<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

/**
 * Deleting the channel from the module's own screen. Two things used to
 * stay behind: Meta kept its webhook subscription for a number nobody was
 * answering any more, and the module's message rows kept the contact's
 * phone number with nothing left pointing at them. Reported by MarkoD
 * (T38).
 *
 * Meta must never be the reason this fails, so the refusing case is
 * tested as carefully as the working one.
 */
class ChannelTeardownTest extends TestCase
{
    use DatabaseTransactions;

    /** @var int how many times the module asked Meta to drop the subscription */
    public $unsubscribeCalls = 0;

    protected function bindApiClient(bool $ok): void
    {
        $test = $this;

        $this->app->bind(WhatsAppApiClient::class, function ($app, $params) use ($ok, $test) {
            return new class($params['account'], $ok, $test) extends WhatsAppApiClient {
                private $pretendOk;
                private $test;

                public function __construct($account, $pretendOk, $test)
                {
                    parent::__construct($account);
                    $this->pretendOk = $pretendOk;
                    $this->test      = $test;
                }

                protected function curlSimple(string $method, string $url, array $headers): array
                {
                    if ($method === 'DELETE') {
                        $this->test->unsubscribeCalls++;
                    }

                    if ($this->pretendOk) {
                        return ['ok' => true, 'body' => '{"success":true}', 'http_status' => 200,
                            'error_code' => null, 'error_message' => null, 'transient' => false];
                    }

                    return ['ok' => false, 'body' => null, 'http_status' => 401,
                        'error_code' => '190', 'error_message' => 'Access token has expired',
                        'transient' => false];
                }
            };
        });
    }

    protected function deleteChannel(WhatsAppAccount $account)
    {
        return $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->delete($this->url('/meta-whatsapp/settings/' . $account->id));
    }

    public function test_the_subscription_is_removed_and_the_rows_go()
    {
        $this->bindApiClient(true);
        $account = $this->createTestAccount();
        WhatsAppMessage::create([
            'wamid'         => 'wamid.TEARDOWN' . uniqid(),
            'account_id'    => $account->id,
            'contact_phone' => '+34600333444',
            'direction'     => WhatsAppMessage::DIRECTION_INBOUND,
            'status'        => WhatsAppMessage::STATUS_RECEIVED,
        ]);

        $this->deleteChannel($account);

        $this->assertSame(1, $this->unsubscribeCalls, 'Meta was never told the number is gone');
        $this->assertNull(WhatsAppAccount::find($account->id));
        $this->assertSame(
            0,
            WhatsAppMessage::where('account_id', $account->id)->count(),
            'the message rows kept the phone number with nothing left pointing at them'
        );
    }

    /**
     * The defect this case exists for: `DELETE /{waba_id}/subscribed_apps`
     * is account-wide, so deleting one channel used to stop the inbound of
     * every other number on the same Messaging account, with nothing said
     * anywhere. Two numbers under one WABA is what the module's own README
     * recommends (`README.md:192`), so it is the ordinary setup.
     */
    public function test_a_sibling_channel_on_the_same_account_keeps_the_subscription()
    {
        $this->bindApiClient(true);
        $kept    = $this->createTestAccount(['waba_id' => 'waba-shared']);
        $deleted = $this->createTestAccount(['waba_id' => 'waba-shared']);

        $this->deleteChannel($deleted);

        $this->assertSame(
            0,
            $this->unsubscribeCalls,
            'deleting one channel unsubscribed the whole Messaging account, so the other number stopped receiving'
        );
        $this->assertNull(WhatsAppAccount::find($deleted->id), 'the channel asked for was not deleted');
        $this->assertNotNull(WhatsAppAccount::find($kept->id), 'the sibling channel was deleted as well');
    }

    /**
     * And the other half of the same rule: an inactive sibling is still a
     * channel an administrator means to switch back on, and unsubscribing
     * would leave it sending without receiving.
     */
    public function test_an_inactive_sibling_also_keeps_the_subscription()
    {
        $this->bindApiClient(true);
        $this->createTestAccount(['waba_id' => 'waba-shared', 'is_active' => false]);
        $deleted = $this->createTestAccount(['waba_id' => 'waba-shared']);

        $this->deleteChannel($deleted);

        $this->assertSame(0, $this->unsubscribeCalls, 'an inactive sibling was treated as no sibling at all');
    }

    /** A channel on an account of its own is still the last one out. */
    public function test_a_channel_alone_on_its_account_still_removes_the_subscription()
    {
        $this->bindApiClient(true);
        $this->createTestAccount(['waba_id' => 'waba-other']);
        $deleted = $this->createTestAccount(['waba_id' => 'waba-alone']);

        $this->deleteChannel($deleted);

        $this->assertSame(
            1,
            $this->unsubscribeCalls,
            'a channel on another Messaging account was counted as a sibling of this one'
        );
    }

    /** An expired token must not be the reason an administrator cannot delete a channel. */
    public function test_a_refusal_from_meta_does_not_stop_the_deletion()
    {
        $this->bindApiClient(false);
        $account = $this->createTestAccount();

        $response = $this->deleteChannel($account);

        $this->assertNull(
            WhatsAppAccount::find($account->id),
            'the channel survived because Meta said no'
        );
        $response->assertSessionHas('flash_warning_floating');
        $message = session('flash_warning_floating');
        $this->assertStringContainsString(
            'Meta App Dashboard',
            $message,
            'the warning does not say what the administrator has left to do'
        );
        $this->assertStringContainsString(
            'Access token has expired',
            $message,
            "Meta's own reason is not passed through, so the administrator cannot tell why"
        );
    }
}
