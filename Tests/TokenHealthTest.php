<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

/**
 * Error 190 turned into a warning that arrives first.
 *
 * A correctly configured system user token never expires, so this check is
 * quiet for anyone who set it up properly. It speaks up exactly for the
 * temporary 24-hour token, which is the mistake that actually happened and
 * left an installation unable to send without anyone knowing why.
 */
class TokenHealthTest extends TestCase
{
    use DatabaseTransactions;

    /** Client whose only network call is answered from the fixture given here. */
    protected function clientReturning(WhatsAppAccount $account, array $data, bool $ok = true): WhatsAppApiClient
    {
        return new class ($account, $data, $ok) extends WhatsAppApiClient {
            private $data;
            private $ok;

            public function __construct($account, $data, $ok)
            {
                parent::__construct($account);
                $this->data = $data;
                $this->ok   = $ok;
            }

            protected function curlGet(string $url, array $headers): array
            {
                if ($this->ok) {
                    return [
                        'ok' => true, 'body' => json_encode(['data' => $this->data]),
                        'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false,
                    ];
                }
                return [
                    'ok' => false, 'body' => null, 'http_status' => 400,
                    'error_code' => '190', 'error_message' => 'Invalid OAuth access token', 'transient' => false,
                ];
            }
        };
    }

    public function test_without_an_app_id_nothing_is_asked()
    {
        $account = $this->createTestAccount();
        $account->app_id = null;
        $account->save();

        $result = $this->clientReturning($account, [])->checkToken();

        $this->assertFalse($result['checked'], 'a call went out with no App ID to authenticate it');
        $this->assertNull($result['expires_at']);
    }

    public function test_a_permanent_token_reports_no_expiry()
    {
        $account = $this->createTestAccount();
        $account->app_id = '1234567890';
        $account->save();

        // Meta writes 0 for a token that never expires.
        $result = $this->clientReturning($account, [
            'is_valid' => true, 'expires_at' => 0, 'scopes' => ['whatsapp_business_messaging'],
        ])->checkToken();

        $this->assertTrue($result['checked']);
        $this->assertTrue($result['valid']);
        $this->assertNull($result['expires_at'], 'expires_at 0 means never, not the epoch');
    }

    public function test_a_temporary_token_reports_its_expiry()
    {
        $account = $this->createTestAccount();
        $account->app_id = '1234567890';
        $account->save();

        $expiry = time() + 3600;
        $result = $this->clientReturning($account, [
            'is_valid' => true, 'expires_at' => $expiry, 'scopes' => ['whatsapp_business_messaging'],
        ])->checkToken();

        $this->assertEquals($expiry, $result['expires_at']);
    }

    public function test_an_expired_token_is_reported_as_invalid_not_as_a_crash()
    {
        $account = $this->createTestAccount();
        $account->app_id = '1234567890';
        $account->save();

        $result = $this->clientReturning($account, [], false)->checkToken();

        $this->assertTrue($result['checked']);
        $this->assertFalse($result['ok']);
        $this->assertEquals('190', $result['error_code']);
    }

    // ------------------------------------------------------------------
    // The three states the account model reports, which is what the panel reads
    // ------------------------------------------------------------------

    public function test_no_app_id_is_unknown_not_never()
    {
        $account = $this->createTestAccount();
        $account->app_id = null;
        $account->token_expires_at = null;

        $this->assertEquals('unknown', $account->tokenExpiryState());
        $this->assertNull($account->daysUntilTokenExpiry());
    }

    public function test_asked_and_permanent_is_never()
    {
        $account = $this->createTestAccount();
        $account->app_id = '1234567890';
        $account->token_expires_at = null;

        $this->assertEquals('never', $account->tokenExpiryState());
        $this->assertNull($account->daysUntilTokenExpiry(), 'a permanent token has nothing to count down');
    }

    public function test_a_future_expiry_counts_down_and_a_past_one_is_expired()
    {
        $account = $this->createTestAccount();
        $account->app_id = '1234567890';

        $account->token_expires_at = now()->addDays(10);
        $this->assertEquals('expires', $account->tokenExpiryState());
        $this->assertEquals(10, $account->daysUntilTokenExpiry(), 'the countdown truncates instead of rounding up');

        // An hour away still has to read as a day, not as zero.
        $account->token_expires_at = now()->addHour();
        $this->assertEquals(1, $account->daysUntilTokenExpiry());

        $account->token_expires_at = now()->subDay();
        $this->assertEquals('expired', $account->tokenExpiryState());
        $this->assertNull($account->daysUntilTokenExpiry());
    }
}
