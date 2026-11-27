<?php

namespace Modules\MetaWhatsApp\Jobs;

use App\Attachment;
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

class SendWhatsAppMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // El backoff entre reintents el gestiona el worker (Laravel 5.5).
    public $tries = 3;

    /**
     * Meta's documented limit for a media caption, in characters.
     *
     * The caption half of T07. Past it Meta refuses the whole message, which
     * took the agent's words down with the file. The rule itself is applied
     * by the dispatcher, which is the one place that decides whether the text
     * rides the first attachment or travels on its own; it reads this.
     *
     * The other half of that ticket, splitting a text over 4096 characters
     * into several messages, is not in this batch: it would need a
     * discriminator for the second and later pieces, and one text row per
     * thread is what keeps the send idempotent.
     */
    const MAX_CAPTION = 1024;

    // Límits documentats de Meta per tipus (bytes).
    const MAX_SIZES = [
        'image'    => 5 * 1024 * 1024,
        'video'    => 16 * 1024 * 1024,
        'audio'    => 16 * 1024 * 1024,
        'document' => 100 * 1024 * 1024,
    ];

    /** @var int */
    protected $accountId;

    /** @var int */
    protected $threadId;

    /** @var string */
    protected $toPhone;

    /** @var int */
    protected $attachmentId;

    /** @var string|null */
    protected $caption;

    public function __construct(int $accountId, int $threadId, string $toPhone, int $attachmentId, ?string $caption = null)
    {
        $this->accountId    = $accountId;
        $this->threadId     = $threadId;
        $this->toPhone      = $toPhone;
        $this->attachmentId = $attachmentId;
        $this->caption      = $caption;
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
            OutboundGuard::SUBJECT_MEDIA
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
            if (OutboundGuard::refusalLeavesARow($this->accountId, OutboundGuard::SUBJECT_MEDIA)) {
                $thread = Thread::find($this->threadId);
                if ($thread) {
                    $this->recordFailure($this->accountId, $thread, 'account_inactive');
                }
            }
            return;
        }

        // Idempotència per adjunt: un mateix thread pot tenir més d'un
        // missatge de sortida (un per adjunt), a diferència del text pla.
        if (WhatsAppMessage::where('thread_id', $this->threadId)
            ->where('attachment_id', $this->attachmentId)
            ->where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)
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

        $thread = Thread::find($this->threadId);
        if (!$thread
            || $thread->type != Thread::TYPE_MESSAGE
            || $thread->state != Thread::STATE_PUBLISHED
        ) {
            return;
        }

        // The window comes before the attachment is even resolved, which the
        // fourth review round asked for: a vanished attachment also loses the
        // text riding it, so that branch sends the text on its own, and with
        // the window shut that would be a guaranteed 131047. Nothing can be
        // sent at all once the window is closed, so it is the first thing
        // asked. The attachment id is read from the job rather than from the
        // row, which has not been looked up yet.
        if (WhatsAppMessage::windowExpired($thread->conversation_id, $account)) {
            Log::error('[MetaWhatsApp] Outside the 24h window: media not sent', [
                'account_id'    => $account->id,
                'thread_id'     => $thread->id,
                'attachment_id' => $this->attachmentId,
            ]);
            $this->recordFailure($account->id, $thread, 'window_expired');
            return;
        }

        $attachment = Attachment::find($this->attachmentId);
        if (!$attachment || (int) $attachment->thread_id !== $thread->id) {
            Log::error('[MetaWhatsApp] SendWhatsAppMedia: attachment missing or not linked to thread', [
                'account_id'    => $account->id,
                'thread_id'     => $thread->id,
                'attachment_id' => $this->attachmentId,
            ]);
            // El text de la resposta pot haver sortit igualment, però el
            // fitxer no. Sense nota, l'agent creu que el client l'ha rebut.
            OutboundGuard::noteRefusal($thread, 'metawhatsapp::metawhatsapp.not_sent_attachment_missing');
            // The text was riding this file, and the file no longer resolves,
            // so without this the words are lost as surely as on any other
            // failure. The note above already says the text may have gone out
            // on its own, which this is what makes true.
            $this->sendTextSeparately($thread);
            return;
        }

        $category = self::mediaCategory($attachment->mime_type);
        $maxSize  = self::MAX_SIZES[$category];
        if ($attachment->size > $maxSize) {
            Log::error('[MetaWhatsApp] Attachment exceeds WhatsApp size limit for its type', [
                'account_id'    => $account->id,
                'thread_id'     => $thread->id,
                'attachment_id' => $attachment->id,
                'category'      => $category,
                'size'          => $attachment->size,
                'max_size'      => $maxSize,
            ]);
            $textWentOnItsOwn = $this->sendTextSeparately($thread, 'size_exceeded');
            $this->recordFailure($account->id, $thread, 'size_exceeded', $textWentOnItsOwn);
            return;
        }

        $client = $this->apiClient($account);

        $upload = $client->uploadMedia($attachment->getLocalFilePath(), $attachment->mime_type, $attachment->file_name);
        if (!$upload['ok']) {
            if ($upload['transient']) {
                throw new \RuntimeException(
                    '[MetaWhatsApp] Transient error uploading attachment to Meta: ' . $upload['error_message']
                );
            }
            Log::error('[MetaWhatsApp] Meta rejected media upload', [
                'account_id'    => $account->id,
                'thread_id'     => $thread->id,
                'attachment_id' => $attachment->id,
                'error_code'    => $upload['error_code'],
                'error'         => $upload['error_message'],
            ]);
            $textWentOnItsOwn = $this->sendTextSeparately($thread, (string) $upload['error_code']);
            $this->recordFailure($account->id, $thread, (string) $upload['error_code'], $textWentOnItsOwn);
            return;
        }

        $filename = $category === 'document' ? $attachment->file_name : null;
        $result   = $client->sendMedia($this->toPhone, $category, $upload['media_id'], $this->caption, $filename);

        if ($result['ok']) {
            WhatsAppMessage::create([
                'wamid'           => $result['wamid'],
                'account_id'      => $account->id,
                'conversation_id' => $thread->conversation_id,
                'thread_id'       => $thread->id,
                'attachment_id'   => $attachment->id,
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
                '[MetaWhatsApp] Transient error sending attachment to Meta: ' . $result['error_message']
            );
        }

        // Semantic errors: retrying does not change the outcome. Same
        // shared handler as the text job and the webhook (issue #25).
        DeliveryFailure::record(
            $account,
            DeliveryFailure::SOURCE_SYNC,
            DeliveryFailure::SUBJECT_MEDIA,
            $result['error_code'],
            $result['error_message'],
            [
                'thread_id'     => $thread->id,
                'attachment_id' => $attachment->id,
            ]
        );

        // Only a synchronous 190 deactivates, as in the text job.
        if (DeliveryFailure::isInvalidToken($result['error_code'])) {
            $account->is_active = false;
            $account->save();
            Log::error('[MetaWhatsApp] Access token rejected by Meta (190): account deactivated', [
                'account_id' => $account->id,
            ]);
        }

        $textWentOnItsOwn = $this->sendTextSeparately($thread, (string) $result['error_code']);
        $this->recordFailure($account->id, $thread, (string) $result['error_code'], $textWentOnItsOwn);
    }

    /**
     * Sends the reply's text as a message of its own (T06).
     *
     * The agent's words travel as the caption of the first attachment, so a
     * file that does not go out takes them with it. The thread is still in
     * FreeScout looking answered, and the customer has nothing.
     *
     * Mechanically this works because the row this job writes on failure
     * carries attachment_id, and the text job skips a thread only when a row
     * with no attachment_id exists. That same check is what stops the text
     * going twice: the dispatcher already sends it separately when the first
     * attachment is audio, and a second attachment of the same reply failing
     * must not deliver the words again.
     *
     * Never from the expired-window branch, and never when Meta's own answer
     * was 131047. The text job has no window check of its own, so either
     * would be a guaranteed second refusal on top of the one that already
     * happened. The second case is the review's finding and it is not
     * theoretical: windowExpired() measures against
     * template_threshold_minutes, which an administrator can set to anything,
     * so the local check can pass while Meta says the window is shut.
     */
    protected function sendTextSeparately(Thread $thread, ?string $errorCode = null): bool
    {
        if ($this->caption === null || trim($this->caption) === '') {
            return false;
        }

        if (DeliveryFailure::isWindowExpired($errorCode)) {
            return false;
        }

        $alreadyWent = WhatsAppMessage::where('thread_id', $thread->id)
            ->where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)
            ->whereNull('attachment_id')
            ->exists();

        if ($alreadyWent) {
            return true;
        }

        SendWhatsAppMessage::dispatch($this->accountId, $thread->id, $this->toPhone);

        return true;
    }

    /**
     * The exact subtypes WhatsApp accepts for image, video and audio, from
     * Meta's own table in our dated copy of the documentation
     * (docs/referencia/meta-whatsapp-docs-2026-11-22/business-phone-numbers_media.md,
     * 22 November 2026).
     *
     * Note video/3gpp and not video/3gp: .3gp is the file extension and 3gpp
     * is the type. The reported patch had the extension, which would have
     * sent every 3GPP video as a document.
     *
     * audio/ogg is listed by Meta as OPUS codecs only, mono input only. The
     * codec cannot be read from a mime type, so the file is still sent as
     * audio; if it is not OPUS, Meta refuses it and since T04 that refusal
     * leaves a note.
     *
     * Documents are not listed here, and the demotion does not promise the
     * file will arrive. The review was right about that: the upload still
     * carries the attachment's own mime type into Meta's /media endpoint,
     * which validates against its own closed list, so an image/gif is
     * refused there whatever category the message would have used. Meta's
     * document list is exactly eight types (text/plain, application/pdf
     * and the three Office pairs).
     *
     * What the demotion does buy is a message that describes the file
     * honestly when the upload does succeed, and a consistent answer to the
     * caption question below.
     *
     * The module deliberately does not refuse an unlisted subtype itself.
     * Being stricter than Meta is the worse failure of the two: a wasted
     * upload costs bandwidth, while a local refusal would withhold a file
     * WhatsApp might have taken. Meta decides, and since T04 its refusal
     * leaves a note carrying its own reason.
     */
    const ACCEPTED_SUBTYPES = [
        'image' => ['image/jpeg', 'image/png', 'image/webp'],
        'video' => ['video/mp4', 'video/3gpp'],
        'audio' => ['audio/aac', 'audio/amr', 'audio/mpeg', 'audio/mp4', 'audio/ogg'],
    ];

    /**
     * Meta's category (image/video/audio/document) for an agent's attachment.
     *
     * T09. This used to decide on the mime prefix alone, like the core's
     * detectType(), and a docblock said the exact subtypes were left out of
     * the MVP on purpose: whatever Meta refused would be handled as any other
     * semantic error. That was true and it was also silent, which is what
     * this batch is about. A subtype WhatsApp does not accept now goes as a
     * document, so the file arrives rather than being refused.
     *
     * Deciding it here and in one place matters beyond this job: the
     * dispatcher asks the same function whether the first attachment is audio
     * in order to decide whether the reply text can ride as a caption. Meta
     * takes no caption on audio but does on a document, so a demoted file
     * must change that answer too, and it does because both read this.
     *
     * The size check in handle() reads MAX_SIZES with the category this
     * returns, so a demoted file is measured against the document limit of
     * 100 MB rather than the 16 MB of the audio it was going to be sent as.
     * That is right for a file Meta accepts as a document, and for one it
     * does not accept at all it means an upload that will be refused on its
     * type instead of a local refusal that blamed the size. The note the
     * agent reads is Meta's reason rather than our guess, which is the
     * trade this takes knowingly.
     */
    public static function mediaCategory(string $mimeType): string
    {
        // Parameters travel in the header (audio/ogg; codecs=opus is what a
        // browser recording arrives as) and are not part of the type.
        $type   = strtolower(trim(explode(';', $mimeType)[0]));
        $prefix = explode('/', $type)[0] ?? '';

        if (isset(self::ACCEPTED_SUBTYPES[$prefix])
            && in_array($type, self::ACCEPTED_SUBTYPES[$prefix], true)
        ) {
            return $prefix;
        }

        return 'document';
    }

    protected function apiClient(WhatsAppAccount $account): WhatsAppApiClient
    {
        return new WhatsAppApiClient($account);
    }

    /**
     * See the same method in the text job (T04). This job is the reason
     * OutboundFailure takes an attachment: it fails per file, and the
     * synthetic wamid has to stay unique per file.
     *
     * @param bool $justDispatchedText this call has just sent the reply's
     *                                 text as a message of its own
     */
    protected function recordFailure(int $accountId, Thread $thread, string $errorCode, bool $justDispatchedText = false)
    {
        // Whether the reply's text survived this failure, which decides
        // whether the note speaks about one file or about the whole reply.
        //
        // A null caption means the words were never riding this file: the
        // dispatcher sent them separately, or there were none, or this is not
        // the attachment that carries them. A caption means they were, and
        // they went down with it, unless this very call has just sent them on
        // their own. That last clause is the third review round's finding: the
        // flag was read before the dispatch, so the two paths that refuse and
        // then send the text still wrote the whole-reply note, telling the
        // agent nothing had arrived while the text was on its way.
        OutboundFailure::record(
            $accountId,
            $thread,
            $errorCode,
            $this->toPhone,
            $this->attachmentId,
            $this->caption === null || $justDispatchedText
        );
    }

    /**
     * The last resort, and it deliberately does NOT rescue the reply's text.
     *
     * `handle()` does rescue it when Meta refuses the file outright, through
     * sendTextSeparately(), so the words still reach the customer. Here they
     * do not, and the reason is not symmetry:
     *
     * - **There is no error code to read.** This runs when the retries are
     *   exhausted or the worker died, so nothing tells us whether the 24
     *   hour window is still open. sendTextSeparately() refuses on a window
     *   error precisely because the text job has no window check of its own,
     *   and dispatching one from here would be that same guaranteed second
     *   refusal with the check missing instead of failing.
     * - **The time is unknown.** A job that reaches `failed_jobs` can be
     *   retried months later and land here, and the guard right below exists
     *   because an erasure can have happened in between. Sending something
     *   new from a handler that runs after the job was given up on means
     *   acting on state nobody has re-read.
     *
     * What makes that acceptable is the note. With the caption on this
     * attachment and no separate dispatch, recordFailure() writes the
     * whole-reply note rather than the file-only one, so the agent is told
     * that nothing of that reply arrived. The words are lost, and they are
     * reported as lost, which leaves the decision where it belongs.
     *
     * Written down on 25 November 2026 because the branch review found the
     * behaviour deliberate and the reason nowhere: it had been argued in a
     * conversation and never in the code.
     *
     * The body moved into runFailed() when #64 wrapped both entry points of
     * every job, and this is the reasoning for that body: it stays with it.
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

    /**
     * The retries are over, and this writes a note too (#64), through
     * OutboundFailure, which is why failed() wraps it in a flush.
     *
     * What it does and why is the block above failed().
     */
    protected function runFailed(\Throwable $e)
    {
        Log::error('[MetaWhatsApp] SendWhatsAppMedia failed permanently', [
            'account_id'    => $this->accountId,
            'thread_id'     => $this->threadId,
            'attachment_id' => $this->attachmentId,
            'error'         => $e->getMessage(),
        ]);

        $exists = WhatsAppMessage::where('thread_id', $this->threadId)
            ->where('attachment_id', $this->attachmentId)
            ->where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)
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
