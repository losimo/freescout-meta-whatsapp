<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Providers\MetaWhatsAppServiceProvider;
use Modules\MetaWhatsApp\Support\OutboundGuard;

/**
 * A reply that never leaves has to say so in the conversation.
 *
 * Issues #28 and #29 were this same defect for an inactive channel, and the
 * fix then was OutboundGuard. The path covered here was not looked at: a
 * contact with no phone number on file. Nothing is dispatched, nothing is
 * logged where the agent would see it, and the thread sits in FreeScout
 * looking exactly like a delivered reply.
 */
class OutboundRefusalTest extends TestCase
{
    use DatabaseTransactions;

    /** Conversation on the account's mailbox whose customer has no phone at all. */
    protected function makeReplyWithoutRecipientPhone(WhatsAppAccount $account): array
    {
        $customer = new Customer();
        $customer->first_name = 'No phone';
        $customer->save();

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
        $thread->body               = '<p>reply that cannot go anywhere</p>';
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

    /**
     * Issue #59 made this reachable: a customer can now hold more than one
     * WhatsApp number, and the recipient fallback used to answer with
     * whichever row came first. On a conversation with no inbound message to
     * point at one of them, sending would mean delivering an agent's reply to
     * a person they were not writing to, with nothing on screen saying so.
     */
    public function test_a_reply_is_refused_when_the_customer_has_several_whatsapp_numbers_and_nothing_points_at_one()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeReplyWithoutRecipientPhone($account);

        \App\CustomerChannel::create($conversation->customer_id, WhatsAppAccount::CHANNEL, '+34611222333');
        \App\CustomerChannel::create($conversation->customer_id, WhatsAppAccount::CHANNEL, '+34655444555');

        $this->dispatchReplies($conversation, [$thread]);

        $note = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->first();

        $this->assertNotNull($note, 'a reply that was never sent left no trace on the conversation');
        $this->assertStringContainsString(
            __('metawhatsapp::metawhatsapp.not_sent_several_numbers'),
            $note->body
        );
    }

    public function test_reply_without_recipient_phone_leaves_a_note()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeReplyWithoutRecipientPhone($account);

        $this->dispatchReplies($conversation, [$thread]);

        $note = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->first();

        $this->assertNotNull($note, 'a reply with no recipient phone left no trace on the conversation');
        $this->assertStringContainsString(OutboundGuard::NOTE_MARKER, $note->body);
    }

    /**
     * Issue #43. Thread::getPerson() reads created_by_user_cached for a note,
     * so a note without that column renders faceless with the name on the
     * right. The refusal note always concerns one agent's reply, and that
     * agent is already resolved to pick the note's user_id.
     *
     * The test uses an agent of its own rather than the helper's hardcoded
     * user 1, so the assertion proves the value travels instead of matching
     * a default by coincidence.
     */
    public function test_a_refusal_note_is_attributed_to_the_agent_whose_reply_was_refused()
    {
        $agent = new \App\User();
        $agent->first_name = 'Refused';
        $agent->last_name  = 'Author';
        $agent->email      = 'refused-' . uniqid() . '@example.com';
        $agent->password   = bcrypt('secret');
        $agent->role       = \App\User::ROLE_USER;
        $agent->save();

        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeReplyWithoutRecipientPhone($account);
        $thread->created_by_user_id = $agent->id;
        $thread->save();

        $this->dispatchReplies($conversation, [$thread]);

        $note = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->first();

        $this->assertNotNull($note);
        $this->assertEquals(
            $agent->id,
            $note->created_by_user_id,
            'the refusal note must name the agent whose reply was refused'
        );
    }

    /**
     * An internal note or a withdrawn thread was never going to be sent, so
     * refusing to send it is not news and must not produce a note of its own.
     */
    public function test_a_reply_that_was_never_going_to_be_sent_gets_no_note()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeReplyWithoutRecipientPhone($account);

        $thread->type = Thread::TYPE_NOTE;
        $thread->save();

        $this->dispatchReplies($conversation, [$thread]);

        $this->assertEquals(
            1,
            Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_NOTE)->count(),
            'a note-only reply produced a refusal note on top of itself'
        );
    }

    /**
     * A reply carrying several attachments dispatches one job per file. Three
     * identical notes would be worse than none.
     */
    public function test_the_note_is_written_once_per_thread()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeReplyWithoutRecipientPhone($account);

        $this->dispatchReplies($conversation, [$thread]);
        $this->dispatchReplies($conversation, [$thread]);
        $this->dispatchReplies($conversation, [$thread]);

        $this->assertEquals(
            1,
            Thread::where('conversation_id', $conversation->id)
                ->where('type', Thread::TYPE_NOTE)
                ->where('body', 'like', OutboundGuard::NOTE_MARKER . '%')
                ->count()
        );
    }

    // ------------------------------------------------- issue #51

    /**
     * Issue #51. The de-duplication was "any refusal note in this
     * conversation in the last five minutes", which is a crude stand-in for
     * "the three attachments of one reply". Two different replies failing a
     * couple of minutes apart left a single note, so the second refusal was
     * silent, and the reporter asked for the identifier of the reply to be
     * the key instead.
     *
     * Note that the suppression no longer expires: the same reply cannot
     * collect a second note hours later either, which is the behaviour change
     * this carries.
     */
    public function test_two_replies_that_fail_leave_one_note_each()
    {
        $account = $this->createTestAccount();
        [$conversation, $first] = $this->makeReplyWithoutRecipientPhone($account);

        $this->dispatchReplies($conversation, [$first]);

        $second = new Thread();
        $second->conversation_id    = $conversation->id;
        $second->user_id            = 1;
        $second->type               = Thread::TYPE_MESSAGE;
        $second->status             = $conversation->status;
        $second->state              = Thread::STATE_PUBLISHED;
        $second->body               = '<p>second reply, same conversation</p>';
        $second->source_via         = Thread::PERSON_USER;
        $second->source_type        = Thread::SOURCE_TYPE_WEB;
        $second->customer_id        = $conversation->customer_id;
        $second->created_by_user_id = 1;
        $second->save();

        $this->dispatchReplies($conversation, [$second]);

        $notes = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->where('body', 'like', OutboundGuard::NOTE_MARKER . '%')
            ->count();

        $this->assertEquals(2, $notes, 'the second reply failed in silence because the first had just failed');
    }

    /**
     * The identifier is stored as a string, and the reason is a collision the
     * reporter's own pattern would have had: looking for ":1" inside the JSON
     * also matches ":123", so one reply's note would suppress another's.
     */
    public function test_a_threads_note_does_not_suppress_a_note_of_a_thread_whose_id_starts_the_same()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeReplyWithoutRecipientPhone($account);

        $this->dispatchReplies($conversation, [$thread]);
        $note = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->where('body', 'like', OutboundGuard::NOTE_MARKER . '%')
            ->first();

        $this->assertNotNull($note);
        $this->assertSame(
            (string) $thread->id,
            $note->getMeta(OutboundGuard::META_REFUSED_THREAD),
            'the refused thread id is not stored as a string, so a shorter id would match a longer one'
        );
    }
}
