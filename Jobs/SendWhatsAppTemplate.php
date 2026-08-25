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
use Modules\MetaWhatsApp\Support\DeliveryFailure;
use Modules\MetaWhatsApp\Support\OutboundGuard;

/**
 * Mirall de SendWhatsAppMessage per al mecanisme de recuperació: envia una
 * plantilla pre-aprovada quan la finestra de servei de 24 h ha expirat.
 * Diferències respecte al job de text pla: (a) llegeix les plantilles
 * configurades del compte i avorta si no n'hi ha cap; (b) crida sendTemplate()
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

    /** @var string|null */
    protected $templateId;

    /** @var string|null */
    protected $templateLanguage;

    /** @var array|null */
    protected $variables;

    /**
     * $templateId/$templateLanguage identifiquen quina plantilla cal enviar.
     * Si es deixen null, s'agafa la primera plantilla configurada
     * del compte (comptes d'una sola plantilla, compatibilitat amb crides
     * existents).
     *
     * $variables distingeix el picker dinàmic (issue #2, punt 2 complet) de
     * les plantilles estàtiques del compte: null (per defecte) = plantilla
     * estàtica, es valida $templateId/$templateLanguage contra
     * $account->findTemplate() abans d'enviar (defensa en profunditat).
     * Array (fins i tot buit) = plantilla dinàmica del catàleg APPROVED en
     * viu de Meta: NO es valida contra la llista estàtica, i els valors
     * s'envien com a paràmetres {{1}}, {{2}}... del cos.
     *
     * Atenció: aquest comentari deia que la plantilla dinàmica arriba "ja
     * validada pel controlador". Era fals. sendDynamicTemplate() només
     * comprova que el nom i l'idioma no siguin cadenes buides; no els
     * contrasta amb res. I ha de ser així, perquè el picker existeix
     * precisament per enviar qualsevol plantilla aprovada al WABA, no
     * només les cinc configurades. La confiança recau en qui té accés a
     * la conversa, no en una validació que no existeix.
     */
    public function __construct(int $accountId, int $threadId, string $toPhone, ?string $templateId = null, ?string $templateLanguage = null, ?array $variables = null)
    {
        $this->accountId        = $accountId;
        $this->threadId         = $threadId;
        $this->toPhone          = $toPhone;
        $this->templateId       = $templateId;
        $this->templateLanguage = $templateLanguage;
        $this->variables        = $variables;
    }

    public function handle()
    {
        $account = OutboundGuard::accountForSending(
            $this->accountId,
            $this->threadId,
            OutboundGuard::SUBJECT_TEMPLATE
        );
        if (!$account) {
            $thread = Thread::find($this->threadId);
            if ($thread) {
                $this->recordFailure($this->accountId, $thread, 'account_inactive');
            }
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

        // Diferència (a): sense plantilla identificable no hi ha res legal
        // a enviar fora de la finestra de 24 h.
        $bodyParams = [];
        if ($this->variables !== null) {
            // Dinàmica: nom/idioma ja validats pel controlador contra el
            // catàleg APPROVED en viu de Meta (vegeu docblock constructor).
            $templateName = $this->templateId;
            $templateLang = $this->templateLanguage;
            $bodyParams   = $this->variables;
        } elseif ($this->templateId && $this->templateLanguage) {
            $template = $account->findTemplate($this->templateId, $this->templateLanguage);
            $templateName = $template['id'] ?? null;
            $templateLang = $template['language'] ?? null;
        } else {
            // Sense plantilla identificada: la primera configurada. Abans
            // aquí es llegia el parell template_name/template_lang, que la
            // #2 va plegar dins les ranures i ja no existeix.
            $first        = $account->getTemplateList()[0] ?? null;
            $templateName = $first['id'] ?? null;
            $templateLang = $first['language'] ?? null;
        }

        if (empty($templateName) || empty($templateLang)) {
            Log::warning('[MetaWhatsApp] Template not configured for the account', [
                'account_id' => $account->id,
                'thread_id'  => $thread->id,
            ]);
            $this->recordFailure($account->id, $thread, 'template-not-configured');
            return;
        }

        $result = $this->makeClient($account)->sendTemplate(
            $this->toPhone,
            $templateName,
            $templateLang,
            $bodyParams
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
                '[MetaWhatsApp] Transient error sending template to Meta: ' . $result['error_message']
            );
        }

        // Semantic errors: retrying does not change the outcome. Shares the
        // handler with the other outbound jobs and the webhook (issue #25),
        // so the three cannot drift apart again.
        DeliveryFailure::record(
            $account,
            DeliveryFailure::SOURCE_SYNC,
            DeliveryFailure::SUBJECT_TEMPLATE,
            $result['error_code'],
            $result['error_message'],
            ['thread_id' => $thread->id]
        );

        if (DeliveryFailure::isInvalidToken($result['error_code'])) {
            $account->is_active = false;
            $account->save();
            Log::error('[MetaWhatsApp] Access token rejected by Meta (190): account deactivated', [
                'account_id' => $account->id,
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
        // firstOrCreate i no create: vegeu el mateix mètode al job de text.
        WhatsAppMessage::firstOrCreate(
            // Els fallits no tenen wamid de Meta: clau sintètica per thread.
            ['wamid' => 'failed-thread-' . $thread->id],
            [
                'account_id'      => $accountId,
                'conversation_id' => $thread->conversation_id,
                'thread_id'       => $thread->id,
                'contact_phone'   => $this->toPhone,
                'direction'       => WhatsAppMessage::DIRECTION_OUTBOUND,
                'status'          => WhatsAppMessage::STATUS_FAILED,
                'error_code'      => substr($errorCode, 0, 20),
            ]
        );
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
