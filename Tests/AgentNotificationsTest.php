<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\Notifications\WebsiteNotification;
use App\Subscription;
use App\Thread;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Jobs\SendWhatsAppMessage;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Providers\MetaWhatsAppServiceProvider;
use Modules\MetaWhatsApp\Support\AgentNotifications;
use Modules\MetaWhatsApp\Support\ConversationNote;
use Modules\MetaWhatsApp\Support\NoteAuthor;
use Modules\MetaWhatsApp\Support\OutboundGuard;

/**
 * Issue #64. Every note this module writes used to notify nobody.
 *
 * Core fires App\Events\UserAddedNote and the conversation.note_added action
 * from Thread::create() (app/Thread.php:1307 on 1.8.252). Support/
 * ConversationNote builds the Thread by hand and saves it, so neither ran:
 * a refused reply, an undelivered message or a number change appeared in the
 * conversation and nothing told anyone it was there. No bell, no e-mail, no
 * browser push, not even for an agent subscribed to "A note is added to a
 * conversation".
 *
 * The reproduction is MarkoD's, from the issue thread: a conversation
 * assigned to an agent and closed, a reply Meta refuses, and the agent
 * subscribed to "another user replies or adds a note to one of my
 * conversations". The note appears, the conversation comes back, and the
 * agent hears nothing.
 */
class AgentNotificationsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * A conversation assigned to $agent, closed, with the agent's own reply
     * on it and a customer that has a WhatsApp number.
     *
     * The reply is stamped a minute ago on purpose. Subscription reads the
     * previous thread to decide which event family a note belongs to
     * (app/Subscription.php:212-220) and getThreads() orders by created_at
     * alone (app/Conversation.php:417), so two rows written in the same
     * second would leave the branch up to the storage engine.
     */
    protected function makeClosedAssignedReply(WhatsAppAccount $account, User $agent): array
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
        $conversation->status         = Conversation::STATUS_CLOSED;
        $conversation->user_id        = $agent->id;
        $conversation->source_via     = Conversation::PERSON_CUSTOMER;
        $conversation->source_type    = Conversation::SOURCE_TYPE_API;
        $conversation->preview        = 'x';
        $conversation->save();

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->user_id            = $agent->id;
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->status             = $conversation->status;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = '<p>a reply Meta will refuse</p>';
        $thread->source_via         = Thread::PERSON_USER;
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id        = $customer->id;
        $thread->created_by_user_id = $agent->id;
        $thread->created_at         = now()->subMinute();
        $thread->save();

        return [$conversation, $thread];
    }

    /**
     * Subscribes a user to "another FreeScout user replies or adds a note to
     * one of my conversations", through the medium that also feeds the
     * notifications menu.
     */
    protected function subscribeToNotesOnMyConversations(User $user): void
    {
        // firstOrCreate and not create: the core gives every new user this
        // subscription by default (Subscription::$default_subscriptions),
        // and the table has a unique key on user_id + medium + event. It is
        // written here anyway so the test states what it depends on instead
        // of inheriting it from a core default that can change.
        Subscription::firstOrCreate([
            'user_id' => $user->id,
            'medium'  => Subscription::MEDIUM_EMAIL,
            'event'   => Subscription::EVENT_USER_REPLIED_TO_MY,
        ]);

        // The buffer is a static array shared by the whole PHP process, so
        // another test in this run may have left events in it. Starting from
        // empty is what makes the assertions below mean something.
        Subscription::$occurred_events = [];
    }

    /**
     * The regression test the issue thread asked for first.
     *
     * Registering the event is necessary and not sufficient:
     * Subscription::registerEvent() only appends to a static buffer, and core
     * empties that buffer in exactly two places, the TerminateHandler
     * middleware and the FetchEmails command. Neither runs in a queue worker,
     * which was issue #46 for inbound messages. This refusal is written by an
     * outbound job, on the queue, so without a flush of its own the event
     * would die in the worker's memory and the fix would look done while
     * notifying nobody.
     *
     * It asserts what the agent ends up with rather than that a particular
     * call was made.
     */
    public function test_a_refused_reply_notifies_the_agent_whose_reply_failed()
    {
        $account = $this->createTestAccount(['is_active' => false]);
        $agent   = $this->makeAdminUser();
        [$conversation, $thread] = $this->makeClosedAssignedReply($account, $agent);
        $this->subscribeToNotesOnMyConversations($agent);

        Queue::fake();
        Notification::fake();

        (new SendWhatsAppMessage($account->id, $thread->id, '+34611222333'))->handle();

        $note = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->first();
        $this->assertNotNull($note, 'the refusal left no note, so this no longer tests the notification');

        Notification::assertSentTo($agent, WebsiteNotification::class);

        $this->assertSame(
            [],
            Subscription::$occurred_events,
            'the job returned with events still buffered, so nothing flushed them and no agent was told'
        );
    }

    /**
     * MarkoD's reason for registering the event ourselves instead of firing
     * UserAddedNote (#64), asserted on the event itself.
     *
     * Subscription drops the user an event is attributed to from its own
     * recipients (app/Subscription.php:373), and the core's listener takes
     * that user from the note's created_by_user_id
     * (SendNotificationToUsers.php:37-40). Since #43 the note carries a real
     * user, the agent it concerns or the installation's first administrator
     * (#68), so firing the event would have excluded whoever signed it,
     * starting with the one agent whose reply had just failed.
     *
     * The two have to stay different things: the note's author is never null,
     * because the core's print view reads its name without checking (#55),
     * and the event's "caused by" is null unless an administrator chose a
     * note author.
     *
     * Called directly rather than through the job, because a flushed buffer
     * has nothing left to look at.
     */
    public function test_with_no_note_author_configured_the_event_excludes_nobody()
    {
        $account = $this->createTestAccount();
        $agent   = $this->makeAdminUser();
        [$conversation, $thread] = $this->makeClosedAssignedReply($account, $agent);

        OutboundGuard::noteRefusal($thread, 'metawhatsapp::metawhatsapp.not_sent_channel_gone');

        $note = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->first();
        $this->assertNotNull($note, 'the refusal left no note, so this tests nothing');
        $this->assertNotNull(
            $note->created_by_user_id,
            'an authorless note breaks the core print view (#55)'
        );

        $this->assertCount(
            2,
            Subscription::$occurred_events,
            'registerEvent() appends an EVENT_TYPE_UPDATED entry of its own, so one note leaves two'
        );
        $event = Subscription::$occurred_events[0];
        $this->assertEquals(Subscription::EVENT_TYPE_USER_ADDED_NOTE, $event['event_type']);
        $this->assertNull(
            $event['caused_by_user_id'],
            'the event is attributed to a user, who Subscription then leaves out of it'
                . ' (app/Subscription.php:373). With no note author configured that would be'
                . ' whoever signed the note, and the agent whose reply failed is exactly who'
                . ' has to be told'
        );
    }

    /**
     * The other half of the same decision: the configured note author is the
     * one user left out.
     *
     * It is the module's designated identity rather than a person who acted,
     * so it is excluded the way the core excludes whoever adds a note. That
     * is also why the setting's help text asks for a placeholder and not for
     * somebody who works conversations: a real agent chosen here stops being
     * told about the module's notes.
     */
    public function test_the_configured_note_author_is_not_notified_about_its_own_notes()
    {
        $account = $this->createTestAccount(['is_active' => false]);
        $agent   = $this->makeAdminUser();
        [$conversation, $thread] = $this->makeClosedAssignedReply($account, $agent);
        $this->subscribeToNotesOnMyConversations($agent);

        NoteAuthor::setUserId($agent->id);

        Queue::fake();
        Notification::fake();

        (new SendWhatsAppMessage($account->id, $thread->id, '+34611222333'))->handle();

        $this->assertNotNull(
            Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_NOTE)->first(),
            'no note was written, so this asserts nothing about who was notified'
        );
        Notification::assertNotSentTo($agent, WebsiteNotification::class);
    }

    /**
     * A number change notifies nobody, which is MarkoD's scope rule: a note
     * that needs an agent to come back notifies, a note that does not, does
     * not. This one comes with no customer message behind it, so notifying
     * would send one notification per affected conversation, closed ones
     * included.
     *
     * Asserted through the buffer rather than through the absence of a
     * notification, because an empty buffer and a buffer nobody was
     * subscribed to look the same from the outside.
     */
    public function test_a_number_change_note_registers_no_event()
    {
        $account = $this->createTestAccount();
        $agent   = $this->makeAdminUser();
        [$conversation, $thread] = $this->makeClosedAssignedReply($account, $agent);
        $this->subscribeToNotesOnMyConversations($agent);

        $job    = new \ReflectionMethod(ProcessInboundWebhook::class, 'noteNumberChange');
        $job->setAccessible(true);
        $job->invoke(new ProcessInboundWebhook($account->id, []), [$conversation->id]);

        $this->assertNotNull(
            Thread::where('conversation_id', $conversation->id)
                ->where('type', Thread::TYPE_NOTE)
                ->where('body', 'like', '%' . __('metawhatsapp::metawhatsapp.contact_number_changed') . '%')
                ->first(),
            'the number-change note was not written, so this asserts nothing'
        );
        $this->assertSame(
            [],
            Subscription::$occurred_events,
            'a number change registered a notification event, so every affected conversation,'
                . ' closed ones included, would notify its agents'
        );
    }

    /**
     * Other modules see our notes like any other note.
     *
     * The core fires conversation.note_added from Thread::create()
     * (app/Thread.php:1307), which our hand-built note never went through, so
     * a module or a Workflow rule listening for an added note never heard
     * about ours. It fires for every note, notified or not.
     */
    public function test_every_module_note_fires_the_cores_note_added_action()
    {
        $account = $this->createTestAccount();
        $agent   = $this->makeAdminUser();
        [$conversation, $thread] = $this->makeClosedAssignedReply($account, $agent);

        $seen = [];
        \Eventy::addAction('conversation.note_added', function ($conv, $note) use (&$seen) {
            $seen[] = (int) $note->id;
        }, 20, 2);

        ConversationNote::write($conversation, 'notified note', null, [], true);
        ConversationNote::write($conversation, 'quiet note', null, [], false);

        $this->assertCount(
            2,
            $seen,
            'conversation.note_added did not fire for both notes, so a Workflow rule or another'
                . ' module reacting to notes still cannot see ours'
        );
    }

    /**
     * Three attachments of one reply failing the same way leave one note, and
     * they must leave one notification too.
     *
     * noteRefusal() de-duplicates per reply and per reason (#51) and the
     * suppressed call still brings the conversation back. Registering from
     * there would notify again for a note that was not written.
     */
    public function test_a_suppressed_duplicate_note_does_not_notify_again()
    {
        $account = $this->createTestAccount();
        $agent   = $this->makeAdminUser();
        [$conversation, $thread] = $this->makeClosedAssignedReply($account, $agent);
        $this->subscribeToNotesOnMyConversations($agent);

        OutboundGuard::noteRefusal($thread, 'metawhatsapp::metawhatsapp.not_sent_channel_gone');
        $first = count(Subscription::$occurred_events);

        OutboundGuard::noteRefusal($thread, 'metawhatsapp::metawhatsapp.not_sent_channel_gone');

        $this->assertGreaterThan(0, $first, 'the first refusal registered nothing, so this tests nothing');
        $this->assertCount(
            1,
            Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_NOTE)->get(),
            'the de-duplication stopped working, so this no longer tests the second call'
        );
        $this->assertCount(
            $first,
            Subscription::$occurred_events,
            'a suppressed duplicate note registered a second event, so one failure notifies twice'
        );
    }

    /**
     * The failure half of the flush, which used to live in
     * ProcessInboundWebhook and now covers every job (#46, then #64).
     *
     * The buffer is a static array, so a rollback does not touch it. Flushing
     * after one is worse than not flushing: on a reopened conversation the
     * conversation row survives and only the new thread disappears, so
     * processEvents() would pick the previous thread and notify the whole
     * mailbox about a message the agents had already read, three times over
     * because the job retries.
     */
    public function test_a_run_that_throws_notifies_nobody_and_leaves_the_buffer_as_it_was()
    {
        $account = $this->createTestAccount();
        $agent   = $this->makeAdminUser();
        [$conversation, $thread] = $this->makeClosedAssignedReply($account, $agent);
        $this->subscribeToNotesOnMyConversations($agent);

        Queue::fake();
        Notification::fake();

        $thrown = null;
        try {
            AgentNotifications::around(function () use ($conversation) {
                ConversationNote::write($conversation, 'a note whose run then throws', null, [], true);

                throw new \RuntimeException('something threw after the note was written');
            }, ['job' => 'phpunit']);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'the exception was swallowed, so the queue would never retry the job');
        $this->assertSame(
            [],
            Subscription::$occurred_events,
            'the event stayed in the buffer and the next job on this worker would flush it'
        );
        Notification::assertNotSentTo($agent, WebsiteNotification::class);
    }

    /**
     * And the path that was easiest to forget: the note written once the
     * retries are over.
     *
     * failed() writes a note through OutboundFailure, so a flush placed at
     * the end of handle() would never run for it. This is why the whole job
     * is wrapped rather than its last line.
     */
    public function test_the_note_written_when_the_retries_run_out_notifies_too()
    {
        $account = $this->createTestAccount();
        $agent   = $this->makeAdminUser();
        [$conversation, $thread] = $this->makeClosedAssignedReply($account, $agent);
        $this->subscribeToNotesOnMyConversations($agent);

        Queue::fake();
        Notification::fake();

        (new SendWhatsAppMessage($account->id, $thread->id, '+34611222333'))
            ->failed(new \RuntimeException('three attempts, no luck'));

        $note = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->first();
        $this->assertNotNull($note, 'failed() left no note, so this no longer tests its notification');

        Notification::assertSentTo($agent, WebsiteNotification::class);
        $this->assertSame(
            [],
            Subscription::$occurred_events,
            'failed() returned with its event still buffered, so the agent was never told'
        );
    }

    /**
     * A note that never survives tells nobody it existed.
     *
     * Two of the four notes are written inside a DB::transaction(), on
     * purpose: the number-change note has to live or die with the move
     * (ProcessInboundWebhook::moveIdentity), and the matched-several note has
     * to be written before the customer's thread so the core's ThreadObserver
     * does not leave our warning as the folder-list preview. Telling the core
     * about them from there would push agents' browsers a new thread, and
     * other modules a conversation.note_added, for a row that can still
     * disappear.
     *
     * This path is the one with no second chance: the inbound job swallows
     * the duplicate-wamid race (1062) and returns normally, so nothing else
     * would ever say the note had gone.
     */
    public function test_a_note_whose_transaction_rolls_back_tells_nobody()
    {
        $account = $this->createTestAccount();
        $agent   = $this->makeAdminUser();
        [$conversation, $thread] = $this->makeClosedAssignedReply($account, $agent);

        $told = [];
        \Eventy::addAction('conversation.note_added', function ($conv, $note) use (&$told) {
            $told[] = (int) $note->id;
        }, 20, 2);

        AgentNotifications::around(function () use ($conversation) {
            try {
                DB::transaction(function () use ($conversation) {
                    ConversationNote::write($conversation, 'a note that will not survive', null, [], false);

                    throw new \RuntimeException('rolled back after the note was written');
                });
            } catch (\RuntimeException $e) {
                // Swallowed on purpose, the way the 1062 race is swallowed.
            }
        }, ['job' => 'phpunit']);

        $this->assertCount(
            0,
            Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_NOTE)->get(),
            'the note survived the rollback, so this no longer tests a vanished note'
        );
        $this->assertSame(
            [],
            $told,
            'conversation.note_added fired for a note that was rolled back, so another module'
                . ' acted on a note that does not exist'
        );
    }

    /**
     * And the note that does survive is told about once the work is over,
     * not while the transaction is still open.
     *
     * The order is the assertion. Firing from inside the transaction is what
     * the test above is about; firing late but losing it altogether would
     * pass that test and break the live refresh, so both halves are pinned
     * here.
     */
    public function test_a_note_written_in_a_transaction_is_told_about_after_it_commits()
    {
        $account = $this->createTestAccount();
        $agent   = $this->makeAdminUser();
        [$conversation, $thread] = $this->makeClosedAssignedReply($account, $agent);

        $steps = [];
        \Eventy::addAction('conversation.note_added', function ($conv, $note) use (&$steps) {
            $steps[] = 'told';
        }, 20, 2);

        AgentNotifications::around(function () use ($conversation, &$steps) {
            DB::transaction(function () use ($conversation, &$steps) {
                ConversationNote::write($conversation, 'a note that commits', null, [], false);
                $steps[] = 'inside the transaction';
            });

            $steps[] = 'committed';
        }, ['job' => 'phpunit']);

        $this->assertSame(['inside the transaction', 'committed', 'told'], $steps);
    }

    /**
     * The hole the first version of this fix left, and the reason it was a
     * hole: the issue's own table said this path was flushed by the HTTP
     * request, and it is not.
     *
     * `handleOutboundReplies` is hooked on `chat_conversation.send_reply`,
     * and the core fires that through Helper::backgroundAction()
     * (app/Listeners/SendReplyToCustomer.php:89), which dispatches the
     * App\Jobs\TriggerAction job onto the default queue with the undo delay.
     * So it runs in a queue worker, exactly where TerminateHandler and
     * FetchEmails do not, and it is the commonest refusal of all: the
     * channel is switched off and the agent's reply never leaves (T28).
     *
     * Without a flush of its own the event does not merely vanish, which is
     * worse than vanishing: the buffer is static and process-wide, so the
     * next job of ours on that worker flushes it along with its own, and the
     * notification arrives attached to whichever thread the core picks then.
     */
    public function test_a_refusal_from_the_reply_hook_notifies_too()
    {
        $account = $this->createTestAccount(['is_active' => false]);
        $agent   = $this->makeAdminUser();
        [$conversation, $thread] = $this->makeClosedAssignedReply($account, $agent);
        $this->subscribeToNotesOnMyConversations($agent);

        Queue::fake();
        Notification::fake();

        $provider = new MetaWhatsAppServiceProvider($this->app);
        $method   = new \ReflectionMethod($provider, 'handleOutboundReplies');
        $method->setAccessible(true);
        $method->invoke($provider, $conversation, [$thread]);

        $note = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->first();
        $this->assertNotNull($note, 'the hook left no refusal note, so this tests nothing');

        Notification::assertSentTo($agent, WebsiteNotification::class);
        $this->assertSame(
            [],
            Subscription::$occurred_events,
            'the hook returned with its event still buffered, so the next job of ours on this'
                . ' worker would flush it late and attach it to another thread'
        );
    }

    /**
     * A third party listening on the new hook cannot take the notifications
     * with it.
     *
     * conversation.note_added is a surface other modules and Workflow rules
     * are invited to use, so somebody else's code now runs in the middle of
     * our flush phase, after the work is done and committed. A throw from
     * there used to skip the notes after it, skip the flush entirely, leave
     * no trace of either, and bill the job a failed attempt that would redo
     * work already written.
     */
    public function test_a_listener_that_throws_does_not_take_the_notifications_with_it()
    {
        $account = $this->createTestAccount(['is_active' => false]);
        $agent   = $this->makeAdminUser();
        [$conversation, $thread] = $this->makeClosedAssignedReply($account, $agent);
        $this->subscribeToNotesOnMyConversations($agent);

        Queue::fake();
        Notification::fake();
        \Log::spy();

        \Eventy::addAction('conversation.note_added', function () {
            throw new \RuntimeException('a module listening for notes threw');
        }, 20, 2);

        (new SendWhatsAppMessage($account->id, $thread->id, '+34611222333'))->handle();

        Notification::assertSentTo($agent, WebsiteNotification::class);
        $this->assertSame([], Subscription::$occurred_events, 'the buffer was left for the next job to flush');

        \Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) {
            return strpos($message, 'listener') !== false;
        })->atLeast()->once();
    }

    /**
     * A flush that fails must not poison the worker.
     *
     * processEvents() drains the whole static buffer, ours and anything
     * another job left in it, because the core offers no way to flush part
     * of it. So an entry the core dies on, a conversation whose mailbox is
     * gone being the one we have already met, is not one lost notification:
     * leaving it there means every later job of ours that writes a note
     * calls processEvents(), hits the same entry, throws again, and nothing
     * is ever notified for the life of that worker, visible only as the
     * same log line repeating.
     *
     * Once this module calls processEvents() it has taken on the whole
     * buffer, so a failed flush clears it and says how many entries went.
     * One lost batch with a trace beats a worker that never notifies again.
     */
    public function test_a_flush_that_fails_does_not_poison_the_rest_of_the_worker()
    {
        $account = $this->createTestAccount(['is_active' => false]);
        $agent   = $this->makeAdminUser();
        [$conversation, $thread] = $this->makeClosedAssignedReply($account, $agent);
        $this->subscribeToNotesOnMyConversations($agent);

        Queue::fake();
        Notification::fake();
        \Log::spy();

        // What the core dies on: an event for a conversation whose mailbox
        // is not there any more (app/Subscription.php, userIdsHavingAccess()
        // on null). Another job on this worker could have left it.
        $orphan = new Conversation();
        $orphan->id         = $conversation->id;
        $orphan->mailbox_id = 0;
        Subscription::registerEvent(Subscription::EVENT_TYPE_USER_ADDED_NOTE, $orphan, null);

        $thrown = null;
        try {
            (new SendWhatsAppMessage($account->id, $thread->id, '+34611222333'))->handle();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNull($thrown, 'the job failed because somebody else poisoned the buffer, so it will retry for ever');
        $this->assertSame(
            [],
            Subscription::$occurred_events,
            'the entry the flush died on is still in the buffer, so every later note on this worker'
                . ' dies on it too and nothing is ever notified again'
        );
        \Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) {
            return strpos($message, 'could not be flushed') !== false
                && ($context['discarded_entries'] ?? 0) > 0;
        })->atLeast()->once();
    }
}
