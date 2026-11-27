<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Modules\MetaWhatsApp\Jobs\SendWhatsAppMessage;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

/**
 * Which inbound message is the last one, and who gets to decide.
 *
 * Three places used to answer that question their own way: the window clock
 * by the time Meta sent (T10), the read receipt by `created_at`, and the
 * reply's recipient by `id`. All three are right most of the time, because
 * messages usually arrive in the order they were sent.
 *
 * They part company when Meta retries. A delivery it could not make is
 * retried with decreasing frequency for up to seven days, so a message that
 * failed arrives after one that went through, and the row this module writes
 * last is then not the message the customer sent last. The read receipt
 * ticked a message the customer had already seen answered, and on a
 * conversation that holds two numbers the reply went to whichever row the
 * worker happened to write last.
 *
 * Found by the whole-branch review of v3.0.0 and deferred to 3.0.1 from
 * there. Everything now reads WhatsAppMessage::lastInbound().
 */
class LastInboundRuleTest extends TestCase
{
    use DatabaseTransactions;

    /** The number the customer really wrote from last. */
    const WROTE_LAST = '+34611000021';

    /** And the one whose message Meta delivered to us last, having sent it first. */
    const WROTE_FIRST = '+34622000022';

    /**
     * A conversation whose two inbound rows disagree with Meta about which
     * message came last: the row written later holds the older time.
     *
     * The two numbers on one customer are the #59 case. A conversation
     * written before v3.0.0 walked its candidates can hold messages from
     * both, and that is where the recipient matters: picking the wrong row
     * answers somebody who is not the person the agent was reading.
     */
    protected function crossedConversation(WhatsAppAccount $account): Thread
    {
        $customer = new Customer();
        $customer->first_name = 'Two numbers';
        $customer->save();
        $customer->addChannel(WhatsAppAccount::CHANNEL, self::WROTE_LAST);
        \App\CustomerChannel::create($customer->id, WhatsAppAccount::CHANNEL, self::WROTE_FIRST);

        $conversation = new Conversation();
        $conversation->type           = Conversation::TYPE_CHAT;
        $conversation->state          = Conversation::STATE_PUBLISHED;
        $conversation->subject        = 'PHPUnit';
        $conversation->mailbox_id     = $account->mailbox_id;
        $conversation->customer_id    = $customer->id;
        $conversation->customer_email = '';
        $conversation->status         = Conversation::STATUS_ACTIVE;
        $conversation->source_via     = Conversation::PERSON_CUSTOMER;
        $conversation->source_type    = Conversation::SOURCE_TYPE_API;
        $conversation->preview        = 'x';
        $conversation->save();

        // Written first, so it holds the lower id, and sent last.
        $this->inbound($account, $conversation->id, 'wamid.sent-last', self::WROTE_LAST, [
            'created_at'   => now()->subMinutes(10),
            'meta_sent_at' => now()->subMinutes(2),
        ]);

        // Written last, so it holds the higher id, and sent first: this is
        // the row both of the old rules picked.
        $this->inbound($account, $conversation->id, 'wamid.sent-first', self::WROTE_FIRST, [
            'created_at'   => now()->subMinute(),
            'meta_sent_at' => now()->subMinutes(20),
        ]);

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->user_id            = 1;
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->status             = $conversation->status;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = '<p>the agent answers</p>';
        $thread->source_via         = Thread::PERSON_USER;
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id        = $customer->id;
        $thread->created_by_user_id = 1;
        $thread->save();

        return $thread;
    }

    protected function inbound(WhatsAppAccount $account, int $conversationId, string $wamid, string $phone, array $times): WhatsAppMessage
    {
        $row = WhatsAppMessage::create([
            'wamid'           => $wamid,
            'account_id'      => $account->id,
            'conversation_id' => $conversationId,
            'contact_phone'   => $phone,
            'direction'       => WhatsAppMessage::DIRECTION_INBOUND,
            'status'          => WhatsAppMessage::STATUS_RECEIVED,
        ]);

        // Through the query builder: created_at is managed by the model and
        // assigning it on a fresh instance would be overwritten on save.
        WhatsAppMessage::where('id', $row->id)->update($times);

        return $row->fresh();
    }

