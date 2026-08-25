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
        $account = OutboundGuard::accountForSending(
            $this->accountId,
            $this->threadId,
            OutboundGuard::SUBJECT_MESSAGE
        );
        if (!$account) {
            $thread = Thread::find($this->threadId);
            if ($thread) {
                $this->recordFailure($this->accountId, $thread, 'account_inactive');
            }
            return;
        }

        // Idempotència autoritativa: un thread només envia UN missatge de
        // text (a diferència de multimèdia, que en pot tenir un per adjunt);
        // per això només compten les files sense attachment_id.
        if (WhatsAppMessage::where('thread_id', $this->threadId)
            ->where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)
            ->whereNull('attachment_id')
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

        $result = $this->apiClient($account)->sendText($this->toPhone, $text);

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
            $this->markLastInboundAsRead($account, $thread);
            return;
        }

        // Errors transitoris (5xx, xarxa): reintent via $tries, sense fila.
        if ($result['transient']) {
            throw new \RuntimeException(
                '[MetaWhatsApp] Transient error sending message to Meta: ' . $result['error_message']
            );
        }

        // Semantic errors: retrying does not change the outcome. Logging
        // and error-code semantics live in DeliveryFailure so this path
        // cannot drift from the asynchronous one (issue #25).
        DeliveryFailure::record(
            $account,
            DeliveryFailure::SOURCE_SYNC,
            DeliveryFailure::SUBJECT_MESSAGE,
            $result['error_code'],
            $result['error_message'],
            ['thread_id' => $thread->id]
        );

        // Only a synchronous 190 deactivates: the call itself was rejected,
        // which is unambiguous. The asynchronous one is left alone.
        if (DeliveryFailure::isInvalidToken($result['error_code'])) {
            $account->is_active = false;
            $account->save();
            Log::error('[MetaWhatsApp] Access token rejected by Meta (190): account deactivated', [
                'account_id' => $account->id,
            ]);
        }

        $this->recordFailure($account->id, $thread, (string) $result['error_code']);
    }

    protected function apiClient(WhatsAppAccount $account): WhatsAppApiClient
    {
        return new WhatsAppApiClient($account);
    }

    /**
     * Marca com a llegit l'últim missatge entrant de la conversa (issue
     * #23). Si no n'hi ha cap (p.ex. l'agent inicia la conversa, o el
     * missatge entrant s'ha esborrat), simplement no fa res, tal com
     * demanava el reporter.
     */
    protected function markLastInboundAsRead(WhatsAppAccount $account, Thread $thread)
    {
        $lastInbound = WhatsAppMessage::where('conversation_id', $thread->conversation_id)
            ->where('direction', WhatsAppMessage::DIRECTION_INBOUND)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($lastInbound && $lastInbound->wamid) {
            $this->apiClient($account)->markAsRead($lastInbound->wamid);
        }
    }

    protected function recordFailure(int $accountId, Thread $thread, string $errorCode)
    {
        // firstOrCreate i no create: el wamid sintètic té UNIQUE i aquest
        // mètode es pot cridar dues vegades per al mateix enviament, per
        // exemple en un reintent del worker. Amb create, la segona petava
        // per clau duplicada.
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
        Log::error('[MetaWhatsApp] SendWhatsAppMessage failed permanently', [
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
