<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\AccountEvent;
use Modules\MetaWhatsApp\Support\AccountEventLog;

/**
 * An optional record of what Meta has told us about the account. Off by
 * default, bounded by retention, and account-level only: never anything
 * that identifies a customer.
 */
class AccountEventLogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_nothing_is_recorded_while_the_audit_is_switched_off()
    {
        \Option::set('metawhatsapp.account_events', '');
        $account = $this->createTestAccount();

        AccountEventLog::record($account, 'message_template_status_update', 'warning', ['event' => 'REJECTED']);

        $this->assertEquals(0, AccountEvent::where('account_id', $account->id)->count());
    }

    public function test_an_event_is_recorded_while_the_audit_is_switched_on()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount();

        AccountEventLog::record($account, 'message_template_status_update', 'warning', ['event' => 'REJECTED']);

        $this->assertEquals(1, AccountEvent::where('account_id', $account->id)->count());
    }

    /**
     * Option::set() does not invalidate Option's static cache, so a cached
     * read would leave a long-lived queue worker with the value it started
     * with. Reproduced here inside a single process: read, change, read again.
     */
    public function test_switching_the_audit_on_takes_effect_without_restarting_the_queue()
    {
        \Option::set('metawhatsapp.account_events', '');
        $account = $this->createTestAccount();

        // This first call is what poisons the cache if the read is cached.
        AccountEventLog::record($account, 'message_template_status_update', 'warning', ['event' => 'REJECTED']);

        \Option::set('metawhatsapp.account_events', '1');
        AccountEventLog::record($account, 'message_template_status_update', 'warning', ['event' => 'PAUSED']);

        $this->assertEquals(
            1,
            AccountEvent::where('account_id', $account->id)->count(),
            'The flag is read from a long-lived queue worker; a cached read would ignore the change until a restart.'
        );
    }

    public function test_rows_older_than_the_retention_are_removed_and_the_rest_are_left()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount();

        $old = AccountEvent::create([
            'account_id' => $account->id,
            'event_type' => 'message_template_status_update',
            'severity'   => 'warning',
            'details'    => '{}',
        ]);
        $old->created_at = now()->subDays(AccountEventLog::RETENTION_DAYS + 1);
        $old->save();

        $withinWindow = AccountEvent::create([
            'account_id' => $account->id,
            'event_type' => 'message_template_status_update',
            'severity'   => 'warning',
            'details'    => '{}',
        ]);
        $withinWindow->created_at = now()->subDays(AccountEventLog::RETENTION_DAYS - 1);
        $withinWindow->save();

        AccountEventLog::record($account, 'message_template_status_update', 'warning', ['event' => 'REJECTED']);

        $this->assertEquals(2, AccountEvent::where('account_id', $account->id)->count());
        $this->assertNull(AccountEvent::find($old->id));
        $this->assertNotNull(AccountEvent::find($withinWindow->id));
    }

    public function test_an_administrator_can_read_the_events()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount();
        AccountEventLog::record($account, 'message_template_status_update', 'warning', ['event' => 'REJECTED', 'template' => 'order_update']);

        $response = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/meta-whatsapp/settings/events'));

        $response->assertStatus(200);
        $this->assertStringContainsString('order_update', $response->getContent());
        $this->assertStringContainsString($account->name, $response->getContent());
    }

    public function test_a_non_administrator_cannot_read_the_events()
    {
        $agent = new \App\User();
        $agent->first_name = 'Agent';
        $agent->last_name  = 'Test';
        $agent->email      = 'agent-' . uniqid() . '@example.com';
        $agent->password   = bcrypt('secret');
        $agent->role       = \App\User::ROLE_USER;
        $agent->save();

        $this->actingAs($agent)
            ->get($this->url('/meta-whatsapp/settings/events'))
            ->assertStatus(403);
    }

    /**
     * The only way to turn this on used to be an SQL update or tinker, which
     * defeats the point on shared hosting with no shell. The switch lives in
     * the same panel as the detailed log.
     */
    public function test_an_administrator_can_switch_the_record_on_from_the_panel()
    {
        \Option::set(AccountEventLog::OPTION_ENABLED, '');

        $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post(route('metawhatsapp.diagnostics'), [
                'account_events'  => '1',
                'debug_retention' => 7,
            ]);

        $this->assertTrue(AccountEventLog::isEnabled());
    }

    public function test_an_administrator_can_switch_the_record_off_from_the_panel()
    {
        \Option::set(AccountEventLog::OPTION_ENABLED, '1');

        $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post(route('metawhatsapp.diagnostics'), [
                'debug_retention' => 7,
            ]);

        $this->assertFalse(AccountEventLog::isEnabled());
    }

    /**
     * The rule is enforced here rather than trusted at each call site, so a
     * family that forgets its allow-list stores nothing instead of storing
     * whatever it was handed.
     */
    public function test_a_field_nobody_declared_is_not_stored()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount();

        AccountEventLog::record($account, 'message_template_status_update', 'warning', [
            'event' => 'REJECTED',
            'phone' => '+34611222333',
        ]);

        $details = AccountEvent::where('account_id', $account->id)->value('details');
        $this->assertStringContainsString('REJECTED', $details);
        $this->assertStringNotContainsString('34611222333', $details);
    }

    public function test_an_undeclared_event_type_stores_no_details_at_all()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount();

        AccountEventLog::record($account, 'something_nobody_declared', 'info', ['anything' => 'at all']);

        $event = AccountEvent::where('account_id', $account->id)->first();
        $this->assertNotNull($event, 'The event itself is still recorded; only its fields are dropped.');
        $this->assertEquals('[]', $event->details);
    }
}
