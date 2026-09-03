<?php

namespace Modules\MetaWhatsApp\Support;

use App\Conversation;
use App\Thread;
use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;

/**
 * Single answer to "can this account send right now, and if not, what do
 * we leave behind" (issues #28 and #29).
 *
 * Each outbound job used to carry its own copy of the account check, and
 * each copy returned silently with a warning. Fixing one left the others
 * untouched, which is how the same defect was reported three times over:
 * templates in #28, then everything else in #29. The check lives here so
 * a job cannot drift away from its siblings, exactly as DeliveryFailure
 * did for error handling in #25.
 *
 * Refusing is not silent. The agent has already written a reply and, as
 * far as FreeScout is concerned, sent it: the thread exists and looks
 * delivered. Without a note in the conversation the only trace would be
 * a log line nobody reads at the moment it matters.
 */
class OutboundGuard
{
    const SUBJECT_MESSAGE  = 'message';
    const SUBJECT_MEDIA    = 'media';
    const SUBJECT_TEMPLATE = 'template';

    /** Marker used to keep one note per thread, not one per attachment. */
    const NOTE_MARKER = '[WhatsApp not sent]';

    /**
     * Returns the account when it is able to send, or null after logging
     * the refusal and leaving a visible note on the conversation.
     */
    public static function accountForSending(int $accountId, int $threadId, string $subject): ?WhatsAppAccount
    {
        $account = WhatsAppAccount::find($accountId);

        if ($account && $account->is_active) {
            return $account;
        }

        Log::error('[MetaWhatsApp] Nothing sent: the WhatsApp channel is missing or inactive', [
            'account_id' => $accountId,
            'thread_id'  => $threadId,
            'subject'    => $subject,
        ]);

        $thread = Thread::find($threadId);
        if ($thread) {
            self::noteRefusal($thread, 'metawhatsapp::metawhatsapp.not_sent_channel_inactive');
        }

        return null;
    }

    /**
     * Same note, other reasons.
     *
     * An inactive channel was never the only way for a reply to die quietly.
     * A contact with no phone number on file, or an attachment row that no
     * longer resolves, end the same way: the job returns, the thread sits in
     * FreeScout looking delivered, and nothing on screen says otherwise. The
     * reason differs, what the agent needs to know does not, so the note is
     * written in one place rather than copied per caller. That copying is
     * what produced #28 and #29.
     *
     * @param string $translationKey full key, so the caller states its reason
     */
    public static function noteRefusal(?Thread $thread, string $translationKey): void
    {
        if (!$thread || !$thread->conversation_id) {
            return;
        }

        // One note per thread, not one per reason and not one per attachment:
        // a reply carrying three files dispatches three jobs, and three
        // identical notes would be worse than none.
        $alreadyNoted = Thread::where('conversation_id', $thread->conversation_id)
            ->where('type', Thread::TYPE_NOTE)
            ->where('body', 'like', self::NOTE_MARKER . '%')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($alreadyNoted) {
            return;
        }

        $conversation = Conversation::find($thread->conversation_id);
        if (!$conversation) {
            return;
        }

        $note = new Thread();
        $note->conversation_id = $conversation->id;
        $note->user_id         = $thread->created_by_user_id ?: $thread->user_id;
        $note->type            = Thread::TYPE_NOTE;
        $note->status          = $conversation->status;
        $note->state           = Thread::STATE_PUBLISHED;
        $note->body            = self::NOTE_MARKER . ' ' . __($translationKey);
        $note->source_via      = Thread::PERSON_USER;
        $note->source_type     = Thread::SOURCE_TYPE_WEB;
        $note->customer_id     = $conversation->customer_id;
        $note->save();
    }
}
