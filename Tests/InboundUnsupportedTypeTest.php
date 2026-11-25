<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * T39. A message of a type the module cannot render was written to the log and
 * dropped, so the customer had written and the agent saw nothing at all.
 *
 * The consequence the ticket does not mention is the window clock. Both clocks
 * read inbound rows (Models/WhatsAppMessage.php:194), and a dropped message
 * leaves no row, so Meta's 24 hours were running while the module told the
 * agent the window was closed and offered them a paid template instead of a
 * free reply.
 *
 * Two tiers, and the distinction is the point. A message the person sent and
 * we cannot draw reaches the conversation. An announcement *about* a message
 * (revoke, edit) does not, because saying "the customer sent something we
 * cannot show" when what they did was delete it would be worse than silence,
 * and a row for it would hand them 24 hours they never earned.
 */
class InboundUnsupportedTypeTest extends TestCase
{
    use DatabaseTransactions;

    protected function runJob(WhatsAppAccount $account, array $payload)
    {
        (new ProcessInboundWebhook($account->id, $payload))->handle();
    }

    protected function typedPayload(WhatsAppAccount $account, string $wamid, string $type, array $body): array
    {
        $payload = $this->inboundPayload($account, $wamid, '34611222333', 'irrelevant');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['type'] = $type;
        $payload['entry'][0]['changes'][0]['value']['messages'][0][$type]  = $body;
        unset($payload['entry'][0]['changes'][0]['value']['messages'][0]['text']);

        return $payload;
    }

    /**
     * Meta's own `unsupported` type, which is what arrives when a WhatsApp
     * user sends something the Cloud API itself does not carry. It names the
     * original kind, and that name is worth showing: it is what lets an agent
     * say "you sent a poll" instead of "something went wrong".
     */
    public function test_a_message_meta_calls_unsupported_reaches_the_conversation()
    {
        $account = $this->createTestAccount();

        // poll_update and not 'poll': the corpus lists the values of
        // unsupported.type, and a fixture written from that list rather than
        // from memory is what would have shown that `edit` is among them.
        $this->runJob($account, $this->typedPayload($account, 'wamid.uns1', 'unsupported', [
            'type' => 'poll_update',
        ]));

        $record = WhatsAppMessage::where('wamid', 'wamid.uns1')->first();
        $this->assertNotNull($record, 'the message left no row at all');
        $this->assertNotNull($record->conversation_id, 'the message reached no conversation');

        $body = Thread::find($record->thread_id)->body;
        $this->assertStringContainsString('Unsupported message', $body);
        $this->assertStringContainsString('poll_update', $body, 'the kind Meta named is not in the entry');

        // A placeholder and not a sentence about the customer, because this
        // is a customer thread: it renders where their own words would, so
        // "the customer sent ..." read as the customer saying it.
        $this->assertStringNotContainsString('The customer', $body);
    }

    /**
     * The costly half. Nothing to do with drawing the message: the module was
     * telling the agent the free window had closed while it was open.
     */
    public function test_the_window_clock_counts_a_message_we_cannot_render()
    {
        $account = $this->createTestAccount();

        $this->runJob($account, $this->typedPayload($account, 'wamid.uns2', 'order', [
            'catalog_id'     => 'cat-1',
            'product_items'  => [],
        ]));

        $record = WhatsAppMessage::where('wamid', 'wamid.uns2')->first();
        $this->assertNotNull($record, 'an order message left no row');

        $this->assertFalse(
            WhatsAppMessage::windowExpired($record->conversation_id, $account),
            'the customer has just written, so the free window is open'
        );
    }

    /**
     * A type nobody has written code for, which is the case that will keep
     * arriving: Meta adds types and this module finds out afterwards.
     */
    public function test_a_type_this_module_has_never_heard_of_still_reaches_the_agent()
    {
        $account = $this->createTestAccount();

        $this->runJob($account, $this->typedPayload($account, 'wamid.uns3', 'something_from_2027', [
            'whatever' => 'shape',
        ]));

        $record = WhatsAppMessage::where('wamid', 'wamid.uns3')->first();
        $this->assertNotNull($record);
        $this->assertStringContainsString(
            'Unsupported message',
            Thread::find($record->thread_id)->body
        );
    }

