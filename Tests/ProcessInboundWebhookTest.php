<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\CustomerChannel;
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

    public function test_inbound_amb_wa_id_i_user_id_persisteix_tots_dos()
    {
        $account = $this->createTestAccount();
        $payload = $this->inboundPayload($account, 'wamid.bs1', '34611222333', 'hola', [[
            'profile' => ['name' => 'Test'],
            'wa_id'   => '34611222333',
            'user_id' => '9876543210987654321',
        ]]);

        $this->runJob($account, $payload);

        $msg = WhatsAppMessage::where('wamid', 'wamid.bs1')->first();
        $this->assertNotNull($msg);
        $this->assertEquals('+34611222333', $msg->contact_phone);
        $this->assertEquals('9876543210987654321', $msg->contact_user_id);
        // El flux per telèfon es conserva intacte: conversa i thread creats.
        $this->assertNotNull($msg->conversation_id);
        $this->assertNotNull($msg->thread_id);
        $this->assertNotNull(Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333'));
    }

    public function test_bsuid_desconegut_crea_placeholder_i_conversa()
    {
        $account = $this->createTestAccount();
        $bsuid   = '1234567890123456789';
        $payload = $this->inboundPayload($account, 'wamid.f2a', $bsuid, 'hola', [[
            'profile' => ['name' => 'Anna Prova'],
            'user_id' => $bsuid,
        ]]);

        $this->runJob($account, $payload);

        // Fase 2: el BSUID resol un client placeholder amb conversa i thread.
        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $bsuid);
        $this->assertNotNull($customer);
        $this->assertEquals('Anna Prova', $customer->first_name);

        $msg = WhatsAppMessage::where('wamid', 'wamid.f2a')->first();
        $this->assertNotNull($msg);
        $this->assertEquals($bsuid, $msg->contact_user_id);
        $this->assertNull($msg->contact_phone);
        $this->assertNotNull($msg->conversation_id);
        $this->assertNotNull($msg->thread_id);

        $conversation = Conversation::find($msg->conversation_id);
        $this->assertEquals($customer->id, $conversation->customer_id);
    }

    public function test_bsuid_conegut_reutilitza_client_i_conversa()
    {
        $account = $this->createTestAccount();
        $bsuid   = '1234567890123456789';
        $contacts = [['profile' => ['name' => 'Anna Prova'], 'user_id' => $bsuid]];

        $this->runJob($account, $this->inboundPayload($account, 'wamid.f2b1', $bsuid, 'hola', $contacts));
        $this->runJob($account, $this->inboundPayload($account, 'wamid.f2b2', $bsuid, 'segon', $contacts));

        $msg1 = WhatsAppMessage::where('wamid', 'wamid.f2b1')->first();
        $msg2 = WhatsAppMessage::where('wamid', 'wamid.f2b2')->first();
        // Mateixa conversa (oberta) i un sol client per al mateix BSUID.
        $this->assertEquals($msg1->conversation_id, $msg2->conversation_id);
        $this->assertEquals(1, Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $bsuid)
            ->conversations()->count());
    }

    public function test_from_numeric_igual_a_user_id_no_es_tracta_com_telefon()
    {
        $account = $this->createTestAccount();
        // BSUID numèric de 15 dígits: passaria el regex de telèfon, però
        // contacts[].user_id idèntic delata que no és un número real.
        $bsuid   = '123456789012345';
        $payload = $this->inboundPayload($account, 'wamid.bs3', $bsuid, 'hola', [[
            'user_id' => $bsuid,
        ]]);

        $this->runJob($account, $payload);

        $msg = WhatsAppMessage::where('wamid', 'wamid.bs3')->first();
        $this->assertNotNull($msg);
        $this->assertEquals($bsuid, $msg->contact_user_id);
        $this->assertNull($msg->contact_phone);
        // Fase 2: ara sí que hi ha conversa, però via canal BSUID, mai com a telèfon.
        $this->assertNotNull($msg->conversation_id);
        $this->assertNull(Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+' . $bsuid));
        // Sense profile.name, el placeholder pren el BSUID com a nom.
        $this->assertEquals($bsuid, Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $bsuid)->first_name);
    }

    public function test_inbound_nomes_amb_wa_id_deixa_contact_user_id_null()
    {
        $account = $this->createTestAccount();
        $payload = $this->inboundPayload($account, 'wamid.bs4', '34611222333', 'hola', [[
            'profile' => ['name' => 'Test'],
            'wa_id'   => '34611222333',
        ]]);

        $this->runJob($account, $payload);

        $msg = WhatsAppMessage::where('wamid', 'wamid.bs4')->first();
        $this->assertNotNull($msg);
        $this->assertEquals('+34611222333', $msg->contact_phone);
        $this->assertNull($msg->contact_user_id);
        $this->assertNotNull($msg->conversation_id);
    }

    public function test_idempotencia_bsuid_el_mateix_wamid_no_duplica_res()
    {
        $account = $this->createTestAccount();
        $bsuid   = '1234567890123456789';
        $payload = $this->inboundPayload($account, 'wamid.bs5', $bsuid, 'hola', [[
            'user_id' => $bsuid,
        ]]);

        $this->runJob($account, $payload);
        $this->runJob($account, $payload);

        $this->assertEquals(1, WhatsAppMessage::where('wamid', 'wamid.bs5')->count());
    }

    public function test_telefon_i_bsuid_apren_el_canal_al_client_del_telefon()
    {
        $account = $this->createTestAccount();
        $bsuid   = '9876543210987654321';
        $payload = $this->inboundPayload($account, 'wamid.f2c', '34611222333', 'hola', [[
            'wa_id' => '34611222333', 'user_id' => $bsuid,
        ]]);

        $this->runJob($account, $payload);

        $byPhone = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $byBsuid = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $bsuid);
        // El BSUID s'ha après com a segon canal del mateix client.
        $this->assertNotNull($byBsuid);
        $this->assertEquals($byPhone->id, $byBsuid->id);
    }

    public function test_aprenentatge_bsuid_es_idempotent()
    {
        $account  = $this->createTestAccount();
        $bsuid    = '9876543210987654321';
        $contacts = [['wa_id' => '34611222333', 'user_id' => $bsuid]];

        $this->runJob($account, $this->inboundPayload($account, 'wamid.f2d1', '34611222333', 'un', $contacts));
        $this->runJob($account, $this->inboundPayload($account, 'wamid.f2d2', '34611222333', 'dos', $contacts));

        $this->assertEquals(1, CustomerChannel::where('channel', WhatsAppAccount::CHANNEL_BSUID)
            ->where('channel_id', $bsuid)->count());
    }

    public function test_compte_inactiu_no_processa_res()
    {
        $account = $this->createTestAccount(['is_active' => false]);
        $this->runJob($account, $this->inboundPayload($account, 'wamid.in7', '34611222333', 'hola'));

        $this->assertEquals(0, WhatsAppMessage::where('wamid', 'wamid.in7')->count());
    }

    public function test_fusio_de_placeholder_pur_en_revelar_el_telefon()
    {
        $account = $this->createTestAccount();
        $bsuid   = '1234567890123456789';

        // 1) Només arriba el BSUID: es crea un placeholder pur (sense telèfon).
        $this->runJob($account, $this->inboundPayload($account, 'wamid.f2e1', $bsuid, 'hola', [[
            'profile' => ['name' => 'Anna Prova'],
            'user_id' => $bsuid,
        ]]));
        $placeholder = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $bsuid);
        $this->assertNotNull($placeholder);

        // 2) El mateix BSUID revela ara el telèfon: cal fusionar el placeholder
        // dins del client real associat al telèfon.
        $this->runJob($account, $this->inboundPayload($account, 'wamid.f2e2', '34611222333', 'segon', [[
            'wa_id' => '34611222333', 'user_id' => $bsuid,
        ]]));

        $target = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $this->assertNotNull($target);
        $this->assertNotEquals($placeholder->id, $target->id);

        // El canal BSUID ara apunta al client real, no al placeholder.
        $this->assertEquals($target->id, Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $bsuid)->id);

        $msg1 = WhatsAppMessage::where('wamid', 'wamid.f2e1')->first();
        $msg2 = WhatsAppMessage::where('wamid', 'wamid.f2e2')->first();
        $this->assertEquals($target->id, Conversation::find($msg2->conversation_id)->customer_id);
        // La conversa del placeholder s'ha mogut i el segon missatge s'hi ha encadenat.
        $this->assertEquals($msg1->conversation_id, $msg2->conversation_id);

        // El placeholder queda inert: sense canals, sense converses i anotat.
        $placeholder = Customer::find($placeholder->id);
        $this->assertNotNull($placeholder);
        $this->assertStringContainsString('Merged into customer #' . $target->id, (string) $placeholder->notes);
        $this->assertEquals(0, CustomerChannel::where('customer_id', $placeholder->id)->count());
        $this->assertEquals(0, Conversation::where('customer_id', $placeholder->id)->count());
    }

    public function test_bsuid_de_client_real_no_es_fusiona()
    {
        $account = $this->createTestAccount();
        $bsuid   = '1234567890123456789';

        // Client real A: telèfon P1 amb BSUID après (no és placeholder pur).
        $this->runJob($account, $this->inboundPayload($account, 'wamid.f2f1', '34611222333', 'hola', [[
            'wa_id' => '34611222333', 'user_id' => $bsuid,
        ]]));
        $customerA = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');

        // Anomalia: el mateix BSUID arriba amb un telèfon diferent P2.
        $this->runJob($account, $this->inboundPayload($account, 'wamid.f2f2', '34699888777', 'hola', [[
            'wa_id' => '34699888777', 'user_id' => $bsuid,
        ]]));

        // Cap fusió: el BSUID continua apuntant a A i el missatge es resol per P2.
        $this->assertEquals($customerA->id, Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $bsuid)->id);
        $customerC = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34699888777');
        $this->assertNotEquals($customerA->id, $customerC->id);
        $msg2 = WhatsAppMessage::where('wamid', 'wamid.f2f2')->first();
        $this->assertEquals($customerC->id, Conversation::find($msg2->conversation_id)->customer_id);
        // A conserva els seus dos canals intactes.
        $this->assertEquals(2, CustomerChannel::where('customer_id', $customerA->id)->count());
    }

    public function test_bsuid_massa_llarg_amb_telefon_no_sapren_com_a_canal()
    {
        $account = $this->createTestAccount();
        $bsuid   = str_repeat('9', 70); // > 64: no cap a customer_channel.channel_id
        $payload = $this->inboundPayload($account, 'wamid.f2g', '34611222333', 'hola', [[
            'wa_id' => '34611222333', 'user_id' => $bsuid,
        ]]);

        $this->runJob($account, $payload);

        // El flux per telèfon no es veu afectat i el BSUID queda al missatge...
        $msg = WhatsAppMessage::where('wamid', 'wamid.f2g')->first();
        $this->assertEquals($bsuid, $msg->contact_user_id);
        $this->assertNotNull($msg->conversation_id);
        // ...però mai al canal.
        $this->assertEquals(0, CustomerChannel::where('channel_id', $bsuid)->count());
        // Sense el guard, MySQL no estricte truncaria el BSUID a 64 caràcters
        // en inserir-lo: tampoc no hi pot haver cap fila amb el prefix truncat.
        $this->assertEquals(0, CustomerChannel::where('channel_id', substr($bsuid, 0, 64))->count());
    }

    public function test_bsuid_massa_llarg_sense_telefon_persisteix_sense_conversa()
    {
        $account = $this->createTestAccount();
        $bsuid   = str_repeat('9', 70);
        $payload = $this->inboundPayload($account, 'wamid.f2h', $bsuid, 'hola', [[
            'user_id' => $bsuid,
        ]]);

        $this->runJob($account, $payload);

        // Sense telèfon ni canal possible: fallada controlada estil fase 1.
        $msg = WhatsAppMessage::where('wamid', 'wamid.f2h')->first();
        $this->assertNotNull($msg);
        $this->assertEquals($bsuid, $msg->contact_user_id);
        $this->assertNull($msg->conversation_id);
        $this->assertEquals(0, CustomerChannel::where('channel_id', $bsuid)->count());
    }
}
