<?php

namespace Modules\MetaWhatsApp\Support;

use App\Conversation;

/**
 * When a failed delivery brings a conversation back into view (issue #19).
 *
 * The note the module writes is often the only trace of a reply that never
 * left, and it arrives when the agent has already moved on. On a closed
 * conversation that note would sit where nobody looks, so the conversation
 * goes back to active.
 *
 * Extracted from ProcessInboundWebhook, where it was protected and therefore
 * unreachable from the outbound jobs (issue T04). Copying it into them is the
 * pattern that produced #28 and #29, where each outbound job carried its own
 * copy of the account check and fixing one left the others untouched. A guard
 * in Tests/Internal/RegressionGuardsTest.php fails if a job grows its own
 * copy again.
 */
class ConversationReopen
{
    /**
     * Brings a conversation back to active after a delivery failure.
     *
     * Uses the core's setStatus(), which also moves the conversation to the
     * right folder; writing ->status directly would leave the sidebar
     * counters out of step. The assigned agent is not touched: reactivating
     * puts the conversation back in view, and reassigning it would be
     * deciding for the team.
     */
    public static function afterFailure(Conversation $conversation): void
    {
        // A conversation marked as spam or deleted must not be resurrected by
        // a delivery failure, and one that is already active has nothing to
        // do.
        //
        // Through CoreCompat rather than isActive()/isSpam(): since 1.8.234
        // another module can add statuses by hook, and the core's hasStatus()
        // resolves them to the standard status they imitate. With isActive()
        // a custom status behaving as active would not match and we would
        // reopen a conversation that was already open.
        if (CoreCompat::conversationHasStatus($conversation, [
            Conversation::STATUS_ACTIVE,
            Conversation::STATUS_SPAM,
        ])) {
            return;
        }

        if ((int) $conversation->state === Conversation::STATE_DELETED) {
            return;
        }

        $conversation->setStatus(Conversation::STATUS_ACTIVE);
        $conversation->save();
    }
}
