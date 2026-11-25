<?php

namespace Modules\MetaWhatsApp\Support;

use App\Thread;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * What a send that failed leaves behind (T04).
 *
 * The module already noted the refusals it decides itself: an inactive
 * channel, an erased contact, a contact with no phone number, an attachment
 * that no longer resolves. Every refusal that came back from Meta, or from
 * the module's own limits, wrote a row and a log line and nothing else. The
 * agent had written a reply and pressed send, FreeScout showed the thread the
 * way it shows a delivered one, and the only trace was a log line on the
 * server.
 *
 * The three outbound jobs each carried their own copy of the row writing,
 * differing only in the synthetic wamid and the attachment. That is the
 * shape that produced #28 and #29, so the copy lives here now and the jobs
 * pass what is theirs. A guard in Tests/Internal/RegressionGuardsTest.php
 * fails if a job writes one of these rows again on its own.
 *
 * The note goes through OutboundGuard::noteRefusal() and not through a copy
 * of it: that is where the marker and the one-note-per-reply rule live, and
 * a reply carrying three files would otherwise leave three identical notes.
 * The reporter's own patch wrote the note directly and asked for a single
 * note in the same breath.
 */
class OutboundFailure
{
    /**
     * Records a failed send: the row, then the note.
     *
     * Reopening the conversation goes with the note, inside
     * OutboundGuard::noteRefusal(), and after it is written: the note is the
     * only part the agent can see, so a core hook misbehaving during the
     * status change must not be what costs the trace.
     *
     * @param int|null $attachmentId       set by the media job, which fails per file
     * @param bool     $textWentSeparately the reply's text travelled as its
     *                                     own message, so this failure costs
     *                                     the file and not the whole reply
     */
    public static function record(
        int $accountId,
        Thread $thread,
        string $errorCode,
        ?string $toPhone,
        ?int $attachmentId = null,
        bool $textWentSeparately = false
    ): void {
        // firstOrCreate and not create: the synthetic wamid is UNIQUE and
        // this can be reached twice for the same send, for instance on a
        // worker retry. With create the second one died on a duplicate key.
        $values = [
            'account_id'      => $accountId,
            'conversation_id' => $thread->conversation_id,
            'thread_id'       => $thread->id,
            'contact_phone'   => $toPhone,
            'direction'       => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status'          => WhatsAppMessage::STATUS_FAILED,
            'error_code'      => substr($errorCode, 0, 20),
        ];

        if ($attachmentId) {
            $values['attachment_id'] = $attachmentId;
        }

        WhatsAppMessage::firstOrCreate(['wamid' => self::syntheticWamid($thread, $attachmentId)], $values);

        // The note also reopens the conversation, inside noteRefusal(): an
        // agent who replies and closes in one action is already elsewhere,
        // and a note in a closed conversation sits where nobody looks (#19).
        // It used to be done here, which left every refusal noted from
        // outside this class without it.
        $aboutOneFile = $attachmentId !== null && $textWentSeparately;

        [$key, $replace] = self::noteFor($errorCode, $aboutOneFile);

        // Nothing here has to ask whether an earlier note should be
        // overridden: noteRefusal() de-duplicates per reply and per reason,
        // so a note about one file does not silence the one that says the
        // whole reply was lost, and neither can write the same sentence
        // twice. The override that used to be passed from here did exactly
        // that on the commonest path of all, an inactive channel, where the
        // guard and the job report the same reason.
        OutboundGuard::noteRefusal($thread, 'metawhatsapp::metawhatsapp.' . $key, $replace);
    }

    /** Failed sends have no wamid from Meta, so the key is ours. */
    protected static function syntheticWamid(Thread $thread, ?int $attachmentId): string
    {
        return $attachmentId
            ? 'failed-thread-' . $thread->id . '-att-' . $attachmentId
            : 'failed-thread-' . $thread->id;
    }

    /**
     * The note's wording for an error code, and what to fill into it.
     *
     * Deliberately not the async_delivery_failed key the reporter's patch
     * reused: its text says "after it was initially accepted for sending",
     * which for a refusal that happened on the spot is simply false.
     *
     * The second argument says whether what was lost is one file or the whole
     * reply, and it is not the same question as "did a file fail". A reply
     * whose text rides the first attachment loses everything when that
     * attachment does, so there the honest note is still "nothing arrived".
     * The file wording is for when the text travelled as its own message,
     * which the caller knows and this class cannot.
     *
     * The first attempt at this derived the flag from the attachment alone,
     * and the review caught it promising the text "may have gone out on its
     * own" in precisely the case where it had not. The test written with it
     * encoded the same mistake.
     *
     * @param bool $aboutOneFile only a file was lost, the text went on its own
     *
     * @return array [translation key without namespace, replacements]
     */
    protected static function noteFor(string $errorCode, bool $aboutOneFile = false): array
    {
        switch ($errorCode) {
            case 'account_inactive':
                return ['not_sent_channel_inactive', []];

            case 'window_expired':
            case DeliveryFailure::CODE_WINDOW_EXPIRED:
                return [$aboutOneFile ? 'not_sent_file_window_expired' : 'not_sent_window_expired', []];

            case 'size_exceeded':
                return ['not_sent_too_large', []];

            case 'template-not-configured':
                return ['not_sent_template_not_configured', []];

            case 'transient':
                return [$aboutOneFile ? 'not_sent_file_retries_exhausted' : 'not_sent_retries_exhausted', []];
        }

        // Meta can answer that it refused without saying why, so the note
        // must not promise a code it does not have.
        if ($errorCode === '') {
            return [$aboutOneFile ? 'not_sent_file_meta_refused' : 'not_sent_meta_refused', []];
        }

        return [
            $aboutOneFile ? 'not_sent_file_meta_error' : 'not_sent_meta_error',
            ['error' => $errorCode],
        ];
    }
}
