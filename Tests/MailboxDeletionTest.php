<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

/**
 * Deleting a mailbox is the core's action, not ours, and the module's
 * foreign key was stopping it halfway.
 *
 * Mailbox::deleteMailbox() (app/Mailbox.php:1141 in 1.8.252) destroys
 * every conversation and thread, detaches the users and deletes the
 * folders, and only then deletes the mailbox row. That last statement hit
 * `meta_whatsapp_accounts.mailbox_id ... ON DELETE RESTRICT` and failed,
 * so the administrator got an error and a mailbox that still existed with
 * nothing left in it. Reported by MarkoD (T01).
 *
 * Driven through deleteMailbox(), the method the controller calls, rather
 * than by firing the hook by hand: the hook firing at the right moment is
 * the part being proved. There is no earlier hook to use, because the
 * first one the core offers is mailbox.before_delete, from
 * MailboxObserver::deleting().
 */
class MailboxDeletionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Taking a channel down tells Meta to drop the webhook subscription,
     * which must not leave the test runner: the account here carries a
     * made-up token and the base URL is Meta's real one.
     */
    /** @var int how many times the module reached for Meta */
    public $metaCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->bind(WhatsAppApiClient::class, function ($app, $params) use ($test) {
            return new class($params['account'], $test) extends WhatsAppApiClient {
                private $test;

                public function __construct($account, $test)
                {
                    parent::__construct($account);
                    $this->test = $test;
                }

                protected function curlSimple(string $method, string $url, array $headers): array
                {
                    $this->test->metaCalls++;

                    return ['ok' => true, 'body' => '{"success":true}', 'http_status' => 200,
                        'error_code' => null, 'error_message' => null, 'transient' => false];
                }
            };
        });
    }

    public function test_a_mailbox_with_a_whatsapp_channel_can_be_deleted()
    {
        $account = $this->createTestAccount();
        $mailbox = $account->mailbox;

        WhatsAppMessage::create([
            'wamid'         => 'wamid.DELETE' . uniqid(),
            'account_id'    => $account->id,
            'contact_phone' => '+34600222333',
            'direction'     => WhatsAppMessage::DIRECTION_INBOUND,
            'status'        => WhatsAppMessage::STATUS_RECEIVED,
        ]);

        $mailbox->deleteMailbox();

        $this->assertNull(\App\Mailbox::find($mailbox->id), 'the mailbox survived its own deletion');
        $this->assertNull(
            WhatsAppAccount::find($account->id),
            'the channel row is still pointing at a mailbox that is gone'
        );
        $this->assertSame(
            0,
            WhatsAppMessage::where('account_id', $account->id)->count(),
            'the message rows kept the phone number with nothing left pointing at them'
        );
    }

    /** A mailbox this module knows nothing about is none of our business. */
    public function test_a_mailbox_without_a_channel_is_untouched_by_us()
    {
        $mailbox             = new \App\Mailbox();
        $mailbox->name       = 'PHPUnit plain';
        $mailbox->email      = 'plain-' . uniqid() . '@example.com';
        $mailbox->out_method = \App\Mailbox::OUT_METHOD_PHP_MAIL;
        $mailbox->in_server  = '';
        $mailbox->out_server = '';
        $mailbox->save();

        $mailbox->deleteMailbox();

        $this->assertNull(\App\Mailbox::find($mailbox->id));
        $this->assertSame(0, $this->metaCalls, 'the module reached for Meta over a mailbox that is not ours');
    }

    /** Another channel's rows are not collateral damage. */
    public function test_only_the_rows_of_the_deleted_channel_go()
    {
        $doomed    = $this->createTestAccount();
        $surviving = $this->createTestAccount();

        WhatsAppMessage::create([
            'wamid'         => 'wamid.KEEP' . uniqid(),
            'account_id'    => $surviving->id,
            'contact_phone' => '+34600444555',
            'direction'     => WhatsAppMessage::DIRECTION_INBOUND,
            'status'        => WhatsAppMessage::STATUS_RECEIVED,
        ]);

        $doomed->mailbox->deleteMailbox();

        $this->assertNotNull(WhatsAppAccount::find($surviving->id));
        $this->assertSame(1, WhatsAppMessage::where('account_id', $surviving->id)->count());
    }
}
