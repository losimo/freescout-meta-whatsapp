<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Migrar de servidor sense endur-se el .env, o executar `key:generate`
 * seguint un consell de fòrum, deixa les credencials xifrades amb una clau
 * que ja no hi és. El perfil de fallada era dels pitjors: la pantalla de
 * canals es pintava perfecta, perquè llistar no desxifra res, i tot el que
 * toca Meta moria. Cada entrega, un 500 amb un DecryptException al log; i
 * Meta desactiva un webhook que respon 500 durant dies.
 */
class CredentialsUnreadableTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_webhook_is_refused_politely_when_the_key_changed()
    {
        \Log::spy();

        $account = $this->createTestAccount();
        // Xifrat vàlid per a una altra clau: el que queda després d'un canvi
        // d'APP_KEY. No és text escombraria, és text d'una altra clau.
        $account->app_secret = base64_encode(json_encode([
            'iv'    => base64_encode(random_bytes(16)),
            'value' => base64_encode(random_bytes(32)),
            'mac'   => str_repeat('a', 64),
        ]));
        $account->save();

        $body = json_encode($this->inboundPayload($account, 'wamid.x', '34600111222', 'hola'));

        $response = $this->call(
            'POST',
            $this->url('/meta-whatsapp/webhook'),
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Hub-Signature-256' => 'sha256=whatever'],
            $body
        );

        $response->assertStatus(403);

        \Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) use ($account) {
            return strpos($message, 'APP_KEY') !== false
                && ($context['account_id'] ?? null) === $account->id;
        })->once();
    }

    public function test_the_panel_can_tell_whether_the_credentials_are_readable()
    {
        $healthy = $this->createTestAccount();
        $this->assertTrue($healthy->credentialsAreReadable());

        $broken = $this->createTestAccount();
        $broken->access_token = base64_encode(json_encode([
            'iv'    => base64_encode(random_bytes(16)),
            'value' => base64_encode(random_bytes(32)),
            'mac'   => str_repeat('b', 64),
        ]));
        $broken->save();

        $this->assertFalse($broken->fresh()->credentialsAreReadable());
    }

    public function test_the_accounts_list_flags_a_channel_with_broken_credentials()
    {
        $admin = $this->makeAdminUser();

        $broken = $this->createTestAccount();
        $broken->access_token = base64_encode(json_encode([
            'iv'    => base64_encode(random_bytes(16)),
            'value' => base64_encode(random_bytes(32)),
            'mac'   => str_repeat('c', 64),
        ]));
        $broken->save();

        $response = $this->actingAs($admin)->get($this->url('/meta-whatsapp/settings'));

        $response->assertStatus(200);
        $this->assertStringContainsString(
            __('metawhatsapp::metawhatsapp.credentials_broken'),
            $response->getContent()
        );
    }

    public function test_the_edit_screen_explains_a_broken_channel_and_a_healthy_one_stays_quiet()
    {
        $admin = $this->makeAdminUser();

        $broken = $this->createTestAccount();
        $broken->access_token = base64_encode(json_encode([
            'iv'    => base64_encode(random_bytes(16)),
            'value' => base64_encode(random_bytes(32)),
            'mac'   => str_repeat('d', 64),
        ]));
        $broken->save();

        $brokenResponse = $this->actingAs($admin)->get($this->url('/meta-whatsapp/settings/' . $broken->id . '/edit'));
        $brokenResponse->assertStatus(200);
        $this->assertStringContainsString(
            __('metawhatsapp::metawhatsapp.credentials_broken_title'),
            $brokenResponse->getContent()
        );

        $healthy = $this->createTestAccount();
        $healthyResponse = $this->actingAs($admin)->get($this->url('/meta-whatsapp/settings/' . $healthy->id . '/edit'));
        $healthyResponse->assertStatus(200);
        $this->assertStringNotContainsString(
            __('metawhatsapp::metawhatsapp.credentials_broken_title'),
            $healthyResponse->getContent()
        );
    }
}
