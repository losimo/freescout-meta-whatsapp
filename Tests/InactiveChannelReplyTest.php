<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Modules\MetaWhatsApp\Jobs\SendWhatsAppMessage;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Providers\MetaWhatsAppServiceProvider;
use Modules\MetaWhatsApp\Support\OutboundGuard;

/**
 * T28, which is our own finding 3. With the channel switched off, an agent's
 * reply evaporated.
 *
 * The dispatcher looked the account up with is_active = true and, finding
 * none, returned on the assumption that the conversation belonged to some
 * other chat channel. A deactivated account is not somebody else's: it is
 * ours, stopped. And every refusal this module knows how to report lives
 * inside the outbound jobs, none of which was dispatched here, so the agent
 * wrote a reply, pressed send, saw it in the conversation, and nothing else
 * happened anywhere.
 *
 * The inactive-channel banner on the conversation covers part of it, but it
 * does not stop the send and it leaves no mark on the thread.
 */
class InactiveChannelReplyTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeReply(WhatsAppAccount $account): array
    {
        $customer = new Customer();
        $customer->first_name = '+34611222333';
        $customer->save();
        $customer->addChannel(WhatsAppAccount::CHANNEL, '+34611222333');

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

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->user_id            = 1;
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->status             = $conversation->status;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = '<p>reply written while the channel was off</p>';
        $thread->source_via         = Thread::PERSON_USER;
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id        = $customer->id;
        $thread->created_by_user_id = 1;
        $thread->save();

        return [$conversation, $thread];
    }

    protected function dispatchReplies($conversation, array $replies): void
    {
        $provider = new MetaWhatsAppServiceProvider($this->app);
        $method   = new \ReflectionMethod($provider, 'handleOutboundReplies');
        $method->setAccessible(true);
        $method->invoke($provider, $conversation, $replies);
    }

    protected function refusalNote(Conversation $conversation): ?Thread
    {
        return Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->where('body', 'like', OutboundGuard::NOTE_MARKER . '%')
            ->first();
    }

    public function test_a_reply_on_an_inactive_channel_leaves_a_note()
    {
        Queue::fake();
        $account = $this->createTestAccount(['is_active' => false]);
        [$conversation, $thread] = $this->makeReply($account);

        $this->dispatchReplies($conversation, [$thread]);

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note, 'the reply died with the channel off and the conversation says nothing');
        $this->assertNotNull($note->created_by_user_id, 'an authorless note breaks the core print view (#55)');
        $this->assertNothingSentToMeta();
    }

    /**
     * The refusal has to be an error in the log too: an installation running
     * at log_level above warning would otherwise have no record at all.
     */
    public function test_the_refusal_is_logged_as_an_error()
    {
        Queue::fake();
        \Log::spy();

        $account = $this->createTestAccount(['is_active' => false]);
        [$conversation, $thread] = $this->makeReply($account);

        $this->dispatchReplies($conversation, [$thread]);

        \Log::shouldHaveReceived('error')->atLeast()->once();
    }

    /**
     * What must not change: a chat conversation in a mailbox this module
     * knows nothing about still goes through untouched. That return is the
     * one the inactive channel was wrongly taking.
     */
    public function test_a_chat_conversation_of_another_channel_is_left_alone()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeReply($account);

        $other = new \App\Mailbox();
        $other->name       = 'Someone else';
        $other->email      = 'other-' . uniqid() . '@example.com';
        $other->out_method = \App\Mailbox::OUT_METHOD_PHP_MAIL;
        $other->save();
        $conversation->mailbox_id = $other->id;
        $conversation->save();

        $this->dispatchReplies($conversation, [$thread]);

        $this->assertNull($this->refusalNote($conversation), 'the module left a note on another channel conversation');
        Queue::assertNothingPushed();
    }

    /** And an active channel still dispatches, which is the whole point. */
    public function test_an_active_channel_still_sends()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeReply($account);

        $this->dispatchReplies($conversation, [$thread]);

        Queue::assertPushed(SendWhatsAppMessage::class);
        $this->assertNull($this->refusalNote($conversation));
    }

    /**
     * The note on this path must not ask the agent to send the reply again,
     * because nothing here throws the reply away.
     *
     * The core hands this hook every message thread of the conversation and
     * not only the new one (core, app/Listeners/SendReplyToCustomer.php:41),
     * and the dispatch loop sends any reply that has no row of its own. So
     * a reply refused with the channel off is dispatched the next time the
     * agent writes with the channel on, and an agent who had followed the
     * jobs' note and typed it again would have the customer read the same
     * answer twice. The case below is the mechanism; this one is the
     * sentence.
     */
    public function test_the_note_on_this_path_does_not_ask_for_the_reply_to_be_sent_again()
    {
        Queue::fake();
        $account = $this->createTestAccount(['is_active' => false]);
        [$conversation, $thread] = $this->makeReply($account);

        $this->dispatchReplies($conversation, [$thread]);

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note);
        $this->assertEquals(
            'metawhatsapp::metawhatsapp.not_sent_channel_inactive_kept',
            $note->getMeta(OutboundGuard::META_REFUSED_REASON),
            'this path left the note the outbound jobs use, which asks for a second send'
        );
    }

    /**
     * And the mechanism itself, which is what makes that note a lie: the
     * reply refused while the channel was off is dispatched when it comes
     * back. Pinned because the sentence above depends on it, so if this ever
     * changes the sentence has to change with it.
     */
    public function test_a_reply_refused_with_the_channel_off_is_dispatched_once_it_is_back()
    {
        Queue::fake();
        $account = $this->createTestAccount(['is_active' => false]);
        [$conversation, $first] = $this->makeReply($account);

        $this->dispatchReplies($conversation, [$first]);
        $this->assertNothingSentToMeta();

        $account->is_active = true;
        $account->save();

        $second = new Thread();
        $second->conversation_id    = $conversation->id;
        $second->user_id            = 1;
        $second->type               = Thread::TYPE_MESSAGE;
        $second->status             = $conversation->status;
        $second->state              = Thread::STATE_PUBLISHED;
        $second->body               = '<p>and the agent writes again</p>';
        $second->source_via         = Thread::PERSON_USER;
        $second->source_type        = Thread::SOURCE_TYPE_WEB;
        $second->customer_id        = $conversation->customer_id;
        $second->created_by_user_id = 1;
        $second->save();

        // The shape the core passes: the new reply and the older ones.
        $this->dispatchReplies($conversation, [$second, $first]);

        Queue::assertPushed(SendWhatsAppMessage::class, 2);
    }

    /**
     * The review's finding. Every job-side refusal reopens the conversation,
     * because a note in a closed conversation sits where nobody looks (#19),
     * and this branch wrote the note without reopening. An agent who uses
     * reply and close in one action is precisely the person who would never
     * see it.
     */
    public function test_the_refusal_reopens_a_conversation_the_agent_had_closed()
    {
        Queue::fake();
        $account = $this->createTestAccount(['is_active' => false]);
        [$conversation, $thread] = $this->makeReply($account);

        $conversation->setStatus(Conversation::STATUS_CLOSED);
        $conversation->save();

        $this->dispatchReplies($conversation, [$thread]);

        $this->assertEquals(
            Conversation::STATUS_ACTIVE,
            (int) $conversation->fresh()->status,
            'the note landed in a closed conversation, where the agent will not see it'
        );
    }

    // -----------------------------------------------------------------
    // The same event caught inside the job (the review's race, 25 Nov)
    // -----------------------------------------------------------------

    /**
     * The other side of the dispatch, and the reason this matters.
     *
     * The channel can go off in the seconds between the dispatcher letting a
     * reply through and the job running, and then the refusal is reported
     * from inside the job instead. That path used to write a failed row, and
     * the dispatcher skips any reply thread that already has an outbound row
     * (MetaWhatsAppServiceProvider: "Ja enviat (o fallit definitivament)"),
     * so the reply above went out when the channel came back while this one
     * never did. Same event, two endings, and the customer's answer hanging
     * on which side of the dispatch the channel happened to go off.
     */
    public function test_a_reply_refused_inside_the_job_is_also_dispatched_once_it_is_back()
    {
        Queue::fake();
        $account = $this->createTestAccount(['is_active' => false]);
        [$conversation, $thread] = $this->makeReply($account);

        // Not the dispatcher this time: the job itself, which is what runs
        // when the channel was still active a moment earlier.
        (new SendWhatsAppMessage($account->id, $thread->id, '+34611222333'))->handle();

        $this->assertNotNull($this->refusalNote($conversation), 'the job path says nothing in the conversation');

        $account->is_active = true;
        $account->save();

        $this->dispatchReplies($conversation, [$thread]);

        Queue::assertPushed(SendWhatsAppMessage::class, 1);
    }

    /**
     * And it has to say the same thing, because the agent cannot tell which
     * path caught their reply. Both notes now report a reply that is still
     * pending rather than one to be written again.
     */
    public function test_both_paths_say_the_reply_is_still_pending()
    {
        Queue::fake();
        $account = $this->createTestAccount(['is_active' => false]);
        [$conversation, $thread] = $this->makeReply($account);

        (new SendWhatsAppMessage($account->id, $thread->id, '+34611222333'))->handle();

        $this->assertStringContainsString(
            __('metawhatsapp::metawhatsapp.not_sent_channel_inactive_kept'),
            (string) $this->refusalNote($conversation)->body,
            'the job path asks the agent to write the reply again, which would send it twice'
        );
    }

    /**
     * The rule in one place, and its two exceptions stated as cases rather
     * than as prose: a channel that is gone will never send this reply, and
     * a template has no second chance to protect because an agent sends one
     * by pressing a button.
     */
    public function test_which_refusals_leave_a_row()
    {
        $off = $this->createTestAccount(['is_active' => false]);
        $on  = $this->createTestAccount();

        $this->assertFalse(
            OutboundGuard::refusalLeavesARow($off->id, OutboundGuard::SUBJECT_MESSAGE),
            'a reply refused on a channel that is merely off would be recorded as failed'
        );
        $this->assertFalse(
            OutboundGuard::refusalLeavesARow($off->id, OutboundGuard::SUBJECT_MEDIA),
            'the attachments of a reply would be recorded as failed'
        );
        $this->assertTrue(
            OutboundGuard::refusalLeavesARow($off->id, OutboundGuard::SUBJECT_TEMPLATE),
            'a template refused with the channel off would leave no trace at all'
        );
        $this->assertTrue(
            OutboundGuard::refusalLeavesARow($on->id + 10000, OutboundGuard::SUBJECT_MESSAGE),
            'a reply whose channel has been deleted would leave no trace at all'
        );
    }

    /**
     * The third answer, which is a race rather than a case.
     *
     * This question is only ever asked inside the branch where
     * accountForSending() has already refused, and it refuses only when the
     * channel is missing or inactive. So a channel that is found and active
     * by the time this runs is one an administrator reconnected in between,
     * which is the switched-off case arriving a second late, and the note
     * written moments earlier already says the reply is still pending.
     *
     * Answering yes there writes a permanent failed row, and the dispatcher
     * skips any reply thread that holds one, so the reply is never sent
     * while the note says it will be. The row and the note cannot
     * contradict each other.
     */
    public function test_a_channel_reconnected_mid_refusal_still_leaves_no_row()
    {
        $account = $this->createTestAccount();

        $this->assertFalse(
            OutboundGuard::refusalLeavesARow($account->id, OutboundGuard::SUBJECT_MESSAGE),
            'the reply was recorded as failed because the channel came back between two lookups,'
                . ' so it will never be sent and the note says it is pending'
        );
    }
}
