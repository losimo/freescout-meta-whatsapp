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
}
