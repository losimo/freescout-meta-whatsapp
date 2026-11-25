<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Modules\MetaWhatsApp\Jobs\SendWhatsAppMedia;
use Modules\MetaWhatsApp\Jobs\SendWhatsAppMessage;
use Modules\MetaWhatsApp\Jobs\SendWhatsAppTemplate;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Support\OutboundGuard;

/**
 * T04. A send that fails on the spot leaves nothing in the conversation.
 *
 * The module already notes the refusals it decides itself: an inactive
 * channel, an erased contact, a missing phone number, an attachment that no
 * longer resolves. What it did not note is every refusal that comes back from
 * Meta or from its own limits: the window had closed, the file was too big,
 * the upload was rejected, the send was rejected, the template was not
 * configured, the retries ran out.
 *
 * In all of those the agent has written a reply and pressed send. FreeScout
 * shows the thread exactly as it shows a delivered one. A log line on the
 * server is not a trace the person who needs it will ever see.
 */
class OutboundFailureNoteTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeReply(WhatsAppAccount $account, string $mime = 'image/jpeg', int $size = 1024): array
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
        $thread->body               = '<p>hola</p>';
        $thread->source_via         = Thread::PERSON_USER;
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id        = $customer->id;
        $thread->created_by_user_id = 1;
        $thread->save();

        $attachment = \App\Attachment::create(
            'photo.jpg',
            $mime,
            \App\Attachment::typeNameToInt('image'),
            'fake-bytes',
            '',
            false,
            $thread->id
        );
        $attachment->size = $size;
        $attachment->save();

        return [$conversation, $thread, $attachment];
    }

    protected function openWindow(WhatsAppAccount $account, Thread $thread): void
    {
        WhatsAppMessage::create([
            'wamid'           => 'wamid.inbound-' . uniqid(),
            'account_id'      => $account->id,
            'conversation_id' => $thread->conversation_id,
            'contact_phone'   => '+34611222333',
            'direction'       => WhatsAppMessage::DIRECTION_INBOUND,
            'status'          => WhatsAppMessage::STATUS_RECEIVED,
        ]);
    }

    protected function refusalNote(Conversation $conversation): ?Thread
    {
        return Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->where('body', 'like', OutboundGuard::NOTE_MARKER . '%')
            ->first();
    }

    protected function mediaJob(WhatsAppAccount $account, Thread $thread, $attachment, array $upload, ?array $send = null)
    {
        $client = \Mockery::mock(\Modules\MetaWhatsApp\Services\WhatsAppApiClient::class);
        $client->shouldReceive('uploadMedia')->andReturn($upload);
        if ($send !== null) {
            $client->shouldReceive('sendMedia')->andReturn($send);
        }
        $client->shouldReceive('markAsRead')->andReturn(['ok' => true, 'http_status' => 200]);

        $job = \Mockery::mock(SendWhatsAppMedia::class, [$account->id, $thread->id, '+34611222333', $attachment->id, 'hola'])
            ->makePartial();
        $job->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('apiClient')->andReturn($client);

        return $job;
    }

    // --------------------------------------------------- Meta says no

    public function test_a_send_meta_rejects_leaves_a_note_naming_the_error()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        $this->mediaJob(
            $account,
            $thread,
            $attachment,
            ['ok' => true, 'media_id' => 'm1', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false],
            ['ok' => false, 'wamid' => null, 'http_status' => 400, 'error_code' => 131026, 'error_message' => 'Receiver incapable', 'transient' => false]
        )->handle();

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note, 'Meta refused the send and the conversation says nothing');
        $this->assertStringContainsString('131026', $note->body, 'the note does not say what Meta answered');
        $this->assertNotNull($note->created_by_user_id, 'an authorless note breaks the core print view (#55)');
    }

    public function test_an_upload_meta_rejects_leaves_a_note()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        $this->mediaJob(
            $account,
            $thread,
            $attachment,
            ['ok' => false, 'media_id' => null, 'http_status' => 400, 'error_code' => 131053, 'error_message' => 'Media upload error', 'transient' => false]
        )->handle();

        $this->assertNotNull($this->refusalNote($conversation), 'the upload was refused and the conversation says nothing');
    }

    public function test_a_text_reply_meta_rejects_leaves_a_note()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        $client = \Mockery::mock(\Modules\MetaWhatsApp\Services\WhatsAppApiClient::class);
        $client->shouldReceive('sendText')->andReturn(
            ['ok' => false, 'wamid' => null, 'http_status' => 400, 'error_code' => 131026, 'error_message' => 'Receiver incapable', 'transient' => false]
        );
        $client->shouldReceive('markAsRead')->andReturn(['ok' => true, 'http_status' => 200]);

        $job = \Mockery::mock(SendWhatsAppMessage::class, [$account->id, $thread->id, '+34611222333'])->makePartial();
        $job->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('apiClient')->andReturn($client);
        $job->handle();

        $this->assertNotNull($this->refusalNote($conversation), 'the text reply was refused and the conversation says nothing');
    }

    // --------------------------------------------- our own limits

    public function test_a_file_too_big_for_whatsapp_leaves_a_note()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread, $attachment] = $this->makeReply($account, 'image/jpeg', 99 * 1024 * 1024);
        $this->openWindow($account, $thread);

        $this->mediaJob($account, $thread, $attachment, ['ok' => true, 'media_id' => 'm1', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false])
            ->handle();

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note, 'the file was over the limit and the conversation says nothing');
        $this->assertStringNotContainsString('131047', $note->body, 'the note blames the window for a size problem');
    }

    public function test_a_closed_window_leaves_a_note_that_says_so()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread, $attachment] = $this->makeReply($account);
        // No inbound message at all: the window was never open.

        $this->mediaJob($account, $thread, $attachment, ['ok' => true, 'media_id' => 'm1', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false])
            ->handle();

        $this->assertNotNull($this->refusalNote($conversation), 'the window was closed and the conversation says nothing');
    }

    // ------------------------------------------- the retries ran out

    public function test_a_job_that_gave_up_leaves_a_note()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeReply($account);

        $job = new SendWhatsAppMessage($account->id, $thread->id, '+34611222333');
        $job->failed(new \RuntimeException('network is gone'));

        $this->assertNotNull($this->refusalNote($conversation), 'the job gave up and the conversation says nothing');
    }

    // ------------------------------------------------ what must not change

    /**
     * A reply carrying three files dispatches three jobs. Three identical
     * notes would be worse than none, which is what OutboundGuard's
     * de-duplication is for, and adding notes to these paths must not get
     * around it.
     */
    public function test_three_failing_attachments_of_one_reply_leave_one_note()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread, $first] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        $others = [];
        foreach (['b.jpg', 'c.jpg'] as $name) {
            $extra = \App\Attachment::create($name, 'image/jpeg', \App\Attachment::typeNameToInt('image'), 'fake', '', false, $thread->id);
            $extra->size = 1024;
            $extra->save();
            $others[] = $extra;
        }

        foreach (array_merge([$first], $others) as $attachment) {
            $this->mediaJob(
                $account,
                $thread,
                $attachment,
                ['ok' => true, 'media_id' => 'm', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false],
                ['ok' => false, 'wamid' => null, 'http_status' => 400, 'error_code' => 131026, 'error_message' => 'no', 'transient' => false]
            )->handle();
        }

        $notes = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->where('body', 'like', OutboundGuard::NOTE_MARKER . '%')
            ->count();

        $this->assertEquals(1, $notes, 'one reply left more than one refusal note');
    }

    /**
     * An agent who replies and closes in one action is not looking at the
     * conversation any more. A refusal on a closed conversation has to bring
     * it back, the same way an asynchronous delivery failure does (#19).
     */
    public function test_a_refusal_reopens_a_conversation_the_agent_had_closed()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        $conversation->setStatus(Conversation::STATUS_CLOSED);
        $conversation->save();

        $this->mediaJob(
            $account,
            $thread,
            $attachment,
            ['ok' => true, 'media_id' => 'm1', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false],
            ['ok' => false, 'wamid' => null, 'http_status' => 400, 'error_code' => 131026, 'error_message' => 'no', 'transient' => false]
        )->handle();

        $this->assertEquals(
            Conversation::STATUS_ACTIVE,
            (int) $conversation->fresh()->status,
            'the reply failed and the conversation stayed closed, where nobody would see the note'
        );
    }

    /**
     * The review's finding, and the test written with it encoded the same
     * mistake: it passed a four-character caption, which never travels
     * separately, and then asserted the note no longer said the whole reply
     * was lost.
     *
     * A reply whose text rides the first attachment loses everything when
     * that attachment does, so "nothing arrived" is the honest note there.
     */
    public function test_a_file_carrying_the_reply_text_says_nothing_arrived()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread, $attachment] = $this->makeReply($account);

        $job = new SendWhatsAppMedia($account->id, $thread->id, '+34611222333', $attachment->id, 'hola');
        $job->failed(new \RuntimeException('network is gone'));

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note);
        $this->assertStringContainsString(
            'Nothing was sent to WhatsApp',
            $note->body,
            'the text was riding this file, so claiming it may have arrived is false'
        );
    }

    /** And when the text did travel on its own, only the file is reported. */
    public function test_a_file_whose_text_already_went_reports_only_the_file()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread, $attachment] = $this->makeReply($account);

        // No caption: the dispatcher sent the reply text as its own message,
        // which is what it does for an audio first attachment or a text
        // longer than Meta allows in a caption.
        $job = new SendWhatsAppMedia($account->id, $thread->id, '+34611222333', $attachment->id, null);
        $job->failed(new \RuntimeException('network is gone'));

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note);
        $this->assertStringNotContainsString('Nothing was sent to WhatsApp', $note->body);
        $this->assertStringContainsString('file', $note->body);
    }

    /** A text reply that gave up still says the whole reply was lost. */
    public function test_a_text_reply_that_gave_up_still_says_nothing_arrived()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeReply($account);

        $job = new SendWhatsAppMessage($account->id, $thread->id, '+34611222333');
        $job->failed(new \RuntimeException('network is gone'));

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note);
        $this->assertStringContainsString('Nothing was sent to WhatsApp', $note->body);
    }

    /**
     * The review's other finding. The note and the reopening answer
     * different questions, so the reopening must not sit behind the
     * one-note-per-reply guard: a second file failing on a conversation the
     * agent closed in between would keep the note it already had and lose
     * the only thing that puts it back in front of anybody.
     */
    public function test_a_second_failure_of_the_same_reply_still_reopens_the_conversation()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread, $first] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        $second = \App\Attachment::create('b.jpg', 'image/jpeg', \App\Attachment::typeNameToInt('image'), 'fake', '', false, $thread->id);
        $second->size = 1024;
        $second->save();

        $this->mediaJob($account, $thread, $first, $this->okUpload(), $this->metaRefusal())->handle();
        $this->assertNotNull($this->refusalNote($conversation), 'the first failure left no note');

        // The agent reads the note and closes the conversation again.
        $conversation->setStatus(\App\Conversation::STATUS_CLOSED);
        $conversation->save();

        $this->mediaJob($account, $thread, $second, $this->okUpload(), $this->metaRefusal())->handle();

        $this->assertEquals(
            \App\Conversation::STATUS_ACTIVE,
            (int) $conversation->fresh()->status,
            'the second file failed and the conversation stayed closed'
        );
    }

    protected function okUpload(): array
    {
        return ['ok' => true, 'media_id' => 'm1', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false];
    }

    protected function metaRefusal(): array
    {
        return ['ok' => false, 'wamid' => null, 'http_status' => 400, 'error_code' => 131026, 'error_message' => 'no', 'transient' => false];
    }

    // --------------------------------------- the third review round

    /**
     * The two paths that refuse and then send the text wrote the whole-reply
     * note, because the flag was read before the dispatch. The agent was
     * told nothing had arrived while the text was already on its way, and
     * the one-note-per-reply rule means that is the only note they get: the
     * natural answer is to send the whole reply again, and the customer
     * reads it twice.
     */
    public function test_a_refusal_that_sends_the_text_on_its_own_reports_only_the_file()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [$conversation, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        $this->mediaJob($account, $thread, $attachment, $this->okUpload(), $this->metaRefusal())->handle();

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note);
        Queue::assertPushed(SendWhatsAppMessage::class);
        // Asserting the absence of the whole-reply wording is not enough: the
        // key for a Meta refusal of a whole reply does not contain that
        // sentence either, so the first version of this test passed against
        // the defect. It has to name the note it expects.
        $this->assertStringContainsString(
            'A file was not sent to WhatsApp',
            $note->body,
            'the note speaks about the whole reply while the text was being sent on its own'
        );
    }

    /**
     * And with two files, the one that is not carrying the text can fail
     * first. Its note is true about the file, and the truthful note about the
     * whole reply must not be suppressed by it.
     */
    public function test_losing_the_whole_reply_overrides_an_earlier_note_about_a_file()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [$conversation, $thread, $first] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        $second = \App\Attachment::create('b.jpg', 'image/jpeg', \App\Attachment::typeNameToInt('image'), 'fake', '', false, $thread->id);
        $second->size = 1024;
        $second->save();

        // The second attachment carries no caption, so its failure costs the
        // file alone.
        $job = \Mockery::mock(SendWhatsAppMedia::class, [$account->id, $thread->id, '+34611222333', $second->id, null])
            ->makePartial();
        $job->shouldAllowMockingProtectedMethods();
        $client = \Mockery::mock(\Modules\MetaWhatsApp\Services\WhatsAppApiClient::class);
        $client->shouldReceive('uploadMedia')->andReturn($this->okUpload());
        $client->shouldReceive('sendMedia')->andReturn($this->metaRefusal());
        $client->shouldReceive('markAsRead')->andReturn(['ok' => true, 'http_status' => 200]);
        $job->shouldReceive('apiClient')->andReturn($client);
        $job->handle();

        $this->assertNotNull($this->refusalNote($conversation), 'the first failure left no note');

        // The first attachment was carrying the text and exhausts its
        // retries, which sends nothing at all.
        $carrier = new SendWhatsAppMedia($account->id, $thread->id, '+34611222333', $first->id, 'hola');
        $carrier->failed(new \RuntimeException('network is gone'));

        $notes = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->where('body', 'like', OutboundGuard::NOTE_MARKER . '%')
            ->get();

        $this->assertCount(2, $notes, 'the note that says the whole reply was lost was suppressed');
        $this->assertTrue(
            $notes->contains(function ($note) {
                return strpos($note->body, 'Nothing was sent to WhatsApp') !== false;
            }),
            'neither note says the whole reply was lost'
        );
    }

    // -------------------------------------- the fourth review round

    /**
     * The commonest refusal of all reports itself twice, once from the guard
     * that decides the channel cannot send and once from the job recording
     * the failure, both with the same key. An override added in the round
     * before let the second one through, so the agent read the same sentence
     * twice. De-duplicating per reply and per reason is what settles it.
     */
    public function test_an_inactive_channel_leaves_exactly_one_note()
    {
        $account = $this->createTestAccount(['is_active' => false]);
        [$conversation, $thread] = $this->makeReply($account);

        (new SendWhatsAppMessage($account->id, $thread->id, '+34611222333'))->handle();

        $notes = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->where('body', 'like', OutboundGuard::NOTE_MARKER . '%')
            ->get();

        $this->assertCount(1, $notes, 'the agent reads the same refusal twice');
    }

    /** The note carries the status the conversation ends up in, not the one it had. */
    public function test_a_note_that_reopens_the_conversation_is_not_labelled_closed()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        $conversation->setStatus(\App\Conversation::STATUS_CLOSED);
        $conversation->save();

        $this->mediaJob($account, $thread, $attachment, $this->okUpload(), $this->metaRefusal())->handle();

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note);
        $this->assertEquals(
            \App\Conversation::STATUS_ACTIVE,
            (int) $note->status,
            'the core prints this status beside the note, so a reopening left no visible trace'
        );
    }

    /**
     * A file refused because the window shut, when the reply's text had
     * already travelled on its own, must not claim the customer received
     * nothing.
     */
    public function test_a_file_refused_for_a_shut_window_does_not_claim_the_text_was_lost()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);
        WhatsAppMessage::where('conversation_id', $thread->conversation_id)
            ->update(['created_at' => now()->subHours(40), 'meta_sent_at' => now()->subHours(40)]);

        // No caption: the dispatcher sent the reply text as its own message.
        $job = \Mockery::mock(SendWhatsAppMedia::class, [$account->id, $thread->id, '+34611222333', $attachment->id, null])
            ->makePartial();
        $job->shouldAllowMockingProtectedMethods();
        $job->handle();

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note);
        $this->assertStringContainsString('A file was not sent to WhatsApp', $note->body);
    }
}
