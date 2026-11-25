<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * Issue #53. The module fired `conversation.status_changed` when an inbound
 * message reopened a closed conversation, and had to invent a user for it
 * because the event's own signature carries one that modules dereference
 * (which is issue #7, where passing null broke Workflows).
 *
 * The core does not fire it for anything a customer did, and that is a
 * decision rather than an omission. Measured on 1.8.252:
 *
 *   a customer replying by e-mail   FetchEmails.php:1568 sets the status
 *                                   through the `conversation.status_changing`
 *                                   filter, then :1665 fires
 *                                   `conversation.customer_replied`.
 *                                   No status_changed.
 *   an agent in the interface       ConversationsController.php:1107
 *   an agent replying by e-mail     FetchEmails.php:1763
 *
 * Asking the core to fire it for a customer would be asking it to invent a
 * user for something no user did, which is the defect the reporter opened #53
 * about. So the module stops firing it and does what the core does.
 *
 * The reporter confirmed on 2026-11-24 that nothing of theirs depends on the
 * signal.
 */
class CustomerReopenEventsTest extends TestCase
{
    use DatabaseTransactions;

    protected function runJob(WhatsAppAccount $account, array $payload)
    {
        (new ProcessInboundWebhook($account->id, $payload))->handle();
    }

    /** A closed conversation and the customer writing again. */
    protected function reopenWithSecondMessage(WhatsAppAccount $account): Conversation
    {
        $this->runJob($account, $this->inboundPayload($account, 'wamid.re1', '34611222333', 'Hola'));
        $conversation = Conversation::find(
            WhatsAppMessage::where('wamid', 'wamid.re1')->first()->conversation_id
        );
        $conversation->status = Conversation::STATUS_CLOSED;
        $conversation->save();

        $this->runJob($account, $this->inboundPayload($account, 'wamid.re2', '34611222333', 'Encara hi sou?'));

        return $conversation->fresh();
    }

    public function test_a_customer_reopening_a_conversation_does_not_fire_the_agent_event()
    {
        $account = $this->createTestAccount();

        $fired = 0;
        \Eventy::addAction('conversation.status_changed', function () use (&$fired) {
            $fired++;
        }, 20, 4);

        $this->reopenWithSecondMessage($account);

        $this->assertEquals(
            0,
            $fired,
            'the module still fires an event the core does not fire for a customer action'
        );
    }

    /**
     * The half that must not go with it. This is the event the core does use
     * for a customer, and removing the wrong one must not remove the right
     * one.
     */
    public function test_the_customer_event_the_core_uses_is_still_fired()
    {
        $account = $this->createTestAccount();

        $fired = 0;
        \Eventy::addAction('conversation.customer_replied', function () use (&$fired) {
            $fired++;
        }, 20, 3);

        $this->reopenWithSecondMessage($account);

        $this->assertEquals(1, $fired, 'conversation.customer_replied went with it');
    }

    /** The reopening itself still has to happen. */
    public function test_the_conversation_is_still_reopened()
    {
        $account = $this->createTestAccount();

        $conversation = $this->reopenWithSecondMessage($account);

        $this->assertEquals(Conversation::STATUS_ACTIVE, $conversation->status);
    }
}
