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
}
