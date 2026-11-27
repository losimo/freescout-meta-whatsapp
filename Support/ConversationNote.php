<?php

namespace Modules\MetaWhatsApp\Support;

use App\Conversation;
use App\Thread;

/**
 * Every note this module leaves on a conversation, in one shape.
 *
 * There were four writers with four shapes, ten fields each, and the fields
 * are not obvious: three of them encode things we learned from reported
 * defects, and a fifth writer would have had to learn them again.
 *
 * - `created_by_user_id` must be there, or the core's print view fails on
 *   the whole conversation (#55, found by MarkoD). It is also what the core
 *   reads to draw the note's face and name, through
 *   `Thread::getPerson()` and `created_by_user_cached` (#43), and who it
 *   names is the administrator's choice through NoteAuthor rather than ours.
 * - `user_id` is the conversation's assignee and not the author: the core
 *   mirrors it onto every thread it writes, in
 *   `Conversation::createUserThread()`, and reads it for the name it prints
 *   on the right. An agent id here showed that agent on every note whatever
 *   the note was about, and on an unassigned conversation null is right.
 * - `status` is the one the core prints beside the note, read at the moment
 *   the note is written. A caller that reopens the conversation afterwards
 *   has to write it again, which is what OutboundGuard does.
 *
 * What is deliberately NOT here is policy. Whether a note reopens a closed
 * conversation is the caller's call and the answers differ: a refused reply
 * needs an agent to come back (#19), a number change does not and reopening
 * for it would put a finished conversation back in the queue.
 *
 * Telling the core a note was added is here, though, because a writer that
 * forgets it writes a note nobody ever hears about, which is issue #64 and
 * was true of all four writers for three major versions. AgentNotifications
 * holds what gets told to whom; this class holds only the one decision each
 * caller has to make, which of its notes needs an agent to come back.
 */
class ConversationNote
{
    /**
     * @param int|null $aboutAgentId the agent the note is about, when it is
     *                               about one; NoteAuthor decides whether
     *                               that is who it ends up naming
     * @param array    $meta         stamped before saving, for a caller that
     *                               de-duplicates on its own
     * @param bool     $notify       whether the subscribed agents are told,
     *                               MarkoD's rule from #64: a note that
     *                               needs somebody to come back notifies, a
     *                               note that does not, does not. Every
     *                               caller passes it, and the default is
     *                               true so that a writer added later and
     *                               forgetting it is noisy rather than
     *                               silent: a notification too many gets
     *                               reported, a missing one is #64 again and
     *                               took three major versions to find.
     */
    public static function write(
        Conversation $conversation,
        string $body,
        ?int $aboutAgentId = null,
        array $meta = [],
        bool $notify = true
    ): Thread {
        $note = new Thread();
        $note->conversation_id    = $conversation->id;
        $note->user_id            = $conversation->user_id;
        $note->created_by_user_id = NoteAuthor::forNote($aboutAgentId ?: null);
        $note->type               = Thread::TYPE_NOTE;
        $note->status             = $conversation->status;
        $note->state              = Thread::STATE_PUBLISHED;
        $note->body               = $body;
        $note->source_via         = Thread::PERSON_USER;
        $note->source_type        = Thread::SOURCE_TYPE_WEB;
        $note->customer_id        = $conversation->customer_id;

        foreach ($meta as $key => $value) {
            $note->setMeta($key, $value);
        }

        $note->save();

        AgentNotifications::noteAdded($conversation, $note, $notify);

        return $note;
    }
}
