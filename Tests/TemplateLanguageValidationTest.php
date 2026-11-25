<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

/**
 * The language of a template is Meta's code, not ours, and the form has to
 * accept every code Meta can hand out.
 *
 * The rule read `[a-z]{2}` with an optional region, which refuses `fil`,
 * Filipino, the one three-letter code in Meta's list. An administrator with
 * a Filipino template could not save the channel at all, and the message the
 * form gave was about the field being invalid rather than about the code
 * being unsupported, so there was nothing to act on. Reported by MarkoD
 * (T25).
 *
 * Posted through the settings form rather than checked against the rule in
 * isolation, because what matters is that the request class the route uses
 * accepts it.
 */
class TemplateLanguageValidationTest extends TestCase
{
    use DatabaseTransactions;

    /** store() subscribes the webhook on success, which must not leave the test runner. */
    protected function bindOfflineApiClient(): void
    {
        $this->app->bind(WhatsAppApiClient::class, function ($app, $params) {
            return new class($params['account']) extends WhatsAppApiClient {
                protected function curlPostSimple(string $url, array $headers): array
                {
                    return ['ok' => true, 'body' => '{"success":true}', 'http_status' => 200,
                        'error_code' => null, 'error_message' => null, 'transient' => false];
                }
            };
        });
    }

    protected function postSettingsWithTemplateLanguage(string $language)
    {
        $this->bindOfflineApiClient();

        return $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/settings'), [
                'name'            => 'PHPUnit language',
                'phone_number'    => '+34600111444',
                'phone_number_id' => 'lang-test-' . uniqid(),
                'waba_id'         => 'waba-lang-test',
                'verify_token'    => bin2hex(random_bytes(32)),
                'access_token'    => str_repeat('a', 25),
                'app_secret'      => str_repeat('b', 20),
                'mailbox_mode'    => 'new',
                'mailbox_name'    => 'PHPUnit language mailbox',
                'templates'       => [
                    ['id' => 'recover_conversation', 'language' => $language],
                ],
            ]);
    }

    public function test_a_three_letter_code_is_accepted()
    {
        $response = $this->postSettingsWithTemplateLanguage('fil');

        $response->assertStatus(302);
        $response->assertSessionMissing('errors');
    }

    /**
     * Still a code and not free text: the field goes into a Graph API call,
     * and widening it to {2,3} must not have widened it to anything else.
     */
    public function test_a_longer_code_is_still_refused()
    {
        $response = $this->postSettingsWithTemplateLanguage('filipino');

        $response->assertStatus(302);
        $response->assertSessionHasErrors('templates.0.language');
    }

    /** The common case, unchanged: two letters with an optional region. */
    public function test_a_two_letter_code_with_a_region_is_accepted()
    {
        $response = $this->postSettingsWithTemplateLanguage('pt_BR');

        $response->assertStatus(302);
        $response->assertSessionMissing('errors');
    }
}
