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
use Modules\MetaWhatsApp\Support\AgentNotifications;
use Modules\MetaWhatsApp\Support\DeliveryFailure;
use Modules\MetaWhatsApp\Support\OutboundFailure;
use Modules\MetaWhatsApp\Support\OutboundGuard;
use Modules\MetaWhatsApp\Support\ReadReceipt;

class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // El backoff entre reintents el gestiona el worker (Laravel 5.5).
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

    /**
     * Issue #64. The whole job runs inside AgentNotifications::around(),
     * which flushes the subscription buffer once the run is over so that a
     * note this job writes actually reaches the agents subscribed to notes.
     *
     * Around the job and not on the last line of run(): the body returns
     * early on several paths that have just written a note, and failed()
     * writes one too. What exactly is flushed, what is put back when the run
     * throws, and why that is not a finally, are all in that class and in
     * one copy, which is the difference between this and #18, #25, #28, #29.
     */
    public function handle()
    {
        return AgentNotifications::around(
            function () {
                return $this->run();
            },
            $this->notificationContext()
        );
    }

    /** The job itself. Everything it writes is flushed by handle(). */
    protected function run()
    {
        $account = OutboundGuard::accountForSending(
            $this->accountId,
            $this->threadId,
            OutboundGuard::SUBJECT_MESSAGE
        );
        if (!$account) {
            // An inactive account and an erasure can land in the same
            // window (a synchronous 190 deactivates the account). Without
            // this check, recordFailure() below would write the erased
            // phone straight back into a failed-thread-N row.
            if (OutboundGuard::refuseIfUnreachable($this->accountId, $this->threadId, $this->toPhone)) {
                return;
            }
            // And no row when the channel is only switched off, which is
            // OutboundGuard::refusalLeavesARow(): a row would be what stops
            // this reply going out when the channel is reconnected.
            if (OutboundGuard::refusalLeavesARow($this->accountId, OutboundGuard::SUBJECT_MESSAGE)) {
                $thread = Thread::find($this->threadId);
                if ($thread) {
                    $this->recordFailure($this->accountId, $thread, 'account_inactive');
                }
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

        // A queued or retried job must not resend to someone whose
        // identity was erased while it waited. See
        // OutboundGuard::stillHoldsPhone().
        //
        // Deliberately after the idempotency check: a retry of a message
        // that already went out must stop there, not here, or we would
        // write a "nothing was sent" note about a message the customer
        // actually received.
        if (!OutboundGuard::stillHoldsPhone($this->toPhone)) {
            OutboundGuard::noteRefusal(
                Thread::find($this->threadId),
                'metawhatsapp::metawhatsapp.not_sent_contact_erased'
            );
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
                'category'        => WhatsAppMessage::CATEGORY_SERVICE,
                'status'          => WhatsAppMessage::STATUS_SENT,
            ]);
            ReadReceipt::markLastInbound($this->apiClient($account), $thread->conversation_id);
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
     * The row, the note and the reopening all live in OutboundFailure, which
     * is shared with the other two outbound jobs (T04). This method stays as
     * the seam each job needs, because what identifies a failed send differs:
     * the media job fails per attachment.
     */
    protected function recordFailure(int $accountId, Thread $thread, string $errorCode)
    {
        OutboundFailure::record($accountId, $thread, $errorCode, $this->toPhone);
    }

    /**
     * The retries are over, and this writes a note too (#64), through
     * OutboundFailure, so it needs the same flush as handle().
     */
    public function failed(\Throwable $e)
    {
        AgentNotifications::around(
            function () use ($e) {
                $this->runFailed($e);
            },
            $this->notificationContext()
        );
    }

    protected function runFailed(\Throwable $e)
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
            // Same reason as the !$account branch above: a job that
            // exhausted its retries can still land here after an erasure,
            // and recordFailure() would write the phone straight back.
            if (OutboundGuard::refuseIfUnreachable($this->accountId, $this->threadId, $this->toPhone)) {
                return;
            }
            $thread = Thread::find($this->threadId);
            if ($thread) {
                $this->recordFailure($this->accountId, $thread, 'transient');
            }
        }
    }

    /** What the discarded-notifications log line carries for this job. */
    protected function notificationContext(): array
    {
        return [
            'job'        => static::class,
            'account_id' => $this->accountId,
            'thread_id'  => $this->threadId,
        ];
    }
}
