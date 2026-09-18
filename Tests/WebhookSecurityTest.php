<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;

class WebhookSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected function webhookUrl(string $query = ''): string
    {
        return $this->url('/meta-whatsapp/webhook' . $query);
    }

    public function test_handshake_amb_token_valid_retorna_challenge()
    {
        $account = $this->createTestAccount();

        $response = $this->get($this->webhookUrl('?hub.mode=subscribe'
            . '&hub.verify_token=' . $account->verify_token
            . '&hub.challenge=repte-de-prova'));

        $response->assertStatus(200);
        $this->assertEquals('repte-de-prova', $response->getContent());
    }

    public function test_handshake_amb_token_dolent_retorna_403()
    {
        $this->createTestAccount();

        $this->get($this->webhookUrl('?hub.mode=subscribe&hub.verify_token=token-dolent&hub.challenge=x'))
            ->assertStatus(403);
    }

    public function test_handshake_sense_mode_subscribe_retorna_403()
    {
        $account = $this->createTestAccount();

        $this->get($this->webhookUrl('?hub.verify_token=' . $account->verify_token . '&hub.challenge=x'))
            ->assertStatus(403);
    }

    public function test_handshake_amb_compte_inactiu_retorna_403()
    {
        $account = $this->createTestAccount(['is_active' => false]);

        $this->get($this->webhookUrl('?hub.mode=subscribe&hub.verify_token=' . $account->verify_token . '&hub.challenge=x'))
            ->assertStatus(403);
    }

    public function test_post_sense_signatura_retorna_403()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        $body    = json_encode($this->inboundPayload($account, 'wamid.t1', '34600111222', 'hola'));

        $response = $this->call('POST', $this->webhookUrl(), [], [], [],
            ['CONTENT_TYPE' => 'application/json'], $body);

        $response->assertStatus(403);
        Queue::assertNotPushed(ProcessInboundWebhook::class);
    }

    public function test_post_amb_signatura_falsa_retorna_403()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        $body    = json_encode($this->inboundPayload($account, 'wamid.t2', '34600111222', 'hola'));

        $response = $this->call('POST', $this->webhookUrl(), [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'secret-equivocat'),
            'CONTENT_TYPE'             => 'application/json',
        ], $body);

        $response->assertStatus(403);
        Queue::assertNotPushed(ProcessInboundWebhook::class);
    }

    public function test_post_amb_compte_desconegut_retorna_403()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        $payload = $this->inboundPayload($account, 'wamid.t3', '34600111222', 'hola');
        $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = 'inexistent';
        $body = json_encode($payload);

        $this->call('POST', $this->webhookUrl(), [], [], [], $this->signedHeaders($body), $body)
            ->assertStatus(403);
        Queue::assertNotPushed(ProcessInboundWebhook::class);
    }

    public function test_post_amb_signatura_valida_encua_el_job()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        $body    = json_encode($this->inboundPayload($account, 'wamid.t4', '34600111222', 'hola'));

        $response = $this->call('POST', $this->webhookUrl(), [], [], [],
            $this->signedHeaders($body), $body);

        $response->assertStatus(200);
        Queue::assertPushed(ProcessInboundWebhook::class, 1);
    }

    public function test_post_amb_json_invalid_retorna_403()
    {
        Queue::fake();
        $this->createTestAccount();

        $this->call('POST', $this->webhookUrl(), [], [], [],
            ['CONTENT_TYPE' => 'application/json'], 'no soc json {')
            ->assertStatus(403);
        Queue::assertNotPushed(ProcessInboundWebhook::class);
    }

    /**
     * Comprovació d'instal·lació: obrir l'URL del webhook al navegador ha de
     * dir que tot va bé, no "Forbidden". Ve del #33, on qui reportava no tenia
     * cap manera de saber si el mòdul responia sense entrar-hi.
     */
    public function test_obrir_lurl_al_navegador_diu_que_el_modul_respon()
    {
        $response = $this->get($this->webhookUrl(''));

        $response->assertStatus(200);
        // assertSee() acaba a assertContains() sobre una cadena, que PHPUnit 9
        // ja no accepta amb aquesta versió de Laravel.
        $this->assertStringContainsString('MetaWhatsApp is installed', $response->getContent());
    }

    /**
     * El 200 no és casual: molts allotjaments compartits substitueixen les
     * pàgines d'error per la seva, i amb un 403 la comprovació fallaria
     * justament on més falta fa.
     */
    public function test_la_comprovacio_no_depen_duna_pagina_derror_del_servidor()
    {
        $this->get($this->webhookUrl(''))->assertStatus(200);
    }

    /**
     * La cortesia és només per a qui no envia res. Amb paràmetres a mitges
     * torna a ser una crida mal formada i es rebutja sense dir per què.
     */
    public function test_amb_parametres_a_mitges_continua_sent_un_403_sec()
    {
        $response = $this->get($this->webhookUrl('?hub.mode=subscribe'));
        $response->assertStatus(403);
        $this->assertStringContainsString('Forbidden', $response->getContent());

        $this->get($this->webhookUrl('?hub.challenge=x'))
            ->assertStatus(403);
    }

    /**
     * Everything that is not a message or a status arrives without
     * `metadata`: template status changes, quality ratings, account alerts.
     * These carry only the WABA id on the entry, and must reach the queue
     * exactly like a message does once the signature checks out.
     */
    public function test_a_waba_level_event_with_a_valid_signature_reaches_the_queue()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        $body    = json_encode($this->templateStatusPayload($account));

        $response = $this->call('POST', $this->webhookUrl(), [], [], [],
            $this->signedHeaders($body), $body);

        $response->assertStatus(200);
        Queue::assertPushed(ProcessInboundWebhook::class, 1);
    }

    public function test_a_waba_level_event_with_a_bad_signature_is_still_refused()
    {
        Queue::fake();
        $account = $this->createTestAccount();
        $body    = json_encode($this->templateStatusPayload($account));

        $response = $this->call('POST', $this->webhookUrl(), [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'wrong-secret'),
            'CONTENT_TYPE'             => 'application/json',
        ], $body);

        $response->assertStatus(403);
        Queue::assertNotPushed(ProcessInboundWebhook::class);
    }

    public function test_a_payload_with_neither_a_phone_number_nor_a_waba_is_refused()
    {
        Queue::fake();
        $this->createTestAccount();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'changes' => [[
                    'value' => ['foo' => 'bar'],
                    'field' => 'account_alerts',
                ]],
            ]],
        ];
        $body = json_encode($payload);

        $response = $this->call('POST', $this->webhookUrl(), [], [], [],
            ['CONTENT_TYPE' => 'application/json'], $body);

        $response->assertStatus(403);
        Queue::assertNotPushed(ProcessInboundWebhook::class);
    }

    /**
     * The whole way through: a signed POST from Meta, no queue faking, and
     * the template ends up recorded on the account. This is the seam where
     * the 403 lived that made the entire event router unreachable.
     */
    public function test_a_signed_template_rejection_ends_up_on_the_account()
    {
        // --no-configuration means phpunit.xml's QUEUE_DRIVER=sync override
        // does not apply, and this module's default queue driver is
        // 'database'; a real POST would enqueue a row rather than run it.
        // Forced to 'sync' here so the job actually executes inline, which
        // is the whole point of this test.
        \Queue::setDefaultDriver('sync');

        $account = $this->createTestAccount();
        $body    = json_encode($this->templateStatusPayload($account));

        $response = $this->call('POST', $this->webhookUrl(), [], [], [],
            $this->signedHeaders($body), $body);

        $response->assertStatus(200);
        $this->assertCount(1, $account->fresh()->templates_issue);
    }

    /**
     * WABA-level payload as Meta sends it: no `metadata` anywhere, only the
     * WABA id on the entry.
     */
    protected function templateStatusPayload(WhatsAppAccount $account, string $event = 'REJECTED'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'id'      => $account->waba_id,
                'changes' => [[
                    'value' => [
                        'event'                     => $event,
                        'message_template_id'       => 123456,
                        'message_template_name'     => 'order_update',
                        'message_template_language' => 'en_US',
                    ],
                    'field' => 'message_template_status_update',
                ]],
            ]],
        ];
    }
}
