<?php

namespace Modules\MetaWhatsApp\Support;

use App\Conversation;
use App\CustomerChannel;
use App\Thread;
use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Support\ConversationReopen;
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
     * Key under threads.meta holding the id of the reply a note is about.
     *
     * Issue #51. The de-duplication used to be "any refusal note in this
     * conversation in the last five minutes", a stand-in for "the three
     * attachments of one reply" that also swallowed the second of two
     * different replies failing minutes apart.
     */
    const META_REFUSED_THREAD = 'mwa_refused_thread';

    /**
     * Key under threads.meta holding which refusal a note reports.
     *
     * The de-duplication is per reply and per reason, which is one rule in
     * place of the three attempts that came before it: a five minute window,
     * then one note per reply, then one note per reply with an override for
     * the worse news. The override wrote a second identical note on the
     * commonest path of all, an inactive channel, because the guard and the
     * job both report it with the same key.
     *
     * Per reply and per reason gives all of it at once: three attachments
     * failing the same way leave one note, a file-only refusal does not
     * silence the one that says the whole reply was lost, and nothing can
     * write the same sentence twice.
     */
    const META_REFUSED_REASON = 'mwa_refused_reason';

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

        // Missing and inactive are not the same thing and the note used
        // to say they were: an agent whose channel had been deleted was
        // told to wait for an administrator to restore the connection,
        // which nobody was going to do. The log line above had the
        // distinction all along.
        $thread = Thread::find($threadId);
        if ($thread) {
            self::noteRefusal(
                $thread,
                $account
                    ? 'metawhatsapp::metawhatsapp.not_sent_channel_inactive'
                    : 'metawhatsapp::metawhatsapp.not_sent_channel_gone'
            );
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
    public static function refuseIfUnreachable(int $accountId, int $threadId, string $toPhone): bool
    {
        // The channel itself may be gone. Deleting a channel, or the
        // mailbox it is attached to, takes its message rows with it
        // (Support\ChannelTeardown), and a job queued before that still
        // carries the number: without this branch it reached
        // recordFailure() and wrote the phone straight back into a new
        // row, under the id of an account that no longer exists.
        if (!WhatsAppAccount::where('id', $accountId)->exists()) {
            self::noteRefusal(
                Thread::find($threadId),
                'metawhatsapp::metawhatsapp.not_sent_channel_gone'
            );

            return true;
        }

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
     * @param array  $replace        values for the key's own placeholders
     */
    public static function noteRefusal(?Thread $thread, string $translationKey, array $replace = []): void
    {
        if (!$thread || !$thread->conversation_id) {
            return;
        }

        $conversation = Conversation::find($thread->conversation_id);
        if (!$conversation) {
            return;
        }

        // One note per reply and per reason: a reply carrying three files
        // dispatches three jobs, and three identical notes would be worse
        // than none, while a note about one file must not silence the one
        // that says the whole reply was lost.
        //
        // Keyed on the reply's own id (issue #51), which replaced a five
        // minute window that also suppressed the second of two different
        // replies failing minutes apart. Two consequences worth stating: a
        // second reply gets its own note, and the suppression of a repeated
        // note no longer expires.
        //
        // Both values go in as strings and are matched with their quotes,
        // because the JSON of "mwa_refused_thread":1 is a prefix of
        // "mwa_refused_thread":123 and thread 1's note would have silenced
        // thread 123's. Laravel 5.5 encodes the cast array with a plain
        // json_encode, no spaces, and threads.meta is a text column, so the
        // stored form is exactly what is matched here.
        $alreadyNoted = Thread::where('conversation_id', $thread->conversation_id)
            ->where('type', Thread::TYPE_NOTE)
            ->where('meta', 'like', '%"' . self::META_REFUSED_THREAD . '":"' . $thread->id . '"%')
            ->where('meta', 'like', '%"' . self::META_REFUSED_REASON . '":"' . $translationKey . '"%')
            ->exists();

        if ($alreadyNoted) {
            self::bringBack($conversation);

            return;
        }

        // L'agent de qui parla la nota: la resposta que s'ha refusat és seva.
        $agentId = $thread->created_by_user_id ?: $thread->user_id;

        $note = new Thread();
        $note->conversation_id = $conversation->id;
        // Not the author and not a choice: the core mirrors the
        // conversation's assignee onto every thread it writes
        // (Conversation::createUserThread sets $thread->user_id =
        // $this->user_id), and FreeScout reads it to decide the name it
        // prints on the right of a note. Putting an agent here showed that
        // agent's name on every note regardless of who the note was about,
        // and on an unassigned conversation the right answer is null.
        $note->user_id         = $conversation->user_id;
        // Issue #43. El core decideix l'avatar i la disposició de la nota amb
        // Thread::getPerson(), que per a notes llegeix created_by_user_cached.
        // Sense aquest camp la nota sortia sense cara i amb el nom a la dreta.
        //
        // Who it names is now the administrator's to choose, because naming
        // the agent made FreeScout render the note as "you" to the very
        // person it was written about. NoteAuthor answers with the chosen
        // user when there is one, and otherwise with this agent, which is
        // the behaviour this module shipped with.
        $note->created_by_user_id = NoteAuthor::forNote($agentId ?: null);
        $note->type            = Thread::TYPE_NOTE;
        $note->status          = $conversation->status;
        $note->state           = Thread::STATE_PUBLISHED;
        $note->body            = self::NOTE_MARKER . ' ' . __($translationKey, $replace);
        $note->source_via      = Thread::PERSON_USER;
        $note->source_type     = Thread::SOURCE_TYPE_WEB;
        $note->customer_id     = $conversation->customer_id;
        $note->setMeta(self::META_REFUSED_THREAD, (string) $thread->id);
        $note->setMeta(self::META_REFUSED_REASON, $translationKey);
        $note->save();

        self::bringBack($conversation);

        // The status the note carries is the one the core prints beside it,
        // and it was read before the reopening, so a note that brought a
        // closed conversation back was labelled closed. Written only when
        // the reopening actually changed something, which is the rare case.
        if ((int) $note->status !== (int) $conversation->status) {
            $note->status = $conversation->status;
            $note->save();
        }
    }

    /**
     * The conversation goes back into view after every refusal, noted or
     * suppressed.
     *
     * Two review rounds shaped this. It used to live in OutboundFailure, so
     * only the job-side refusals reopened and an agent who replied and closed
     * in one action never saw the others. Moving it into the note put it
     * behind the one-note-per-reply guard instead, so a second file failing
     * on a conversation closed in between kept its note and lost its
     * reopening. It now runs on both paths, and after the note rather than
     * before it: writing the trace is the part that must not be lost to a
     * core hook throwing during a status change.
     */
    protected static function bringBack(Conversation $conversation): void
    {
        // Idempotent by design: ConversationReopen returns early on a
        // conversation that is already active, which is the common case.
        ConversationReopen::afterFailure($conversation);
    }
}
