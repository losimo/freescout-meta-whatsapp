<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;

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
}
