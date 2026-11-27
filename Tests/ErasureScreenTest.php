<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

class ErasureScreenTest extends TestCase
{
    use DatabaseTransactions;

    private function heldRow($account, string $phone): WhatsAppMessage
    {
        $message = new WhatsAppMessage();
        $message->account_id    = $account->id;
        $message->wamid         = 'wamid.screen-' . uniqid();
        $message->direction     = WhatsAppMessage::DIRECTION_INBOUND;
        $message->status        = WhatsAppMessage::STATUS_RECEIVED;
        $message->contact_phone = $phone;
        $message->save();

        return $message;
    }

    private function agent(): \App\User
    {
        $user = new \App\User();
        $user->first_name = 'Agent';
        $user->last_name  = 'Only';
        $user->email      = 'agent-' . uniqid() . '@example.com';
        $user->password   = bcrypt('secret');
        $user->role       = \App\User::ROLE_USER;
        $user->save();

        return $user;
    }

    public function test_a_non_admin_cannot_reach_the_screen_the_search_or_the_erasure()
    {
        $agent = $this->agent();

        $this->actingAs($agent)->get($this->url('/meta-whatsapp/erasure'))->assertStatus(403);

        $this->actingAs($agent)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/erasure/search'), ['identifier' => '+34611100001'])
            ->assertStatus(403);

        $this->actingAs($agent)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/erasure/erase'), ['identifier' => '+34611100001'])
            ->assertStatus(403);
    }

    public function test_an_admin_can_open_the_screen()
    {
        $response = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/meta-whatsapp/erasure'));

        $response->assertStatus(200);
        $this->assertStringContainsString('name="identifier"', $response->getContent());
    }

    public function test_the_search_shows_what_we_hold()
    {
        $account = $this->createTestAccount();
        $this->heldRow($account, '+34611100002');

        $response = $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/erasure/search'), ['identifier' => '+34611100002']);

        $response->assertStatus(200);
        $this->assertStringContainsString('+34611100002', $response->getContent());
    }

    /**
     * Through the door, because the figure is only worth anything if the
     * administrator reads it before pressing the button.
     *
     * The record count on this screen includes records this module never
     * created: the column it searches is the one Customer::byPhone()
     * searches, so a number left on anybody's record goes on resolving to
     * this person. The line says how many of them hold other data as well,
     * which is how an administrator spots their own imported contact among
     * them.
     */
    public function test_the_screen_warns_when_a_record_holds_other_data_as_well()
    {
        $account = $this->createTestAccount();
        $this->heldRow($account, '+34611100021');

        $imported = new \App\Customer();
        $imported->first_name = 'Imported';
        $imported->last_name  = 'Contact';
        $imported->save();
        $imported->setPhones([
            ['value' => '+34611100021', 'type' => \App\Customer::PHONE_TYPE_WORK],
            ['value' => '+34655100099', 'type' => \App\Customer::PHONE_TYPE_MOBILE],
        ]);
        $imported->save();

        $response = $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/erasure/search'), ['identifier' => '+34611100021']);

        $response->assertStatus(200);
        $this->assertStringContainsString(
            e(__('metawhatsapp::metawhatsapp.erasure_records_other_data', ['count' => 1])),
            $response->getContent(),
            'the screen counted the record but never said it holds other data too'
        );
    }

    public function test_the_search_says_nothing_held_for_an_identifier_never_seen_at_all()
    {
        $response = $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/erasure/search'), ['identifier' => '+34611199999']);

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString(
            e(__('metawhatsapp::metawhatsapp.erasure_no_results')),
            $content
        );
        // An empty search is the worst place to abbreviate the list. It looks
        // identical to "we never heard of this person" while their traffic may
        // sit under a second portfolio, or their messages in the debug log.
        foreach (['conversations', 'customer', 'queue', 'debuglog', 'portfolios'] as $line) {
            $this->assertStringContainsString(
                e(__('metawhatsapp::metawhatsapp.erasure_receipt_remains_' . $line)),
                $content,
                'the empty-result disclaimer dropped the "' . $line . '" line'
            );
        }
    }

