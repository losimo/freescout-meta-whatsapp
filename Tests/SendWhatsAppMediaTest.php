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
 * Guards del job outbound de multimèdia: mateix esperit que
 * SendWhatsAppMessageTest — els camins de xarxa es verifiquen aquí amb
 * el seam apiClient() mockejat, mai contra graph.facebook.com real.
 */
class SendWhatsAppMediaTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeConversationWithThreadAndAttachment(WhatsAppAccount $account, int $threadType, int $threadState, string $mime = 'image/jpeg'): array
    {
        $customer = new Customer();
        $customer->first_name = '+34611222333';
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
        $thread->type               = $threadType;
        $thread->status             = $conversation->status;
        $thread->state              = $threadState;
        $thread->body               = '<p>resposta de prova</p>';
        $thread->source_via         = Thread::PERSON_USER;
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id        = $customer->id;
        $thread->created_by_user_id = 1;
        $thread->save();

        $attachment = \App\Attachment::create('photo.jpg', $mime, \App\Attachment::typeNameToInt('image'), 'fake-bytes', '', false, $thread->id);

        return [$thread, $attachment];
    }

    protected function markWindowOpen(WhatsAppAccount $account, $thread)
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

    public function test_compte_inactiu_no_envia_ni_crea_fila()
    {
        $account = $this->createTestAccount(['is_active' => false]);
        [$thread, $attachment] = $this->makeConversationWithThreadAndAttachment($account, Thread::TYPE_MESSAGE, Thread::STATE_PUBLISHED);

        (new SendWhatsAppMedia($account->id, $thread->id, '+34611222333', $attachment->id))->handle();

        $this->assertEquals(0, WhatsAppMessage::where('attachment_id', $attachment->id)->count());
    }

    public function test_idempotencia_per_adjunt_curtcircuita_abans_del_http()
    {
        $account = $this->createTestAccount();
        [$thread, $attachment] = $this->makeConversationWithThreadAndAttachment($account, Thread::TYPE_MESSAGE, Thread::STATE_PUBLISHED);

        WhatsAppMessage::create([
            'wamid'           => 'wamid.ja-enviat-media',
            'account_id'      => $account->id,
            'conversation_id' => $thread->conversation_id,
            'thread_id'       => $thread->id,
            'attachment_id'   => $attachment->id,
            'contact_phone'   => '+34611222333',
            'direction'       => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status'          => WhatsAppMessage::STATUS_SENT,
        ]);

        (new SendWhatsAppMedia($account->id, $thread->id, '+34611222333', $attachment->id))->handle();

        $this->assertEquals(1, WhatsAppMessage::where('attachment_id', $attachment->id)->count());
    }

    public function test_finestra_tancada_bloqueja_enviament_i_registra_fallada()
    {
        $account = $this->createTestAccount();
        [$thread, $attachment] = $this->makeConversationWithThreadAndAttachment($account, Thread::TYPE_MESSAGE, Thread::STATE_PUBLISHED);

        // Sense cap inbound: windowExpired() torna true.
        (new SendWhatsAppMedia($account->id, $thread->id, '+34611222333', $attachment->id))->handle();

        $failed = WhatsAppMessage::where('attachment_id', $attachment->id)
            ->where('status', WhatsAppMessage::STATUS_FAILED)
            ->first();
        $this->assertNotNull($failed);
        $this->assertEquals('window_expired', $failed->error_code);
    }

    public function test_fitxer_massa_gran_bloqueja_enviament_i_registra_fallada()
    {
        $account = $this->createTestAccount();
        [$thread, $attachment] = $this->makeConversationWithThreadAndAttachment($account, Thread::TYPE_MESSAGE, Thread::STATE_PUBLISHED);
        $this->markWindowOpen($account, $thread);

        $attachment->size = 6 * 1024 * 1024; // supera el límit de 5MB per a imatge
        $attachment->save();

        (new SendWhatsAppMedia($account->id, $thread->id, '+34611222333', $attachment->id))->handle();

        $failed = WhatsAppMessage::where('attachment_id', $attachment->id)
            ->where('status', WhatsAppMessage::STATUS_FAILED)
            ->first();
        $this->assertNotNull($failed);
        $this->assertEquals('size_exceeded', $failed->error_code);
    }

    public function test_pujada_i_enviament_correctes_creen_fila_sent()
    {
        $account = $this->createTestAccount();
        [$thread, $attachment] = $this->makeConversationWithThreadAndAttachment($account, Thread::TYPE_MESSAGE, Thread::STATE_PUBLISHED);
        $this->markWindowOpen($account, $thread);

        $fakeClient = \Mockery::mock(WhatsAppApiClient::class);
        $fakeClient->shouldReceive('uploadMedia')
            ->once()
            ->andReturn(['ok' => true, 'media_id' => 'meta-media-1', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false]);
        $fakeClient->shouldReceive('sendMedia')
            ->once()
            ->with('+34611222333', 'image', 'meta-media-1', 'hola', null)
            ->andReturn(['ok' => true, 'wamid' => 'wamid.sent-media-1', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false]);

        $job = \Mockery::mock(SendWhatsAppMedia::class, [$account->id, $thread->id, '+34611222333', $attachment->id, 'hola'])->makePartial();
        $job->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('apiClient')->andReturn($fakeClient);
        $job->handle();

        $sent = WhatsAppMessage::where('attachment_id', $attachment->id)->first();
        $this->assertEquals(WhatsAppMessage::STATUS_SENT, $sent->status);
        $this->assertEquals('wamid.sent-media-1', $sent->wamid);
    }

    public function test_mediaCategory_mapa_prefixos_de_mime_correctament()
    {
        $this->assertEquals('image', SendWhatsAppMedia::mediaCategory('image/jpeg'));
        $this->assertEquals('video', SendWhatsAppMedia::mediaCategory('video/mp4'));
        $this->assertEquals('audio', SendWhatsAppMedia::mediaCategory('audio/ogg'));
        $this->assertEquals('document', SendWhatsAppMedia::mediaCategory('application/pdf'));
        $this->assertEquals('document', SendWhatsAppMedia::mediaCategory('text/plain'));
    }
}
