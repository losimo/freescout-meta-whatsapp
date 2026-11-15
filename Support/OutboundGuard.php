<?php

namespace Modules\MetaWhatsApp\Support;

use App\Conversation;
use App\CustomerChannel;
use App\Thread;
use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

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
     * Whether we still hold the number this job is about to send to.
     *
     * The three outbound jobs bake the phone into their constructor and
     * are queued with the core's undo delay, so handle() runs later and
     * sometimes much later: a job that lands in failed_jobs can be
     * retried months afterwards. If an administrator answered a
     * right-of-erasure request in between, sending would reach the erased
     * person and write their phone back into our table, falsifying a
     * receipt that had already been handed over.
     *
     * This used to ask about the thread's CONVERSATION instead: whether it
     * still had any route to any customer. The two coincide in normal use,
     * which is why it survived a round of review, but they come apart as
     * soon as the conversation is moved onto a different customer, by the
     * core's "Change customer" or by Customer::mergeWith(). Then the
     * conversation has a perfectly good route, through the new customer's
     * own channel row, while the job is still carrying the erased number
     * and would deliver to it. The job acts on a value, so the guard has
     * to ask about that value.
     *
     * We cannot ask the direct question, "was this number erased?",
     * because answering it would mean keeping a list of erased numbers,
     * and a register of exactly the people who exercised this right is a
     * worse thing to hold than the data it replaces. So the question is
     * the one we can answer honestly from what is still here.
     *
     * The two sources are the same ones handleOutboundReplies() and
     * resolveModuleConversation() read the phone from in the first place,
     * so at the moment a job is queued this is true by construction, and
     * the only thing that makes it false is an erasure.
     */
    public static function stillHoldsPhone(string $toPhone): bool
    {
        if ($toPhone === '') {
            return false;
        }

        if (WhatsAppMessage::where('contact_phone', $toPhone)->exists()) {
            return true;
        }

        // Deliberately not scoped to the conversation or its customer.
        // Erasure is global: it clears a person everywhere at once, so the
        // question has to be global too. Scoping it is what would start
        // refusing legitimate sends.
        return CustomerChannel::where('channel', WhatsAppAccount::CHANNEL)
            ->where('channel_id', $toPhone)
            ->exists();
    }

    /**
     * Checks the contact route and, if it is gone, leaves the erasure
     * refusal note. Returns true when the caller must stop.
     *
     * This exists because `accountForSending()` returning null is itself
     * an early-return branch that every job takes before ever reaching
     * `stillHoldsPhone()`. That branch calls `recordFailure()`, which
     * writes the phone straight back into a `failed-thread-N` row: an
     * inactive account and an erasure can land in the same window (a
     * synchronous 190 deactivates the account, and this module has its
     * own UX for exactly that), so without this check a job could refuse
     * to send for one reason while still falsifying the receipt for the
     * other. The caller must consult this before `recordFailure()`, not
     * after, and skip the recording entirely when it returns true.
     */
    public static function refuseIfContactErased(int $threadId, string $toPhone): bool
    {
        if (self::stillHoldsPhone($toPhone)) {
            return false;
        }

        self::noteRefusal(
            Thread::find($threadId),
            'metawhatsapp::metawhatsapp.not_sent_contact_erased'
        );

        return true;
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

        // L'agent de qui parla la nota: la resposta que s'ha refusat és seva.
        $agentId = $thread->created_by_user_id ?: $thread->user_id;

        $note = new Thread();
        $note->conversation_id = $conversation->id;
        $note->user_id         = $agentId;
        // Issue #43. El core decideix l'avatar i la disposició de la nota amb
        // Thread::getPerson(), que per a notes llegeix created_by_user_cached.
        // Sense aquest camp la nota sortia sense cara i amb el nom a la dreta.
        //
        // Who it names is now the administrator's to choose, because naming
        // the agent made FreeScout render the note as "you" to the very
        // person it was written about. NoteAuthor answers with the chosen
        // user when there is one, and otherwise with this agent, which is
        // the behaviour this module shipped with. user_id stays as it was:
        // there it means who the note is tied to, not who wrote it.
        $note->created_by_user_id = NoteAuthor::forNote($agentId ?: null);
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
