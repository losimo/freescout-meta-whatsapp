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
}
