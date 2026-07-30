<?php

namespace Modules\MetaWhatsApp\Tests;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

class ConnectionPanelTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeAdminUser(): User
    {
        $admin = new User();
        $admin->first_name = 'Admin';
        $admin->last_name  = 'Test';
        $admin->email      = 'admin-' . uniqid() . '@example.com';
        $admin->password   = bcrypt('secret');
        $admin->role       = User::ROLE_ADMIN;
        $admin->save();

        return $admin;
    }

    // ------------------------------------------------------------------
    // WhatsAppApiClient::testConnection()
    // ------------------------------------------------------------------

    public function test_test_connection_ok_retorna_verified_name_del_body()
    {
        $account = $this->createTestAccount();

        $client = new class ($account) extends WhatsAppApiClient {
            protected function curlGet(string $url, array $headers): array
            {
                return [
                    'ok' => true, 'body' => json_encode(['verified_name' => 'Suport Test']),
                    'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false,
                ];
            }
        };

        $result = $client->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertEquals('Suport Test', $result['verified_name']);
    }

    public function test_test_connection_fallit_no_afegeix_verified_name()
    {
        $account = $this->createTestAccount();

        $client = new class ($account) extends WhatsAppApiClient {
            protected function curlGet(string $url, array $headers): array
            {
                return [
                    'ok' => false, 'body' => null,
                    'http_status' => 401, 'error_code' => '190', 'error_message' => 'Token caducat', 'transient' => false,
                ];
            }
        };

        $result = $client->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertArrayNotHasKey('verified_name', $result);
        $this->assertEquals('Token caducat', $result['error_message']);
    }

    // ------------------------------------------------------------------
    // Controlador: POST meta-whatsapp/settings/{id}/test-connection
    // ------------------------------------------------------------------

    public function test_post_test_connection_exit_marca_flash_success_floating()
    {
        $account = $this->createTestAccount();

        $this->app->bind(WhatsAppApiClient::class, function ($app, $params) {
            return new class ($params['account']) extends WhatsAppApiClient {
                protected function curlGet(string $url, array $headers): array
                {
                    return [
                        'ok' => true, 'body' => json_encode(['verified_name' => 'Suport Test']),
                        'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false,
                    ];
                }
            };
        });

        $admin = $this->makeAdminUser();

        $response = $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/settings/' . $account->id . '/test-connection'));

        $response->assertStatus(302);
        $response->assertRedirect($this->url('/meta-whatsapp/settings/' . $account->id . '/edit'));
        $response->assertSessionHas('flash_success_floating');
    }

    public function test_post_test_connection_fallit_marca_flash_error_floating()
    {
        $account = $this->createTestAccount();

        $this->app->bind(WhatsAppApiClient::class, function ($app, $params) {
            return new class ($params['account']) extends WhatsAppApiClient {
                protected function curlGet(string $url, array $headers): array
                {
                    return [
                        'ok' => false, 'body' => null,
                        'http_status' => 401, 'error_code' => '190', 'error_message' => 'Token caducat', 'transient' => false,
                    ];
                }
            };
        });

        $admin = $this->makeAdminUser();

        $response = $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/settings/' . $account->id . '/test-connection'));

        $response->assertStatus(302);
        $response->assertSessionHas('flash_error_floating');
    }

    // ------------------------------------------------------------------
    // Controlador: GET meta-whatsapp/settings/{id}/edit (health snapshot)
    // ------------------------------------------------------------------

    public function test_edit_snapshot_de_salut_reflecteix_els_darrers_missatges()
    {
        $account = $this->createTestAccount();

        WhatsAppMessage::create([
            'wamid' => 'wamid.hs-in-1', 'account_id' => $account->id, 'conversation_id' => 1,
            'contact_phone' => '+34611222333', 'direction' => WhatsAppMessage::DIRECTION_INBOUND,
            'status' => WhatsAppMessage::STATUS_RECEIVED,
        ]);
        $lastInbound = WhatsAppMessage::create([
            'wamid' => 'wamid.hs-in-2', 'account_id' => $account->id, 'conversation_id' => 1,
            'contact_phone' => '+34611222333', 'direction' => WhatsAppMessage::DIRECTION_INBOUND,
            'status' => WhatsAppMessage::STATUS_RECEIVED,
        ]);
        // El missatge failed és anterior al sent, perquè "últim intent de
        // sortida" ha de reflectir el més recent (encara que sigui un èxit),
        // sense confondre's amb "últim error" (que és un altre creuament).
        $lastError = WhatsAppMessage::create([
            'wamid' => 'wamid.hs-err-1', 'account_id' => $account->id, 'conversation_id' => 1,
            'contact_phone' => '+34611222333', 'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status' => WhatsAppMessage::STATUS_FAILED, 'error_code' => '131047',
        ]);
        $lastOutbound = WhatsAppMessage::create([
            'wamid' => 'wamid.hs-out-1', 'account_id' => $account->id, 'conversation_id' => 1,
            'contact_phone' => '+34611222333', 'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status' => WhatsAppMessage::STATUS_SENT,
        ]);

        $admin = $this->makeAdminUser();

        $response = $this->actingAs($admin)
            ->get($this->url('/meta-whatsapp/settings/' . $account->id . '/edit'));

        $response->assertStatus(200);
        $response->assertViewHas('healthSnapshot', function ($snapshot) use ($lastInbound, $lastOutbound, $lastError) {
            return $snapshot['last_inbound']->id === $lastInbound->id
                && $snapshot['last_outbound']->id === $lastOutbound->id
                && $snapshot['last_error']->id === $lastError->id;
        });
    }

    public function test_edit_snapshot_de_salut_es_null_sense_historial()
    {
        $account = $this->createTestAccount();
        $admin   = $this->makeAdminUser();

        $response = $this->actingAs($admin)
            ->get($this->url('/meta-whatsapp/settings/' . $account->id . '/edit'));

        $response->assertStatus(200);
        $response->assertViewHas('healthSnapshot', function ($snapshot) {
            return $snapshot['last_inbound'] === null
                && $snapshot['last_outbound'] === null
                && $snapshot['last_error'] === null;
        });
    }
}
