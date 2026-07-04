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

class SendWhatsAppMessage implements ShouldQueue
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
            Log::warning('[MetaWhatsApp] SendWhatsAppMessage: compte inexistent o inactiu', [
                'account_id' => $this->accountId,
                'thread_id'  => $this->threadId,
            ]);
            return;
        }

        // Idempotència autoritativa: un thread només s'envia una vegada.
        if (WhatsAppMessage::where('thread_id', $this->threadId)
            ->where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)
            ->exists()
        ) {
            return;
        }

        // Guard post-undo (H7/A6): estat SEMPRE fresc de BD, mai del model
        // serialitzat. L'undo converteix el thread en draft i no cancel·la
        // el TriggerAction que ens ha portat fins aquí.
        $thread = Thread::find($this->threadId);
        if (!$thread
            || $thread->type != Thread::TYPE_MESSAGE
            || $thread->state != Thread::STATE_PUBLISHED
        ) {
            return;
        }

        $text = trim(\Helper::htmlToText($thread->body));
        if ($text === '') {
            return;
        }

        $result = (new WhatsAppApiClient($account))->sendText($this->toPhone, $text);

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
                '[MetaWhatsApp] Error transitori enviant a Meta: ' . $result['error_message']
            );
        }

        // Errors semàntics: reintentar no canvia el resultat.
        if ($result['error_code'] === '190') {
            // Token invàlid o expirat: desactivar el compte perquè l'admin
            // ho vegi al llistat (○ Inactiu) i no cremar més crides.
            $account->is_active = false;
            $account->save();
            Log::error('[MetaWhatsApp] Token d\'accés rebutjat per Meta (190): compte desactivat', [
                'account_id' => $account->id,
            ]);
        } elseif ($result['error_code'] === '131047') {
            Log::warning('[MetaWhatsApp] Fora de la finestra de 24 h (131047): missatge no lliurat', [
                'account_id' => $account->id,
                'thread_id'  => $thread->id,
            ]);
        } else {
            Log::error('[MetaWhatsApp] Error semàntic de Meta enviant missatge', [
                'account_id' => $account->id,
                'thread_id'  => $thread->id,
                'error_code' => $result['error_code'],
                'error'      => $result['error_message'],
            ]);
        }

        $this->recordFailure($account->id, $thread, (string) $result['error_code']);
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
        Log::error('[MetaWhatsApp] SendWhatsAppMessage failed permanently', [
            'account_id' => $this->accountId,
            'thread_id'  => $this->threadId,
            'error'      => $e->getMessage(),
        ]);

        $exists = WhatsAppMessage::where('thread_id', $this->threadId)
            ->where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)
            ->exists();
        if (!$exists) {
            $thread = Thread::find($this->threadId);
            if ($thread) {
                $this->recordFailure($this->accountId, $thread, 'transient');
            }
        }
    }
}
