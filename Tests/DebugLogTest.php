<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Support\DebugLog;

/**
 * Detailed logging: a window and a retention, not a switch.
 *
 * The file records message text and phone numbers, and it is the one place
 * erasure cannot reach: deleting a customer or a conversation cannot rewrite
 * a rotating log file. So the control that matters is how long the window is,
 * and both that and the retention belong to the administrator, whose server
 * and whose data it is.
 */
class DebugLogTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['metawhatsapp.debug' => false]);

        // Les opcions viuen a la base de dades i sobreviuen a qualsevol cosa
        // que s'hagi fet abans des del navegador o des d'un altre test. Cada
        // test ha de partir d'un estat que ell mateix ha fixat, no del que hi
        // hagi hagut abans: si no, el que comprova el valor per defecte
        // acaba llegint el que algú va desar la setmana passada.
        DebugLog::disable();
        \Option::remove(DebugLog::OPTION_RETENTION);
    }

    public function test_it_is_off_until_someone_turns_it_on()
    {
        $this->assertFalse(DebugLog::isEnabled());
        $this->assertNull(DebugLog::expiresAt());
    }

    public function test_a_window_switches_it_on_and_reports_when_it_ends()
    {
        DebugLog::enableFor(3);

        $this->assertTrue(DebugLog::isEnabled());
        $this->assertNotNull(DebugLog::expiresAt());
        $this->assertEqualsWithDelta(3, now()->diffInDays(DebugLog::expiresAt(), false), 1);
        $this->assertFalse(DebugLog::isAlwaysOn());
    }

    /**
     * The whole point of a window: it closes on its own. A switch someone
     * leaves on is a copy of customer conversations growing for months.
     */
    public function test_a_window_that_has_run_out_is_off()
    {
        \Option::set(DebugLog::OPTION_UNTIL, now()->subMinute()->toDateTimeString());

        $this->assertFalse(DebugLog::isEnabled());

        // Still readable, so the panel can say when it ended rather than
        // pretending it was never on.
        $this->assertNotNull(DebugLog::expiresAt());
    }

    public function test_always_on_is_allowed_because_the_choice_is_not_ours()
    {
        DebugLog::enableFor(null);

        $this->assertTrue(DebugLog::isEnabled());
        $this->assertTrue(DebugLog::isAlwaysOn());
        $this->assertNull(DebugLog::expiresAt(), 'always on has no end date to show');
    }

    public function test_the_env_flag_still_wins_on_its_own()
    {
        config(['metawhatsapp.debug' => true]);
        DebugLog::disable();

        $this->assertTrue(DebugLog::isEnabled(), 'an install configured through .env stopped working');
        $this->assertTrue(DebugLog::isForcedByEnv());
    }

    public function test_retention_defaults_to_what_the_module_shipped_with()
    {
        $this->assertEquals(DebugLog::DEFAULT_RETENTION_DAYS, DebugLog::retentionDays());
    }

    public function test_retention_is_configurable()
    {
        DebugLog::setRetentionDays(30);
        $this->assertEquals(30, DebugLog::retentionDays());
    }

    /**
     * MarkoD asked for this directly (issue #10): Monolog's
     * RotatingFileHandler already reads a maxFiles of 0 as "keep every
     * file forever", so 0 here means exactly that literal thing, not a
     * fallback to the default.
     */
    public function test_retention_of_zero_means_keep_every_file_forever()
    {
        DebugLog::setRetentionDays(0);
        $this->assertEquals(0, DebugLog::retentionDays());
    }

    public function test_retention_rejects_negative_values()
    {
        DebugLog::setRetentionDays(-5);
        $this->assertEquals(0, DebugLog::retentionDays());
    }

    public function test_the_panel_says_which_of_the_three_states_it_is_in()
    {
        $render = function (array $debug) {
            return \View::make('metawhatsapp::partials/diagnostics', ['debug' => $debug])->render();
        };

        $off = $render(['enabled' => false, 'forced_env' => false, 'always' => false, 'expires_at' => null, 'retention' => 7]);
        $this->assertStringContainsString(__('metawhatsapp::metawhatsapp.diagnostics_off'), $off);

        $until = $render(['enabled' => true, 'forced_env' => false, 'always' => false, 'expires_at' => now()->addDays(2), 'retention' => 7]);
        $this->assertStringContainsString(now()->addDays(2)->format('Y-m-d'), $until);

        $always = $render(['enabled' => true, 'forced_env' => false, 'always' => true, 'expires_at' => null, 'retention' => 7]);
        $this->assertStringContainsString(__('metawhatsapp::metawhatsapp.diagnostics_on_always'), $always);
    }

    /**
     * Someone who set this up through .env must be told, or they will change
     * the dropdown here and wonder why nothing happens.
     */
    public function test_the_panel_warns_when_the_env_flag_overrides_it()
    {
        $rendered = \View::make('metawhatsapp::partials/diagnostics', [
            'debug' => ['enabled' => true, 'forced_env' => true, 'always' => false, 'expires_at' => null, 'retention' => 7],
        ])->render();

        $this->assertStringContainsString('METAWHATSAPP_DEBUG', $rendered);
    }

    /**
     * El defecte que va ensenyar la captura i no els tests: si el desplegable
     * tornés per defecte a "apagat", qui vingués només a canviar els dies de
     * retenció apagaria el registre sense adonar-se'n.
     */
    public function test_saving_without_touching_the_window_leaves_it_alone()
    {
        $admin = new \App\User();
        $admin->first_name = 'Admin';
        $admin->last_name  = 'Test';
        $admin->email      = 'admin-' . uniqid() . '@example.com';
        $admin->password   = bcrypt('secret');
        $admin->role       = \App\User::ROLE_ADMIN;
        $admin->save();

        DebugLog::enableFor(3);
        $before = DebugLog::expiresAt();

        $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/diagnostics'), [
                'debug_window'    => '',
                'debug_retention' => 21,
            ]);

        $this->assertTrue(DebugLog::isEnabled(), 'canviar la retenció ha apagat el registre');
        $this->assertEquals($before->format('Y-m-d H:i'), DebugLog::expiresAt()->format('Y-m-d H:i'));
        $this->assertEquals(21, DebugLog::retentionDays());
    }

    /**
     * The door, not just the class: confirms the controller's own
     * validation actually accepts 0 and the raised 365 ceiling (issue #10),
     * not only that DebugLog itself would store them if asked.
     */
    public function test_the_settings_form_accepts_zero_and_the_raised_ceiling()
    {
        $admin = new \App\User();
        $admin->first_name = 'Admin';
        $admin->last_name  = 'Test';
        $admin->email      = 'admin-' . uniqid() . '@example.com';
        $admin->password   = bcrypt('secret');
        $admin->role       = \App\User::ROLE_ADMIN;
        $admin->save();

        $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/diagnostics'), [
                'debug_window'    => '',
                'debug_retention' => 0,
            ]);
        $this->assertEquals(0, DebugLog::retentionDays());

        $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/diagnostics'), [
                'debug_window'    => '',
                'debug_retention' => 365,
            ]);
        $this->assertEquals(365, DebugLog::retentionDays());
    }
}