    /**
     * The receipt is the one place in the whole design that names the
     * person, which is exactly why it must say what is left behind too.
     */
    public function test_the_receipt_names_the_identifier_the_counts_and_what_remains()
    {
        $account = $this->createTestAccount();
        $this->heldRow($account, '+34611100003');

        $response = $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/erasure/erase'), ['identifier' => '+34611100003']);

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('+34611100003', $content);
        // The view escapes every translation it prints (deliberately: this
        // text can be overridden from FreeScout's Manage > Translate
        // screen, and an unescaped override would render as markup), so an
        // assertion has to compare against the escaped form too. Comparing
        // the raw string would eventually push someone into disabling
        // escaping in the view just to make an assertion match.
        $this->assertStringContainsString(e(__('metawhatsapp::metawhatsapp.erasure_receipt_kept')), $content);
        $this->assertStringContainsString(e(__('metawhatsapp::metawhatsapp.erasure_receipt_remains_conversations')), $content);
        $this->assertStringContainsString(e(__('metawhatsapp::metawhatsapp.erasure_receipt_remains_customer')), $content);
        $this->assertStringContainsString(e(__('metawhatsapp::metawhatsapp.erasure_receipt_remains_queue')), $content);
        // The debug log is the copy nothing else removes. Since v2.1.0 its
        // retention reaches 365 days and 0 means forever, so a receipt that
        // leaves it out claims a completeness it does not have.
        $this->assertStringContainsString(e(__('metawhatsapp::metawhatsapp.erasure_receipt_remains_debuglog')), $content);
        $this->assertStringContainsString(e(__('metawhatsapp::metawhatsapp.erasure_receipt_remains_portfolios')), $content);
        $this->assertStringContainsString(e(__('metawhatsapp::metawhatsapp.erasure_receipt_warning')), $content);
    }

    /**
     * The receipt's numbers are what makes it a receipt rather than a
     * reassurance: a count that silently rounds or drops a row would be
     * worse than no screen at all for whoever relies on it.
     */
    public function test_the_receipt_counts_match_what_was_actually_held()
    {
        $account = $this->createTestAccount();
        $phone   = '+34611100006';

        $this->heldRow($account, $phone);
        $this->heldRow($account, $phone);
        $this->heldRow($account, $phone);

        // Two distinct channel rows under the same identifier: the
        // CHANNEL type for one and CHANNEL_BSUID for the other, because
        // customer_channels has a unique constraint on (channel,
        // channel_id), so a second row under the same CHANNEL would
        // silently fail to insert (see addChannel()/CustomerChannel::create()).
        $first = new \App\Customer();
        $first->first_name = 'First side';
        $first->save();
        $first->addChannel(\Modules\MetaWhatsApp\Models\WhatsAppAccount::CHANNEL, $phone);

        $second = new \App\Customer();
        $second->first_name = 'Second side';
        $second->save();
        $second->addChannel(\Modules\MetaWhatsApp\Models\WhatsAppAccount::CHANNEL_BSUID, $phone);

        $response = $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/erasure/erase'), ['identifier' => $phone]);

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('from 3 message record(s)', $content);
        $this->assertStringContainsString('2 contact channel record(s)', $content);
    }

    /**
     * The confirmation is the only thing standing between a misclick and
     * an unrecoverable erasure, and an inline onsubmit is exactly what
     * FreeScout's CSP blocks: a handler that is silently never called
     * would let the form submit on the first click with no dialog.
     */
    public function test_the_erase_form_confirms_through_a_script_listener_not_an_inline_handler()
    {
        $account = $this->createTestAccount();
        $this->heldRow($account, '+34611100007');

        $response = $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/erasure/search'), ['identifier' => '+34611100007']);

        $content = $response->getContent();

        $this->assertStringContainsString('class="mwa-erasure-form"', $content);
        $this->assertStringNotContainsString('onsubmit', $content);

        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $content, $scripts);
        $scriptBodies = implode("\n", $scripts[1] ?? []);

        $this->assertStringContainsString(
            json_encode(__('metawhatsapp::metawhatsapp.erasure_confirm')),
            $scriptBodies,
            'the confirm text was not found inside a script block'
        );
    }

    public function test_a_resubmitted_erasure_says_there_was_nothing_left()
    {
        $account = $this->createTestAccount();
        $this->heldRow($account, '+34611100004');
        $admin = $this->makeAdminUser();

        $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/erasure/erase'), ['identifier' => '+34611100004']);

        $second = $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/erasure/erase'), ['identifier' => '+34611100004']);

        $second->assertStatus(200);
        $this->assertStringContainsString(
            e(__('metawhatsapp::metawhatsapp.erasure_nothing_to_erase')),
            $second->getContent()
        );
    }

    /**
     * A screen that exists to prove what is left cannot answer "nothing"
     * while the phone is still the customer's display name one click away.
     */
    public function test_a_search_after_an_erasure_repeats_what_is_out_of_our_reach()
    {
        $account = $this->createTestAccount();
        $this->heldRow($account, '+34611100005');
        $admin = $this->makeAdminUser();

        $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/erasure/erase'), ['identifier' => '+34611100005']);

        $response = $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/erasure/search'), ['identifier' => '+34611100005']);

        $this->assertStringContainsString(
            e(__('metawhatsapp::metawhatsapp.erasure_receipt_remains_conversations')),
            $response->getContent(),
            'the empty result claimed completeness without repeating what is out of our reach'
        );
    }
}
