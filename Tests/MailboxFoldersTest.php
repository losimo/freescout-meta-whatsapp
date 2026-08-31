<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Folder;
use App\Mailbox;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

/**
 * Issue #30: creating a channel with "New mailbox" produced two copies of
 * every public folder in the sidebar (Unassigned, Drafts, Assigned, Closed,
 * Deleted, Spam). The controller called createPublicFolders() after saving the
 * mailbox, not knowing the core MailboxObserver already does it on the
 * `created` model event, and createPublicFolders() inserts unconditionally.
 *
 * Personal folders (Mine, Starred) were never duplicated, because
 * createUsersFolders() skips users that already have folders -- which is
 * exactly why the sidebar showed a mix of single and doubled entries.
 */
class MailboxFoldersTest extends TestCase
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

    /**
     * The webhook subscription is a real HTTP call in store(); the folder
     * count does not depend on its outcome, so it is stubbed out.
     */
    protected function fakeWebhookSubscription(): void
    {
        $this->app->bind(WhatsAppApiClient::class, function ($app, $parameters) {
            return new class ($parameters['account']) extends WhatsAppApiClient {
                public function subscribeWebhook(): array
                {
                    return ['ok' => true, 'error_code' => null, 'error_message' => null];
                }
            };
        });
    }

    protected function storePayload(array $overrides = []): array
    {
        $phoneNumberId = 'test' . mt_rand(100000000, 999999999);

        return array_merge([
            'name'            => 'PHPUnit WhatsApp',
            'phone_number'    => '+34600999888',
            'phone_number_id' => $phoneNumberId,
            'waba_id'         => 'test-waba',
            'verify_token'    => bin2hex(random_bytes(32)),
            'access_token'    => self::TEST_ACCESS_TOKEN . '-long-enough',
            'app_secret'      => self::TEST_APP_SECRET . '-long-enough',
            'mailbox_mode'    => 'new',
            'mailbox_name'    => 'PHPUnit WhatsApp Mailbox',
        ], $overrides);
    }

    public function test_new_mailbox_gets_exactly_one_folder_per_public_type()
    {
        $this->fakeWebhookSubscription();
        $admin   = $this->makeAdminUser();
        $payload = $this->storePayload();

        $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/settings'), $payload);

        $account = WhatsAppAccount::where('phone_number_id', $payload['phone_number_id'])->first();
        $this->assertNotNull($account, 'the account was not created');

        foreach (Folder::$public_types as $type) {
            $this->assertEquals(
                1,
                Folder::where('mailbox_id', $account->mailbox_id)->where('type', $type)->count(),
                'public folder type ' . $type . ' is duplicated in the new mailbox (issue #30)'
            );
        }
    }

    /**
     * The same assertion stated the other way round, so the test still fails
     * if a future core release moves folder creation out of the observer:
     * the mailbox must end up with folders without the module creating any.
     */
    public function test_saving_a_mailbox_alone_already_creates_its_folders()
    {
        $mailbox = new Mailbox();
        $mailbox->name       = 'PHPUnit folders';
        $mailbox->email      = 'whatsapp-folders-' . uniqid() . '@channel.internal';
        $mailbox->out_method = Mailbox::OUT_METHOD_PHP_MAIL;
        $mailbox->in_server  = '';
        $mailbox->out_server = '';
        $mailbox->save();

        foreach (Folder::$public_types as $type) {
            $this->assertEquals(
                1,
                Folder::where('mailbox_id', $mailbox->id)->where('type', $type)->count(),
                'the core observer no longer creates public folder type ' . $type
                . '; the module relies on it since issue #30'
            );
        }
    }
}
