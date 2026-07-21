<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Mailbox;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;

abstract class TestCase extends BaseTestCase
{
    const TEST_APP_SECRET   = '<test-app-secret-not-real>';
    const TEST_ACCESS_TOKEN = '<test-access-token-not-real>';

    public function createApplication()
    {
        $app = require __DIR__ . '/../../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // El middleware global ResponseHeaders del core crida header(); sota
        // phpunit (CLI, output ja emès pel printer) PHP avisa "headers already
        // sent" i HandleExceptions (error_reporting -1) ho converteix en
        // ErrorException -> 500. S'ignora només aquest warning; la resta es
        // delega al handler previ.
        // Mockery (usat per Log::spy()/shouldReceive()) crida mètodes de
        // ReflectionParameter deprecats en aquesta versió de PHP;
        // HandleExceptions ho converteix igualment en ErrorException.
        $previousHandler = null;
        $previousHandler = set_error_handler(
            function ($severity, $message, $file = '', $line = 0) use (&$previousHandler) {
                if (strpos($message, 'Cannot modify header information') !== false
                    || strpos($message, 'ReflectionParameter::') !== false
                    || strpos($message, 'Use of "parent" in callables is deprecated') !== false
                ) {
                    return true;
                }
                return $previousHandler
                    ? call_user_func($previousHandler, $severity, $message, $file, $line)
                    : false;
            }
        );
    }

    /**
     * Crea una bústia tècnica + compte WhatsApp de prova.
     * S'executa dins de la transacció del test: rollback automàtic.
     */
    protected function createTestAccount(array $overrides = []): WhatsAppAccount
    {
        $phoneNumberId = $overrides['phone_number_id'] ?? ('test' . mt_rand(100000000, 999999999));

        $mailbox = new Mailbox();
        $mailbox->name       = 'PHPUnit WhatsApp';
        $mailbox->email      = 'whatsapp-' . $phoneNumberId . '@channel.internal';
        $mailbox->out_method = Mailbox::OUT_METHOD_PHP_MAIL;
        $mailbox->in_server  = '';
        $mailbox->out_server = '';
        $mailbox->save();

        $account = new WhatsAppAccount();
        $account->name                 = 'PHPUnit WhatsApp';
        $account->phone_number         = '+34600999888';
        $account->phone_number_id      = $phoneNumberId;
        $account->waba_id              = 'test-waba';
        $account->verify_token         = bin2hex(random_bytes(32));
        $account->mailbox_id           = $mailbox->id;
        $account->auto_created_mailbox = true;
        $account->access_token         = encrypt(self::TEST_ACCESS_TOKEN);
        $account->app_secret           = encrypt(self::TEST_APP_SECRET);
        $account->is_active            = $overrides['is_active'] ?? true;
        $account->save();

        return $account;
    }

    /**
     * Payload mínim de Meta amb un missatge de text.
     * $contacts permet simular el bloc contacts[] (wa_id, user_id/BSUID).
     */
    protected function inboundPayload(WhatsAppAccount $account, string $wamid, string $from, string $text, ?array $contacts = null): array
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'id'      => $account->waba_id,
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata'          => [
                            'display_phone_number' => ltrim($account->phone_number, '+'),
                            'phone_number_id'      => $account->phone_number_id,
                        ],
                        'messages' => [[
                            'from'      => $from,
                            'id'        => $wamid,
                            'timestamp' => (string) time(),
                            'text'      => ['body' => $text],
                            'type'      => 'text',
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];

        if ($contacts !== null) {
            $payload['entry'][0]['changes'][0]['value']['contacts'] = $contacts;
        }

        return $payload;
    }

    /**
     * URL absoluta amb el host real: el TrustHosts de FreeScout rebutja
     * el 'localhost' per defecte del client de tests.
     */
    protected function url(string $path): string
    {
        return rtrim(config('app.url'), '/') . $path;
    }

    protected function signedHeaders(string $body): array
    {
        return [
            'HTTP_X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, self::TEST_APP_SECRET),
            'CONTENT_TYPE'             => 'application/json',
        ];
    }
}
