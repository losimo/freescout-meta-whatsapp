<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Modules\MetaWhatsApp\Jobs\SendWhatsAppMessage;
use Modules\MetaWhatsApp\Jobs\SendWhatsAppTemplate;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * Meta bills each outbound message by category, and the monthly service
 * allowance only counts one of them. The table has to say which.
 */
class MessageCategoryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_messages_table_records_the_billing_category()
    {
        $this->assertTrue(
            Schema::hasColumn('meta_whatsapp_messages', 'category'),
            'meta_whatsapp_messages needs a category column to tell a service message from a template.'
        );
    }

    public function test_an_outbound_reply_is_recorded_as_a_service_message()
    {
        $account = $this->createTestAccount();
        $thread  = $this->makeOutboundThread($account);

        $fakeClient = \Mockery::mock(\Modules\MetaWhatsApp\Services\WhatsAppApiClient::class);
        $fakeClient->shouldReceive('sendText')
            ->once()
            ->andReturn(['ok' => true, 'wamid' => 'wamid.reply', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false]);
        $fakeClient->shouldReceive('markAsRead')->andReturn(['ok' => true]);

        $job = \Mockery::mock(SendWhatsAppMessage::class, [$account->id, $thread->id, '+34611222333'])->makePartial();
        $job->shouldAllowMockingProtectedMethods();
        $job->shouldReceive('apiClient')->andReturn($fakeClient);
        $job->handle();

        $this->assertEquals(
            WhatsAppMessage::CATEGORY_SERVICE,
            WhatsAppMessage::where('wamid', 'wamid.reply')->value('category')
        );
    }

    public function test_an_outbound_template_is_recorded_as_a_template_and_not_as_service()
    {
        $account = $this->createTestAccount();
        $thread  = $this->makeOutboundThread($account);

        $fakeClient = \Mockery::mock(\Modules\MetaWhatsApp\Services\WhatsAppApiClient::class);
        $fakeClient->shouldReceive('sendTemplate')
            ->once()
            ->andReturn(['ok' => true, 'wamid' => 'wamid.tpl', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false]);
        $fakeClient->shouldReceive('markAsRead')->andReturn(['ok' => true]);

        $job = \Mockery::mock(SendWhatsAppTemplate::class, [$account->id, $thread->id, '+34611222333', 'hello_world', 'en_US', []])->makePartial();
        $job->shouldAllowMockingProtectedMethods();
        // This job's seam is makeClient(), not apiClient() like the others.
        $job->shouldReceive('makeClient')->andReturn($fakeClient);
        $job->handle();

        $this->assertEquals(
            WhatsAppMessage::CATEGORY_TEMPLATE,
            WhatsAppMessage::where('wamid', 'wamid.tpl')->value('category')
        );
    }

    /**
     * A conversation with an inbound message recent enough for the customer
     * window to be open, so the outbound job reaches the API call.
     */
    protected function makeOutboundThread(WhatsAppAccount $account): Thread
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

        // Inbound row inside the 24h window, so the outbound guard lets it through.
        WhatsAppMessage::create([
            'wamid'           => 'wamid.inbound-' . mt_rand(100000, 999999),
            'account_id'      => $account->id,
            'conversation_id' => $conversation->id,
            'contact_phone'   => '+34611222333',
            'direction'       => WhatsAppMessage::DIRECTION_INBOUND,
            'status'          => WhatsAppMessage::STATUS_RECEIVED,
        ]);

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->user_id         = 1;
        $thread->type            = Thread::TYPE_MESSAGE;
        $thread->status          = $conversation->status;
        $thread->state           = Thread::STATE_PUBLISHED;
        $thread->body            = 'Hello';
        $thread->source_via      = Thread::PERSON_USER;
        $thread->source_type     = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id     = $customer->id;
        $thread->created_by_user_id = 1;
        $thread->save();

        return $thread;
    }
}
