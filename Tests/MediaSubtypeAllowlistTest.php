<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Jobs\SendWhatsAppMedia;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

/**
 * T09. The category was decided by mime prefix alone, so any audio, video or
 * image went out claiming to be of that kind whether WhatsApp accepts the
 * subtype or not. Meta then refused it, which until this batch was silent.
 *
 * The subtypes are Meta's own, from our dated copy of
 * business-phone-numbers_media.md (2026-11-22). A subtype outside the list
 * goes as a document, which WhatsApp does accept, so the file arrives instead
 * of being refused.
 */
class MediaSubtypeAllowlistTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_subtypes_meta_accepts_keep_their_category()
    {
        $this->assertEquals('image', SendWhatsAppMedia::mediaCategory('image/jpeg'));
        $this->assertEquals('image', SendWhatsAppMedia::mediaCategory('image/png'));
        $this->assertEquals('image', SendWhatsAppMedia::mediaCategory('image/webp'));
        $this->assertEquals('video', SendWhatsAppMedia::mediaCategory('video/mp4'));
        $this->assertEquals('audio', SendWhatsAppMedia::mediaCategory('audio/aac'));
        $this->assertEquals('audio', SendWhatsAppMedia::mediaCategory('audio/amr'));
        $this->assertEquals('audio', SendWhatsAppMedia::mediaCategory('audio/mpeg'));
        $this->assertEquals('audio', SendWhatsAppMedia::mediaCategory('audio/mp4'));
        $this->assertEquals('audio', SendWhatsAppMedia::mediaCategory('audio/ogg'));
    }

    /**
     * The reported patch wrote video/3gp. Meta's table says video/3gpp, and
     * .3gp is the extension, not the type.
     */
    public function test_the_video_type_meta_names_is_3gpp_and_not_3gp()
    {
        $this->assertEquals('video', SendWhatsAppMedia::mediaCategory('video/3gpp'));
        $this->assertEquals('document', SendWhatsAppMedia::mediaCategory('video/3gp'));
    }

    public function test_a_subtype_meta_does_not_accept_goes_as_a_document()
    {
        $this->assertEquals('document', SendWhatsAppMedia::mediaCategory('image/gif'));
        $this->assertEquals('document', SendWhatsAppMedia::mediaCategory('image/tiff'));
        $this->assertEquals('document', SendWhatsAppMedia::mediaCategory('audio/webm'));
        $this->assertEquals('document', SendWhatsAppMedia::mediaCategory('video/quicktime'));
        $this->assertEquals('document', SendWhatsAppMedia::mediaCategory('application/pdf'));
        $this->assertEquals('document', SendWhatsAppMedia::mediaCategory('text/plain'));
    }

    /**
     * A browser recording arrives as audio/ogg with its codec as a parameter,
     * and Meta accepts exactly that file. Comparing the whole string would
     * have demoted it to a document and changed what the customer receives.
     */
    public function test_the_codec_parameter_does_not_demote_a_type_meta_accepts()
    {
        $this->assertEquals('audio', SendWhatsAppMedia::mediaCategory('audio/ogg; codecs=opus'));
        $this->assertEquals('audio', SendWhatsAppMedia::mediaCategory('AUDIO/OGG;codecs=opus'));
        $this->assertEquals('image', SendWhatsAppMedia::mediaCategory(' image/jpeg '));
    }

    /**
     * The second retouch. A demoted file is a document to Meta, and Meta's
     * limit for a document is 100 MB rather than the 16 MB of the audio it
     * was going to be sent as. Checking the size before the demotion would
     * refuse a file WhatsApp would have taken.
     */
    public function test_a_demoted_file_is_measured_against_the_document_limit()
    {
        $account = $this->createTestAccount();
        [$thread, $attachment] = $this->makeAudioReply($account, 'audio/webm', 20 * 1024 * 1024);
        $this->openWindow($account, $thread);

        $client = \Mockery::mock(WhatsAppApiClient::class);
        $client->shouldReceive('uploadMedia')->andReturn(
            ['ok' => true, 'media_id' => 'm1', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false]
        );
        $client->shouldReceive('sendMedia')
            ->once()
            ->with('+34611222333', 'document', 'm1', \Mockery::any(), 'note.webm')
            ->andReturn(['ok' => true, 'wamid' => 'wamid.doc', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false]);
        $client->shouldReceive('markAsRead')->andReturn(['ok' => true, 'http_status' => 200]);

        $job = \Mockery::mock(SendWhatsAppMedia::class, [$account->id, $thread->id, '+34611222333', $attachment->id, null])
            ->makePartial();
        $job->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('apiClient')->andReturn($client);
        $job->handle();

        $row = WhatsAppMessage::where('attachment_id', $attachment->id)->first();
        $this->assertEquals(WhatsAppMessage::STATUS_SENT, $row->status, 'a 20 MB document was refused against the 16 MB audio limit');
    }

    protected function makeAudioReply(WhatsAppAccount $account, string $mime, int $size): array
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
        $thread->body               = '';
        $thread->source_via         = Thread::PERSON_USER;
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id        = $customer->id;
        $thread->created_by_user_id = 1;
        $thread->save();

        $attachment = \App\Attachment::create(
            'note.webm',
            $mime,
            \App\Attachment::typeNameToInt('audio'),
            'fake-bytes',
            '',
            false,
            $thread->id
        );
        $attachment->size = $size;
        $attachment->save();

        return [$thread, $attachment];
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
}
