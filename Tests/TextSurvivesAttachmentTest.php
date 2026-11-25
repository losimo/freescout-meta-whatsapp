<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Modules\MetaWhatsApp\Jobs\SendWhatsAppMedia;
use Modules\MetaWhatsApp\Jobs\SendWhatsAppMessage;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

/**
 * T06, and the caption half of T07. The agent's words travel as the caption
 * of the first attachment, so when the file does not go out, neither do they.
 *
 * Two ways into the same silence: Meta refuses the file, or the text is longer
 * than the 1024 characters Meta allows in a caption and the whole message is
 * refused for it. One rule covers both: when the text cannot ride the file, it
 * goes on its own.
 *
 * The exception is the closed window, and it is not a detail. The text job has
 * no window check of its own, so dispatching it from there would guarantee a
 * 131047 and turn one silent failure into two.
 */
class TextSurvivesAttachmentTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeReply(WhatsAppAccount $account, int $size = 1024): array
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
        $thread->body               = '<p>here is the file you asked for</p>';
        $thread->source_via         = Thread::PERSON_USER;
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id        = $customer->id;
        $thread->created_by_user_id = 1;
        $thread->save();

        $attachment = \App\Attachment::create(
            'photo.jpg',
            'image/jpeg',
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

    protected function job(WhatsAppAccount $account, Thread $thread, $attachment, ?string $caption, ?array $upload = null, ?array $send = null)
    {
        $client = \Mockery::mock(WhatsAppApiClient::class);
        if ($upload !== null) {
            $client->shouldReceive('uploadMedia')->andReturn($upload);
        }
        if ($send !== null) {
            $client->shouldReceive('sendMedia')->andReturn($send);
        }
        $client->shouldReceive('markAsRead')->andReturn(['ok' => true, 'http_status' => 200]);

        $job = \Mockery::mock(SendWhatsAppMedia::class, [$account->id, $thread->id, '+34611222333', $attachment->id, $caption])
            ->makePartial();
        $job->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('apiClient')->andReturn($client);

        return [$job, $client];
    }

    protected function ok(string $wamid = 'wamid.ok'): array
    {
        return ['ok' => true, 'wamid' => $wamid, 'media_id' => 'm1', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false];
    }

    protected function refused(int $code = 131026): array
    {
        return ['ok' => false, 'wamid' => null, 'media_id' => null, 'http_status' => 400, 'error_code' => $code, 'error_message' => 'refused', 'transient' => false];
    }

    // ------------------------------------------------- the file does not go

    public function test_the_reply_text_goes_on_its_own_when_meta_rejects_the_file()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        [$job] = $this->job($account, $thread, $attachment, 'here is the file you asked for', $this->ok(), $this->refused());
        $job->handle();

        Queue::assertPushed(SendWhatsAppMessage::class);
    }

    public function test_a_file_over_the_size_limit_still_lets_the_text_through()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [, $thread, $attachment] = $this->makeReply($account, 99 * 1024 * 1024);
        $this->openWindow($account, $thread);

        [$job] = $this->job($account, $thread, $attachment, 'here is the file you asked for');
        $job->handle();

        Queue::assertPushed(SendWhatsAppMessage::class);
    }

    public function test_an_upload_meta_rejects_still_lets_the_text_through()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        [$job] = $this->job($account, $thread, $attachment, 'here is the file you asked for', $this->refused(131053));
        $job->handle();

        Queue::assertPushed(SendWhatsAppMessage::class);
    }

    // --------------------------------------------- the window is closed

    /**
     * The one place the rule must not apply. SendWhatsAppMessage has no
     * window check, so this would be a guaranteed 131047 on top of the
     * refusal that already happened.
     */
    public function test_a_closed_window_does_not_send_the_text_on_its_own()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [, $thread, $attachment] = $this->makeReply($account);
        // No inbound message: the window was never open.

        [$job] = $this->job($account, $thread, $attachment, 'here is the file you asked for');
        $job->handle();

        Queue::assertNotPushed(SendWhatsAppMessage::class);
    }

    // ------------------------------------- the caption is too long (T07)

    public function test_a_caption_meta_accepts_still_rides_the_file()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        $client = \Mockery::mock(WhatsAppApiClient::class);
        $client->shouldReceive('uploadMedia')->andReturn($this->ok());
        $client->shouldReceive('sendMedia')
            ->once()
            ->with('+34611222333', 'image', 'm1', 'short enough', null)
            ->andReturn($this->ok('wamid.caption'));
        $client->shouldReceive('markAsRead')->andReturn(['ok' => true, 'http_status' => 200]);

        $job = \Mockery::mock(SendWhatsAppMedia::class, [$account->id, $thread->id, '+34611222333', $attachment->id, 'short enough'])
            ->makePartial();
        $job->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('apiClient')->andReturn($client);
        $job->handle();

        Queue::assertNotPushed(SendWhatsAppMessage::class);
    }

    // ------------------------------------------------ what must not change

    public function test_a_file_with_no_text_dispatches_nothing_extra()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        [$job] = $this->job($account, $thread, $attachment, null, $this->ok(), $this->refused());
        $job->handle();

        Queue::assertNotPushed(SendWhatsAppMessage::class);
    }

    /**
     * The reply with an audio file first already sends its text separately
     * from the dispatcher, so the text row is there before this job runs.
     * Sending it again would deliver the same words twice.
     */
    public function test_the_text_is_not_sent_again_when_it_already_went_on_its_own()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        WhatsAppMessage::create([
            'wamid'           => 'wamid.text-already',
            'account_id'      => $account->id,
            'conversation_id' => $thread->conversation_id,
            'thread_id'       => $thread->id,
            'contact_phone'   => '+34611222333',
            'direction'       => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status'          => WhatsAppMessage::STATUS_SENT,
        ]);

        [$job] = $this->job($account, $thread, $attachment, 'here is the file you asked for', $this->ok(), $this->refused());
        $job->handle();

        Queue::assertNotPushed(SendWhatsAppMessage::class);
    }

    // ------------------------------------------- what the review found

    /**
     * The window clocks can disagree with Meta's. windowExpired() measures
     * against template_threshold_minutes, which an administrator can set to
     * anything, so the local check can pass while Meta answers 131047. The
     * text job has no window check at all, so dispatching it then would be a
     * second guaranteed refusal, which is exactly what the rule about the
     * expired-window branch exists to prevent.
     */
    public function test_a_131047_from_meta_does_not_send_the_text_on_its_own()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        $account->template_threshold_minutes = 4000;
        $account->save();

        [, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);
        // The inbound message is old enough for Meta, not for our threshold.
        WhatsAppMessage::where('conversation_id', $thread->conversation_id)
            ->update(['created_at' => now()->subHours(40), 'meta_sent_at' => now()->subHours(40)]);

        [$job] = $this->job($account, $thread, $attachment, 'here is the file you asked for', $this->ok(), $this->refused(131047));
        $job->handle();

        Queue::assertNotPushed(SendWhatsAppMessage::class);
    }

    /**
     * The length rule belongs to the dispatcher, with the audio rule, and
     * not inside the job. There it ran before the upload, so a transient
     * error brought the worker back with the caption restored from the
     * serialized payload: the text could go out twice, or, if the first
     * attempt died before reaching the check, never. Both were the review's
     * finding. Here it is decided once per reply, with no retry around it.
     */
    public function test_a_long_reply_is_split_by_the_dispatcher_and_not_by_the_job()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [$conversation, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        $thread->body = '<p>' . str_repeat('d', SendWhatsAppMedia::MAX_CAPTION + 1) . '</p>';
        $thread->save();

        $provider = new \Modules\MetaWhatsApp\Providers\MetaWhatsAppServiceProvider($this->app);
        $method   = new \ReflectionMethod($provider, 'handleOutboundReplies');
        $method->setAccessible(true);
        $method->invoke($provider, $conversation, [$thread]);

        Queue::assertPushed(SendWhatsAppMessage::class, 1);
        Queue::assertPushed(SendWhatsAppMedia::class, function ($job) {
            $caption = (new \ReflectionClass($job))->getProperty('caption');
            $caption->setAccessible(true);

            return $caption->getValue($job) === null;
        });
    }

    /** A reply Meta would accept as a caption still rides the file. */
    public function test_a_reply_within_the_limit_still_rides_the_file()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [$conversation, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        $provider = new \Modules\MetaWhatsApp\Providers\MetaWhatsAppServiceProvider($this->app);
        $method   = new \ReflectionMethod($provider, 'handleOutboundReplies');
        $method->setAccessible(true);
        $method->invoke($provider, $conversation, [$thread]);

        Queue::assertNotPushed(SendWhatsAppMessage::class);
        Queue::assertPushed(SendWhatsAppMedia::class, function ($job) {
            $caption = (new \ReflectionClass($job))->getProperty('caption');
            $caption->setAccessible(true);

            return $caption->getValue($job) === 'here is the file you asked for';
        });
    }

    /**
     * The attachment row no longer resolves, so the file cannot go and the
     * text riding it would have gone nowhere either. The note on that path
     * has always said the text may have gone out on its own; this is what
     * makes that true.
     */
    public function test_a_vanished_attachment_still_lets_the_text_through()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);

        $attachmentId = $attachment->id;
        $attachment->delete();

        $job = \Mockery::mock(SendWhatsAppMedia::class, [$account->id, $thread->id, '+34611222333', $attachmentId, 'here is the file you asked for'])
            ->makePartial();
        $job->shouldAllowMockingProtectedMethods();
        $job->handle();

        Queue::assertPushed(SendWhatsAppMessage::class);
    }

    /**
     * With the window shut the split still happens, and that is a decision
     * the fourth review round changed. Checking the window here instead
     * takes a snapshot in the request: nothing would be queued, and if the
     * customer wrote in before the media job ran, the file would go out and
     * the agent's words would be dropped with nothing to say so. It would
     * also make the module stricter than Meta on an administrator's own
     * margin. Meta decides, its refusal leaves a note, and the note is not
     * written twice because the de-duplication is per reply and per reason.
     */
    public function test_a_long_reply_is_still_split_once_the_window_has_closed()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [$conversation, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);
        WhatsAppMessage::where('conversation_id', $thread->conversation_id)
            ->update(['created_at' => now()->subHours(40), 'meta_sent_at' => now()->subHours(40)]);

        $thread->body = '<p>' . str_repeat('e', SendWhatsAppMedia::MAX_CAPTION + 1) . '</p>';
        $thread->save();

        $provider = new \Modules\MetaWhatsApp\Providers\MetaWhatsAppServiceProvider($this->app);
        $method   = new \ReflectionMethod($provider, 'handleOutboundReplies');
        $method->setAccessible(true);
        $method->invoke($provider, $conversation, [$thread]);

        Queue::assertPushed(SendWhatsAppMessage::class);
    }

    /**
     * But the job itself still asks the window before anything is sent on
     * its own, and it asks before resolving the attachment, so the branch
     * for a vanished attachment cannot dispatch into a shut window.
     */
    public function test_a_vanished_attachment_sends_nothing_once_the_window_has_closed()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        [$conversation, $thread, $attachment] = $this->makeReply($account);
        $this->openWindow($account, $thread);
        WhatsAppMessage::where('conversation_id', $thread->conversation_id)
            ->update(['created_at' => now()->subHours(40), 'meta_sent_at' => now()->subHours(40)]);

        $attachmentId = $attachment->id;
        $attachment->delete();

        $job = \Mockery::mock(SendWhatsAppMedia::class, [$account->id, $thread->id, '+34611222333', $attachmentId, 'here is the file you asked for'])
            ->makePartial();
        $job->shouldAllowMockingProtectedMethods();
        $job->handle();

        Queue::assertNotPushed(SendWhatsAppMessage::class);
    }
}
