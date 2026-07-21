<?php

namespace Modules\MetaWhatsApp\Jobs;

use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

/**
 * Mirall de SendWhatsAppMessage per al mecanisme de recuperació: envia una
 * plantilla pre-aprovada quan la finestra de servei de 24 h ha expirat.
 * Diferències respecte al job de text pla: (a) llegeix template_name/
 * template_lang del compte i avorta si no hi són; (b) crida sendTemplate()
 * en lloc de sendText() — per tant no hi ha branch 131047 (fora de
 * finestra), ja que la plantilla és precisament el mecanisme legal per
 * a aquest cas.
 */
class SendWhatsAppTemplate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // El backoff entre reintents el gestiona el worker (Laravel 5.8).
    public $tries = 3;

    /** @var int */
    protected $accountId;

    /** @var int */
    protected $threadId;

    /** @var string */
    protected $toPhone;

    public function __construct(int $accountId, int $threadId, string $toPhone)
    {
        $this->accountId = $accountId;
        $this->threadId  = $threadId;
        $this->toPhone   = $toPhone;
    }

    public function handle()
    {
        $account = WhatsAppAccount::find($this->accountId);
        if (!$account || !$account->is_active) {
            Log::warning('[MetaWhatsApp] SendWhatsAppTemplate: account missing or inactive', [
                'account_id' => $this->accountId,
                'thread_id'  => $this->threadId,
            ]);
            return;
        }

        // Idempotència autoritativa: un thread només s'envia una vegada.
        if (WhatsAppMessage::where('thread_id', $this->threadId)
            ->where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)
            ->whereNull('attachment_id')
            ->exists()
        ) {
            return;
        }

        // Guard post-undo (H7/A6): estat SEMPRE fresc de BD, mai del model
        // serialitzat.
        $thread = Thread::find($this->threadId);
        if (!$thread
            || $thread->type != Thread::TYPE_MESSAGE
            || $thread->state != Thread::STATE_PUBLISHED
        ) {
            return;
        }

        // Diferència (a): sense plantilla configurada no hi ha res legal
        // a enviar fora de la finestra de 24 h.
        if (empty($account->template_name) || empty($account->template_lang)) {
            Log::warning('[MetaWhatsApp] Template not configured for the account', [
                'account_id' => $account->id,
                'thread_id'  => $thread->id,
            ]);
            $this->recordFailure($account->id, $thread, 'template-not-configured');
            return;
        }

        $result = $this->makeClient($account)->sendTemplate(
            $this->toPhone,
            $account->template_name,
            $account->template_lang
        );

        if ($result['ok']) {
            WhatsAppMessage::create([
                'wamid'           => $result['wamid'],
                'account_id'      => $account->id,
                'conversation_id' => $thread->conversation_id,
                'thread_id'       => $thread->id,
                'contact_phone'   => $this->toPhone,
                'direction'       => WhatsAppMessage::DIRECTION_OUTBOUND,
                'status'          => WhatsAppMessage::STATUS_SENT,
            ]);
            return;
        }

        // Errors transitoris (5xx, xarxa): reintent via $tries, sense fila.
        if ($result['transient']) {
            throw new \RuntimeException(
                '[MetaWhatsApp] Error transitori enviant plantilla a Meta: ' . $result['error_message']
            );
        }

        // Errors semàntics: reintentar no canvia el resultat. Diferència (b):
        // no hi ha branch 131047 aquí (vegeu docblock de la classe).
        if ($result['error_code'] === '190') {
            // Token invàlid o expirat: desactivar el compte perquè l'admin
            // ho vegi al llistat (○ Inactiu) i no cremar més crides.
            $account->is_active = false;
            $account->save();
            Log::error('[MetaWhatsApp] Access token rejected by Meta (190): account deactivated', [
                'account_id' => $account->id,
            ]);
        } else {
            Log::error('[MetaWhatsApp] Meta semantic error sending template', [
                'account_id' => $account->id,
                'thread_id'  => $thread->id,
                'error_code' => $result['error_code'],
                'error'      => $result['error_message'],
            ]);
        }

        $this->recordFailure($account->id, $thread, (string) $result['error_code']);
    }

    /**
     * Seam d'instanciació: permet substituir el transport HTTP a tests
     * (bind al contenidor) sense tocar la resta de la lògica del job.
     */
    protected function makeClient(WhatsAppAccount $account): WhatsAppApiClient
    {
        return app(WhatsAppApiClient::class, ['account' => $account]);
    }

    protected function recordFailure(int $accountId, Thread $thread, string $errorCode)
    {
        WhatsAppMessage::create([
            // Els fallits no tenen wamid de Meta: clau sintètica única per thread.
            'wamid'           => 'failed-thread-' . $thread->id,
            'account_id'      => $accountId,
            'conversation_id' => $thread->conversation_id,
            'thread_id'       => $thread->id,
            'contact_phone'   => $this->toPhone,
            'direction'       => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status'          => WhatsAppMessage::STATUS_FAILED,
            'error_code'      => substr($errorCode, 0, 20),
        ]);
    }

    public function failed(\Throwable $e)
    {
        Log::error('[MetaWhatsApp] SendWhatsAppTemplate failed permanently', [
            'account_id' => $this->accountId,
            'thread_id'  => $this->threadId,
            'error'      => $e->getMessage(),
        ]);

        $exists = WhatsAppMessage::where('thread_id', $this->threadId)
            ->where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)
            ->whereNull('attachment_id')
            ->exists();
        if (!$exists) {
            $thread = Thread::find($this->threadId);
            if ($thread) {
                $this->recordFailure($this->accountId, $thread, 'transient');
            }
        }
    }
}