    /**
     * `request_welcome`, which is a third thing again and was found in review.
     *
     * Meta sends it when somebody opens a chat with the business and no
     * thread exists yet, typically from an ad that clicks to WhatsApp. Nobody
     * wrote anything, so "the customer sent a message this module cannot
     * show" is the wrong sentence. But the row is right: the event opens the
     * customer service window, which is what makes the whole welcome message
     * feature possible, so leaving it out would tell the agent the window is
     * closed when a free-form reply would be accepted.
     *
     * Not in Meta's dated corpus of 2026-11-22. Confirmed on 2026-11-24 from
     * Meta's conversational components page and 360dialog's documentation,
     * which is also where the window behaviour is stated.
     */
    public function test_someone_opening_a_chat_is_not_described_as_sending_a_message()
    {
        $account = $this->createTestAccount();

        $payload = $this->inboundPayload($account, 'wamid.wel1', '34611222333', 'irrelevant');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['type'] = 'request_welcome';
        unset($payload['entry'][0]['changes'][0]['value']['messages'][0]['text']);

        $this->runJob($account, $payload);

        $record = WhatsAppMessage::where('wamid', 'wamid.wel1')->first();
        $this->assertNotNull($record, 'the event left no row, so the window clock cannot see it');

        $body = Thread::find($record->thread_id)->body;
        $this->assertStringNotContainsString(
            'Unsupported message',
            $body,
            'nobody sent a message, so this must not read as one that could not be shown'
        );
        $this->assertStringContainsString('Chat opened', $body);
        $this->assertStringNotContainsString('The customer', $body);

        $this->assertFalse(
            WhatsAppMessage::windowExpired($record->conversation_id, $account),
            'the event opens the window, which is what lets the agent greet them'
        );
    }

    /**
     * The second tier. `revoke` says a message was deleted, so it is not a
     * message to show and not a reason to reopen the window.
     */
    public function test_an_announcement_about_a_message_leaves_nothing_behind()
    {
        $account = $this->createTestAccount();

        // A conversation already exists, so "nothing behind" is not just the
        // absence of a conversation nobody created.
        $this->runJob($account, $this->inboundPayload($account, 'wamid.before', '34611222333', 'Hola'));
        $conversationId = WhatsAppMessage::where('wamid', 'wamid.before')->first()->conversation_id;
        $threadsBefore  = Thread::where('conversation_id', $conversationId)->count();

        $this->runJob($account, $this->typedPayload($account, 'wamid.rev1', 'revoke', [
            'original_message_id' => 'wamid.before',
        ]));

        $this->assertNull(
            WhatsAppMessage::where('wamid', 'wamid.rev1')->first(),
            'an announcement must not be stored as an inbound message'
        );
        $this->assertEquals(
            $threadsBefore,
            Thread::where('conversation_id', $conversationId)->count(),
            'an announcement must not write anything into the conversation'
        );
    }

    /**
     * Found by an outside review, and the defect the two-tier rule was
     * missing: Meta wraps some announcements in its `unsupported` envelope,
     * so the top-level type is `unsupported` and the real kind is inside.
     * The corpus of 2026-11-22 lists `edit`, `pin` and `keep_in_chat` among
     * the values of `unsupported.type`.
     *
     * Reaching tier 1 meant an edit handed the customer 24 hours of free
     * window they had not earned, reopened a closed conversation, and told
     * the agent a message had arrived that could not be shown.
     */
    public function test_an_announcement_wrapped_as_unsupported_leaves_nothing_behind()
    {
        foreach (['edit', 'pin', 'keep_in_chat'] as $i => $kind) {
            $account = $this->createTestAccount();
            $this->runJob($account, $this->inboundPayload($account, 'wamid.wrapbefore' . $i, '34611222333', 'Hola'));
            $conversationId = WhatsAppMessage::where('wamid', 'wamid.wrapbefore' . $i)->first()->conversation_id;
            $threadsBefore  = Thread::where('conversation_id', $conversationId)->count();

            $this->runJob($account, $this->typedPayload($account, 'wamid.wrap' . $i, 'unsupported', [
                'type' => $kind,
            ]));

            $this->assertNull(
                WhatsAppMessage::where('wamid', 'wamid.wrap' . $i)->first(),
                $kind . ': an announcement was stored as an inbound message'
            );
            $this->assertEquals(
                $threadsBefore,
                Thread::where('conversation_id', $conversationId)->count(),
                $kind . ': an announcement wrote into the conversation'
            );
        }
    }

