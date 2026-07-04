<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

class ProcessInboundWebhookTest extends TestCase
{
    use DatabaseTransactions;

    protected function runJob(WhatsAppAccount $account, array $payload)
    {
        (new ProcessInboundWebhook($account->id, $payload))->handle();
    }

    public function test_missatge_nou_crea_customer_conversa_i_thread()
    {
        $account = $this->createTestAccount();
        $payload = $this->inboundPayload($account, 'wamid.in1', '34611222333', "Hola!\nUn dubte.");

        $this->runJob($account, $payload);

        $msg = WhatsAppMessage::where('wamid', 'wamid.in1')->first();
        $this->assertNotNull($msg);
        $this->assertEquals('+34611222333', $msg->contact_phone);
        $this->assertEquals(WhatsAppMessage::STATUS_RECEIVED, $msg->status);

        $conversation = Conversation::find($msg->conversation_id);
        $this->assertEquals(Conversation::TYPE_CHAT, $conversation->type);
        $this->assertEquals($account->mailbox_id, $conversation->mailbox_id);
        $this->assertEquals(Conversation::STATUS_ACTIVE, $conversation->status);

        $thread = Thread::find($msg->thread_id);
        $this->assertEquals(Thread::TYPE_CUSTOMER, $thread->type);
        $this->assertEquals(Thread::STATE_PUBLISHED, $thread->state);
        $this->assertTrue((bool) $thread->first);
        // El text s'escapa i els salts de línia es preserven.
        $this->assertStringContainsString('Hola!', $thread->body);

        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $this->assertNotNull($customer);
        $this->assertEquals($conversation->customer_id, $customer->id);
    }

    public function test_idempotencia_el_mateix_wamid_no_duplica_res()
    {
        $account = $this->createTestAccount();
        $payload = $this->inboundPayload($account, 'wamid.in2', '34611222333', 'hola');

        $this->runJob($account, $payload);
        $this->runJob($account, $payload);

        $this->assertEquals(1, WhatsAppMessage::where('wamid', 'wamid.in2')->count());
        $msg = WhatsAppMessage::where('wamid', 'wamid.in2')->first();
        $this->assertEquals(1, Thread::where('conversation_id', $msg->conversation_id)->count());
    }

    public function test_segon_missatge_del_mateix_client_va_a_la_mateixa_conversa()
    {
        $account = $this->createTestAccount();

        $this->runJob($account, $this->inboundPayload($account, 'wamid.in3', '34611222333', 'primer'));
        $this->runJob($account, $this->inboundPayload($account, 'wamid.in4', '34611222333', 'segon'));

        $m1 = WhatsAppMessage::where('wamid', 'wamid.in3')->first();
        $m2 = WhatsAppMessage::where('wamid', 'wamid.in4')->first();
        $this->assertEquals($m1->conversation_id, $m2->conversation_id);
        $this->assertEquals(2, Thread::where('conversation_id', $m1->conversation_id)->count());
    }

    public function test_status_actualitza_la_fila_best_effort()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.in5', '34611222333', 'hola'));

        $statusPayload = $this->inboundPayload($account, 'x', 'x', 'x');
        $statusPayload['entry'][0]['changes'][0]['value'] = [
            'messaging_product' => 'whatsapp',
            'metadata'          => ['phone_number_id' => $account->phone_number_id],
            'statuses'          => [[
                'id'     => 'wamid.in5',
                'status' => 'read',
            ]],
        ];
        $this->runJob($account, $statusPayload);

        $this->assertEquals(
            WhatsAppMessage::STATUS_READ,
            WhatsAppMessage::where('wamid', 'wamid.in5')->value('status')
        );
    }

    public function test_tipus_no_suportat_es_descarta_sense_efectes()
    {
        $account = $this->createTestAccount();
        $payload = $this->inboundPayload($account, 'wamid.in6', '34611222333', 'x');
        $payload['entry'][0]['changes'][0]['value']['messages'][0] = [
            'from'      => '34611222333',
            'id'        => 'wamid.in6',
            'timestamp' => (string) time(),
            'type'      => 'image',
            'image'     => ['id' => 'img1', 'mime_type' => 'image/jpeg'],
        ];

        $before = Conversation::where('mailbox_id', $account->mailbox_id)->count();
        $this->runJob($account, $payload);

        $this->assertEquals(0, WhatsAppMessage::where('wamid', 'wamid.in6')->count());
        $this->assertEquals($before, Conversation::where('mailbox_id', $account->mailbox_id)->count());
    }

    public function test_change_amb_phone_number_id_dun_altre_numero_es_descarta()
    {
        $account = $this->createTestAccount();
        $payload = $this->inboundPayload($account, 'wamid.in8', '34611222333', 'hola');
        // El change diu ser d'un altre número: no s'ha d'atribuir a aquest compte.
        $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = 'altre-numero';

        $this->runJob($account, $payload);

        $this->assertEquals(0, WhatsAppMessage::where('wamid', 'wamid.in8')->count());
    }

    public function test_compte_inactiu_no_processa_res()
    {
        $account = $this->createTestAccount(['is_active' => false]);
        $this->runJob($account, $this->inboundPayload($account, 'wamid.in7', '34611222333', 'hola'));

        $this->assertEquals(0, WhatsAppMessage::where('wamid', 'wamid.in7')->count());
    }
}