    public function test_the_last_inbound_is_the_message_meta_sent_last()
    {
        $account = $this->createTestAccount();
        $thread  = $this->crossedConversation($account);

        $last = WhatsAppMessage::lastInbound($thread->conversation_id);

        $this->assertNotNull($last, 'no inbound message was found at all');
        $this->assertEquals(
            'wamid.sent-last',
            $last->wamid,
            'the last inbound message is the row written last rather than the message sent last'
        );
    }

    /** And the time comes from that same row, so the two cannot drift apart. */
    public function test_the_time_and_the_message_come_from_the_same_row()
    {
        $account = $this->createTestAccount();
        $thread  = $this->crossedConversation($account);

        $last = WhatsAppMessage::lastInbound($thread->conversation_id);

        $this->assertEquals(
            $last->meta_sent_at->toDateTimeString(),
            WhatsAppMessage::lastInboundAt($thread->conversation_id),
            'lastInboundAt() answers with a different row than lastInbound()'
        );
    }

    /**
     * Two messages sent within the same second are ordinary: somebody sends
     * a line and a photo. The newer row wins, which is the only tie-break
     * available and the one both old rules already used.
     */
    public function test_a_tie_on_metas_time_is_broken_by_the_newer_row()
    {
        $account = $this->createTestAccount();
        $thread  = $this->crossedConversation($account);
        $at      = now()->subMinutes(5);

        $first = $this->inbound($account, $thread->conversation_id, 'wamid.tie-older', self::WROTE_LAST, [
            'created_at'   => $at,
            'meta_sent_at' => $at,
        ]);
        $this->inbound($account, $thread->conversation_id, 'wamid.tie-newer', self::WROTE_LAST, [
            'created_at'   => $at,
            'meta_sent_at' => $at,
        ]);

        // The tie has to be the newest time in the conversation, or the rows
        // above would decide this test instead.
        WhatsAppMessage::where('conversation_id', $thread->conversation_id)
            ->whereNotIn('wamid', ['wamid.tie-older', 'wamid.tie-newer'])
            ->update(['meta_sent_at' => now()->subHours(3)]);

        $this->assertEquals(
            'wamid.tie-newer',
            WhatsAppMessage::lastInbound($thread->conversation_id)->wamid,
            'two messages sharing a timestamp are resolved to the older row'
        );
        $this->assertGreaterThan(
            $first->id,
            WhatsAppMessage::where('wamid', 'wamid.tie-newer')->first()->id,
            'the fixture no longer has the ids the tie-break depends on'
        );
    }

    /**
     * The read receipt, through the job. A row with no `wamid` cannot be
     * marked, and a conversation with no inbound message is a no-op, both of
     * which SendWhatsAppMessageTest already covers; what this adds is which
     * of two messages gets the tick.
     */
    public function test_the_read_receipt_ticks_the_message_meta_sent_last()
    {
        $account = $this->createTestAccount();
        $thread  = $this->crossedConversation($account);

        $client = \Mockery::mock(WhatsAppApiClient::class);
        $client->shouldReceive('sendText')->once()->andReturn([
            'ok' => true, 'wamid' => 'wamid.agent-reply', 'http_status' => 200,
            'error_code' => null, 'error_message' => null, 'transient' => false,
        ]);
        $client->shouldReceive('markAsRead')
            ->once()
            ->with('wamid.sent-last')
            ->andReturn(['ok' => true, 'http_status' => 200]);

        $job = \Mockery::mock(SendWhatsAppMessage::class, [$account->id, $thread->id, self::WROTE_LAST])->makePartial();
        $job->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('apiClient')->andReturn($client);
        $job->handle();
    }

    /**
     * And the recipient, through the core hook an agent's reply really goes
     * through. This is the half with a consequence the customer sees: the
     * answer arriving at the wrong number.
     */
    public function test_the_reply_goes_to_the_number_that_wrote_last()
    {
        $account      = $this->createTestAccount();
        $thread       = $this->crossedConversation($account);
        $conversation = Conversation::find($thread->conversation_id);

        Queue::fake();
        \Eventy::action('chat_conversation.send_reply', $conversation, collect([$thread]), $conversation->customer);

        Queue::assertPushed(SendWhatsAppMessage::class, function ($job) {
            $property = (new \ReflectionObject($job))->getProperty('toPhone');
            $property->setAccessible(true);

            return $property->getValue($job) === self::WROTE_LAST;
        });
    }
}