    /**
     * Error 131060 is a customer writing, and this case asserted the
     * opposite until the whole-branch review of the 3.0.0 branch.
     *
     * The reading it rested on was mine and is nowhere in the corpus: that
     * the webhook fires on the owner of a number when somebody else writes
     * to a number already in use with the API. What the corpus says, in two
     * places, is that it is a WhatsApp user messaging a business whose
     * number is also in the WhatsApp Business app, "for the first time", or
     * writing from an unsupported companion device, and that Meta's own
     * instruction is to "check the WhatsApp Business app for the message"
     * (`webhooks_reference_messages_unsupported` and
     * `embedded-signup_onboarding-business-app-users`, corpus of
     * 2026-11-22). So discarding it threw away somebody's first message,
     * which is the exact loss this ticket was opened for.
     */
    public function test_a_message_whatsapp_could_not_hand_over_still_reaches_the_agent()
    {
        $account = $this->createTestAccount();

        $payload = $this->typedPayload($account, 'wamid.unavail', 'unsupported', ['type' => 'image']);
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['errors'] = [[
            'code'  => 131060,
            'title' => 'This message is currently unavailable.',
        ]];

        $this->runJob($account, $payload);

        $record = WhatsAppMessage::where('wamid', 'wamid.unavail')->first();
        $this->assertNotNull($record, "somebody's first message was discarded");
        $this->assertNotNull($record->conversation_id, 'the message reached no conversation');
        $this->assertNotNull(
            $record->meta_sent_at,
            'no row for the window clock, so the module would offer a paid template with the free window open'
        );

        $body = Thread::find($record->thread_id)->body;
        $this->assertStringContainsString('131060', $body, 'the agent is not told which refusal this is');
        $this->assertStringContainsString(
            'WhatsApp Business',
            $body,
            "Meta's own advice for this error, which is where the message can be read, is not passed on"
        );
        $this->assertStringNotContainsString(
            'image',
            $body,
            'the kind in unsupported.type was printed as what the customer sent, which this payload does not say'
        );
    }

    /**
     * `edit` as a type of its own, which only `revoke` had a test for.
     */
    public function test_an_edit_announcement_leaves_nothing_behind()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.editbefore', '34611222333', 'Hola'));
        $conversationId = WhatsAppMessage::where('wamid', 'wamid.editbefore')->first()->conversation_id;
        $threadsBefore  = Thread::where('conversation_id', $conversationId)->count();

        $this->runJob($account, $this->typedPayload($account, 'wamid.edit1', 'edit', [
            'original_message_id' => 'wamid.editbefore',
            'message'             => ['type' => 'text', 'text' => ['body' => 'corregit']],
        ]));

        $this->assertNull(WhatsAppMessage::where('wamid', 'wamid.edit1')->first());
        $this->assertEquals(
            $threadsBefore,
            Thread::where('conversation_id', $conversationId)->count()
        );
    }

    /**
     * The log line stays, which is what issue #8 asked for, but it drops from
     * error to warning: the message is no longer invisible, so it is no
     * longer an error to find in the log. The sender's phone number comes out
     * of the context at the same time, because the log is one of the places
     * erasure cannot reach.
     */
    public function test_the_log_still_names_the_type_and_no_longer_the_sender()
    {
        Log::spy();
        $account = $this->createTestAccount();

        $this->runJob($account, $this->typedPayload($account, 'wamid.uns4', 'interactive', [
            'type' => 'list_reply',
        ]));

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
            return strpos($message, '[MetaWhatsApp]') === 0
                && stripos($message, 'cannot render') !== false
                && ($context['type'] ?? null) === 'interactive'
                && !array_key_exists('from', $context);
        })->once();
    }
}
