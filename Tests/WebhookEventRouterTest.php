<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\AccountEvent;
use Modules\MetaWhatsApp\Support\WebhookEventRouter;

/**
 * Subscribing a number to Meta's webhooks subscribes it to every field, so
 * template status changes, quality ratings and account alerts all arrive
 * alongside messages. The router is what tells them apart.
 */
class WebhookEventRouterTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * A template belongs to the WABA, not to a number. With two channels on
     * one WABA, showing the problem on only one of them leaves the other
     * saying, by the absence of a row, that it has none.
     */
    public function test_a_broken_template_is_reported_on_every_channel_of_that_waba()
    {
        $one = $this->createTestAccount(['waba_id' => 'waba-shared']);
        $two = $this->createTestAccount(['waba_id' => 'waba-shared']);

        WebhookEventRouter::route($one, $this->templateStatusChange('REJECTED', 'order_update', 'en_US'));

        $this->assertCount(1, $one->fresh()->templates_issue);
        $this->assertCount(1, $two->fresh()->templates_issue, 'The other channel on the same WABA is broken too.');
    }

    public function test_a_channel_on_another_waba_is_left_alone()
    {
        $mine     = $this->createTestAccount(['waba_id' => 'waba-mine']);
        $somebody = $this->createTestAccount(['waba_id' => 'waba-theirs']);

        WebhookEventRouter::route($mine, $this->templateStatusChange('REJECTED', 'order_update', 'en_US'));

        $this->assertNull($somebody->fresh()->templates_issue);
    }

    public function test_a_rejected_template_is_recorded_on_the_account()
    {
        $account = $this->createTestAccount();

        WebhookEventRouter::route($account, $this->templateStatusChange('REJECTED', 'order_update', 'en_US'));

        $issues = $account->fresh()->templates_issue;
        $this->assertCount(1, $issues);
        $issue = reset($issues);
        $this->assertStringContainsString('order_update', $issue['summary']);
        $this->assertStringContainsString('REJECTED', $issue['summary']);
        $this->assertNotNull($issue['at']);
    }

    public function test_an_approved_template_clears_the_problem_it_was_about()
    {
        $account = $this->createTestAccount();

        WebhookEventRouter::route($account, $this->templateStatusChange('REJECTED', 'order_update', 'en_US'));
        WebhookEventRouter::route($account, $this->templateStatusChange('APPROVED', 'order_update', 'en_US'));

        $this->assertNull($account->fresh()->templates_issue, 'A fixed problem must leave the panel, or the row becomes wallpaper.');
    }

    public function test_an_approval_for_a_different_template_does_not_hide_the_problem()
    {
        $account = $this->createTestAccount();

        WebhookEventRouter::route($account, $this->templateStatusChange('REJECTED', 'order_update', 'en_US'));
        WebhookEventRouter::route($account, $this->templateStatusChange('APPROVED', 'welcome', 'en_US'));

        $issues = $account->fresh()->templates_issue;
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('order_update', json_encode($issues));
    }

    /**
     * Two broken templates used to mean the panel forgot the first one, and
     * fixing the second cleared the row entirely while the first was still
     * rejected. Under a rule that says rows appear only when there is
     * something to say, that missing row asserted there was nothing.
     */
    public function test_both_broken_templates_are_kept_and_fixing_one_leaves_the_other()
    {
        $account = $this->createTestAccount();

        WebhookEventRouter::route($account, $this->templateStatusChange('REJECTED', 'order_update', 'en_US'));
        WebhookEventRouter::route($account, $this->templateStatusChange('PAUSED', 'welcome', 'es_ES'));

        $issues = $account->fresh()->templates_issue;
        $this->assertCount(2, $issues);

        WebhookEventRouter::route($account, $this->templateStatusChange('APPROVED', 'welcome', 'es_ES'));

        $issues = $account->fresh()->templates_issue;
        $this->assertCount(1, $issues, 'Fixing one template must not clear the other.');
        $this->assertStringContainsString('order_update', json_encode($issues));
    }

    public function test_fixing_the_only_broken_template_empties_the_row()
    {
        $account = $this->createTestAccount();

        WebhookEventRouter::route($account, $this->templateStatusChange('REJECTED', 'order_update', 'en_US'));
        WebhookEventRouter::route($account, $this->templateStatusChange('APPROVED', 'order_update', 'en_US'));

        $this->assertNull($account->fresh()->templates_issue);
    }

    /**
     * The identity is the key, not the sentence on screen. If clearing ever
     * goes back to matching a prefix of the display text, rewording the panel
     * breaks it silently, which is what this guards.
     */
    public function test_a_template_whose_summary_would_be_truncated_can_still_be_cleared()
    {
        $account = $this->createTestAccount();
        $long    = str_repeat('a', 200);

        WebhookEventRouter::route($account, $this->templateStatusChange('REJECTED', $long, 'en_US'));
        $this->assertCount(1, $account->fresh()->templates_issue);

        WebhookEventRouter::route($account, $this->templateStatusChange('APPROVED', $long, 'en_US'));
        $this->assertNull($account->fresh()->templates_issue);
    }

    /**
     * PENDING_DELETION means the administrator already asked Meta to delete
     * the template. Treating it as a problem raised a row telling them to go
     * and fix something they had chosen, and nothing would ever clear it: no
     * approval is coming for a template being deleted.
     */
    public function test_a_template_the_administrator_is_deleting_is_not_a_problem()
    {
        $account = $this->createTestAccount();

        WebhookEventRouter::route($account, $this->templateStatusChange('PENDING_DELETION', 'old_promo', 'en_US'));

        $this->assertNull($account->fresh()->templates_issue);
    }

    /**
     * The same field carries a quality change with no `event` at all. It is not
     * handled in this slice and must not be mistaken for a status change.
     */
    public function test_a_quality_change_on_the_same_field_is_not_treated_as_a_status_change()
    {
        $account = $this->createTestAccount();

        WebhookEventRouter::route($account, ['field' => 'message_template_status_update', 'value' => [
            'previous_quality_score'    => 'GREEN',
            'new_quality_score'         => 'RED',
            'message_template_id'       => 123456,
            'message_template_name'     => 'order_update',
            'message_template_language' => 'en_US',
        ]]);

        $this->assertNull($account->fresh()->templates_issue);
    }

    /**
     * The example used to be account_alerts, which #66 now handles. A field
     * this module really does not read is the right example, and
     * message_echoes is the one with a reason beside it: the module does not
     * mirror an agent's reply back into the conversation it came from.
     */
    public function test_an_unknown_field_says_what_arrived()
    {
        \Log::spy();
        $account = $this->createTestAccount();

        WebhookEventRouter::route($account, ['field' => 'message_echoes', 'value' => ['messages' => []]]);

        \Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) {
            return strpos($message, 'not handled') !== false
                && ($context['field'] ?? null) === 'message_echoes';
        })->once();
    }

    public function test_the_panel_names_the_broken_template()
    {
        $account = $this->createTestAccount();
        WebhookEventRouter::route($account, $this->templateStatusChange('REJECTED', 'order_update', 'en_US', 'INCORRECT_CATEGORY'));

        $response = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/meta-whatsapp/settings/' . $account->id . '/edit'));

        $response->assertStatus(200);
        $this->assertStringContainsString('order_update', $response->getContent());
        $this->assertStringContainsString(__('metawhatsapp::metawhatsapp.templates_issue_help'), $response->getContent());
    }

    public function test_the_panel_says_nothing_about_templates_when_there_is_no_problem()
    {
        $account = $this->createTestAccount();

        $response = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/meta-whatsapp/settings/' . $account->id . '/edit'));

        // The status assertion is what gives the next one meaning: an error
        // page does not mention templates either.
        $response->assertStatus(200);
        $this->assertStringNotContainsString(__('metawhatsapp::metawhatsapp.templates_issue_title'), $response->getContent());
    }

    /**
     * The rule is not "no personal data" as an intention, it is this: a change
     * that also carries a contacts block must produce an audit row holding
     * neither the phone number nor the business-scoped id.
     */
    public function test_the_audit_row_never_carries_anything_that_identifies_a_customer()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount();

        $change = $this->templateStatusChange('REJECTED', 'order_update', 'en_US');
        $change['value']['contacts'] = [[
            'profile' => ['name' => 'Pablo M.'],
            'wa_id'   => '34611222333',
            'user_id' => 'US.13491208655302741918',
        ]];

        WebhookEventRouter::route($account, $change);

        $details = \Modules\MetaWhatsApp\Models\AccountEvent::where('account_id', $account->id)->value('details');
        $this->assertStringNotContainsString('34611222333', $details);
        $this->assertStringNotContainsString('US.13491208655302741918', $details);
        $this->assertStringNotContainsString('Pablo M.', $details);
    }

    /**
     * Meta redelivers webhooks until it gets a 2xx. The panel presents the
     * date as when the problem started, so a redelivery must not move it.
     */
    public function test_a_redelivery_of_the_same_event_changes_nothing()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount();

        WebhookEventRouter::route($account, $this->templateStatusChange('REJECTED', 'order_update', 'en_US'));
        $firstIssues = $account->fresh()->templates_issue;
        $firstSeen   = reset($firstIssues)['at'];

        WebhookEventRouter::route($account, $this->templateStatusChange('REJECTED', 'order_update', 'en_US'));

        $issues = $account->fresh()->templates_issue;
        $this->assertCount(1, $issues);
        $this->assertEquals(
            $firstSeen,
            reset($issues)['at'],
            'A redelivery must not move the date the panel presents as when the problem started.'
        );
        $this->assertEquals(
            1,
            \Modules\MetaWhatsApp\Models\AccountEvent::where('account_id', $account->id)->count(),
            'A redelivery must not add a second identical audit row.'
        );
    }

    /**
     * When the panel row disappears, the record is what tells someone what
     * happened: not only that a template broke, but also that it was fixed.
     */
    public function test_the_record_keeps_the_resolution_and_not_only_the_problem()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount();

        WebhookEventRouter::route($account, $this->templateStatusChange('REJECTED', 'order_update', 'en_US'));
        WebhookEventRouter::route($account, $this->templateStatusChange('APPROVED', 'order_update', 'en_US'));

        $this->assertEquals(2, \Modules\MetaWhatsApp\Models\AccountEvent::where('account_id', $account->id)->count());
    }

    public function test_a_category_change_is_recorded_and_not_put_on_the_panel()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount();

        WebhookEventRouter::route($account, ['field' => 'template_category_update', 'value' => [
            'message_template_id'       => 1,
            'message_template_name'     => 'order_update',
            'message_template_language' => 'en_US',
            'previous_category'         => 'MARKETING',
            'new_category'              => 'UTILITY',
        ]]);

        $this->assertNull($account->fresh()->templates_issue, 'A price change is not a fault and does not belong on the panel.');

        $event = \Modules\MetaWhatsApp\Models\AccountEvent::where('account_id', $account->id)->first();
        $this->assertEquals('template_category_update', $event->event_type);
        $this->assertEquals('info', $event->severity);
        $this->assertStringContainsString('MARKETING', $event->details);
        $this->assertStringContainsString('UTILITY', $event->details);
    }

    /**
     * Meta's naming is a trap: in the warning sent 24 hours ahead,
     * `new_category` is what the template still is and `correct_category` is
     * what it will become. Reading "new" as the new one gets it backwards.
     */
    public function test_the_warning_sent_ahead_reads_metas_fields_the_right_way_round()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount();

        WebhookEventRouter::route($account, ['field' => 'template_category_update', 'value' => [
            'message_template_id'       => 1,
            'message_template_name'     => 'order_update',
            'message_template_language' => 'en_US',
            'new_category'              => 'UTILITY',
            'correct_category'          => 'MARKETING',
        ]]);

        $event   = \Modules\MetaWhatsApp\Models\AccountEvent::where('account_id', $account->id)->first();
        $details = json_decode($event->details, true);

        $this->assertEquals('impending', $details['stage']);
        $this->assertEquals('UTILITY', $details['from'], 'new_category is what it still is.');
        $this->assertEquals('MARKETING', $details['to'], 'correct_category is what it will become.');
        $this->assertEquals('warning', $event->severity, 'There are 24 hours to appeal, so it is actionable.');
    }

    public function test_a_category_change_reaches_every_channel_of_that_waba()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $one = $this->createTestAccount(['waba_id' => 'waba-shared']);
        $two = $this->createTestAccount(['waba_id' => 'waba-shared']);

        WebhookEventRouter::route($one, ['field' => 'template_category_update', 'value' => [
            'message_template_id'       => 1,
            'message_template_name'     => 'order_update',
            'message_template_language' => 'en_US',
            'previous_category'         => 'MARKETING',
            'new_category'              => 'UTILITY',
        ]]);

        $this->assertEquals(1, \Modules\MetaWhatsApp\Models\AccountEvent::where('account_id', $two->id)->count());
    }

    /**
     * A family whose scope nobody declared touches only the channel the
     * webhook resolved, and says so. Writing to fewer channels is the smaller
     * mistake, and the warning is what turns a silent omission into something
     * somebody fixes.
     */
    public function test_a_family_with_no_declared_scope_touches_one_channel_and_says_so()
    {
        \Log::spy();
        $one = $this->createTestAccount(['waba_id' => 'waba-shared']);
        $two = $this->createTestAccount(['waba_id' => 'waba-shared']);

        $method = new \ReflectionMethod(WebhookEventRouter::class, 'channelsFor');
        $method->setAccessible(true);
        $channels = $method->invoke(null, $one, 'a_family_nobody_declared');

        $this->assertCount(1, $channels);
        $this->assertEquals($one->id, $channels[0]->id);

        \Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
            return strpos($message, 'no declared scope') !== false
                && ($context['field'] ?? null) === 'a_family_nobody_declared';
        })->once();
    }

    /**
     * Issue #66, found by MarkoD on #62. The account events switch promised a
     * record of "quality ratings and restrictions" and the router knew two
     * fields, both about templates, so an administrator who turned it on for
     * that sentence got an empty screen and the screen's own empty text told
     * them either nothing had happened or the record was off. It shipped in
     * 3.0.0, so it is a defect of ours.
     *
     * The payloads in these tests come from Meta's own reference pages, from
     * the dated corpus in docs/referencia/meta-whatsapp-docs-2026-11-22.
     *
     * A restriction is the one that stops an agent's reply, so it is an error
     * and it carries which restriction: a business that cannot start
     * conversations and one that cannot answer them have different days
     * ahead.
     */
    public function test_an_account_restriction_is_recorded_as_an_error_with_its_type()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount(['waba_id' => 'waba-restricted']);

        WebhookEventRouter::route($account, ['field' => 'account_update', 'value' => [
            'event'            => 'ACCOUNT_RESTRICTION',
            'restriction_info' => [
                ['restriction_type' => 'RESTRICTED_BIZ_INITIATED_MESSAGING', 'expiration' => 0],
                ['restriction_type' => 'RESTRICTED_CUSTOMER_INITIATED_MESSAGING', 'expiration' => 0],
            ],
        ]]);

        $row = AccountEvent::where('account_id', $account->id)->first();
        $this->assertNotNull($row, 'a restriction on the account left no row');
        $this->assertEquals('account_update', $row->event_type);
        $this->assertEquals('error', $row->severity);

        $details = json_decode($row->details, true);
        $this->assertEquals('ACCOUNT_RESTRICTION', $details['event']);
        $this->assertStringContainsString('RESTRICTED_BIZ_INITIATED_MESSAGING', $details['restriction_types']);
        $this->assertStringContainsString('RESTRICTED_CUSTOMER_INITIATED_MESSAGING', $details['restriction_types']);
    }

    /** A violation and a ban are the same family and the same severity. */
    public function test_a_violation_and_a_ban_are_recorded()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount(['waba_id' => 'waba-violating']);

        WebhookEventRouter::route($account, ['field' => 'account_update', 'value' => [
            'event'          => 'ACCOUNT_VIOLATION',
            'violation_info' => ['violation_type' => 'ADULT'],
        ]]);
        WebhookEventRouter::route($account, ['field' => 'account_update', 'value' => [
            'event'    => 'DISABLED_UPDATE',
            'ban_info' => ['waba_ban_state' => 'SCHEDULE_FOR_DISABLE', 'waba_ban_date' => 'April 17, 2025'],
        ]]);

        $rows = AccountEvent::where('account_id', $account->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);

        $violation = json_decode($rows[0]->details, true);
        $this->assertEquals('ADULT', $violation['violation_type']);
        $this->assertEquals('error', $rows[0]->severity);

        $ban = json_decode($rows[1]->details, true);
        $this->assertEquals('SCHEDULE_FOR_DISABLE', $ban['ban_state']);
        $this->assertEquals('April 17, 2025', $ban['ban_date']);
        $this->assertEquals('error', $rows[1]->severity);
    }

    /**
     * Being reinstated is the same field and the same event as being banned,
     * and it is not an error. A log where good news is filed as a fault is a
     * log nobody reads twice.
     */
    public function test_a_reinstatement_is_not_an_error()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount(['waba_id' => 'waba-back']);

        WebhookEventRouter::route($account, ['field' => 'account_update', 'value' => [
            'event'    => 'DISABLED_UPDATE',
            'ban_info' => ['waba_ban_state' => 'REINSTATE'],
        ]]);

        $this->assertEquals('info', AccountEvent::where('account_id', $account->id)->value('severity'));
    }

    /**
     * An account event nobody has taught this router about is a warning and
     * not an info line, and the reason is in Meta's own page: two of the
     * events on this field are marked **New** in the corpus we read, and one
     * of them, ACCOUNT_OFFBOARDED, stops the number working. A default of
     * info would file the next one of those where nobody looks.
     */
    public function test_an_account_event_we_do_not_know_yet_is_a_warning()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount(['waba_id' => 'waba-unknown']);

        WebhookEventRouter::route($account, ['field' => 'account_update', 'value' => [
            'event' => 'SOMETHING_META_ADDED_LAST_TUESDAY',
        ]]);

        $row = AccountEvent::where('account_id', $account->id)->first();
        $this->assertEquals('warning', $row->severity);
        $this->assertEquals('SOMETHING_META_ADDED_LAST_TUESDAY', json_decode($row->details, true)['event']);
    }

    /**
     * An alert carries a description, and the description is the only free
     * text in any of these payloads. It is not stored: Meta documents eight
     * sentences for it today and the next revision can document others, and
     * the alert type says what to do without that risk.
     */
    public function test_an_account_alert_is_recorded_without_its_free_text()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount(['waba_id' => 'waba-alerted']);

        WebhookEventRouter::route($account, ['field' => 'account_alerts', 'value' => [
            'entity_type' => 'BUSINESS',
            'entity_id'   => '506914307656634',
            'alert_info'  => [
                'alert_severity'    => 'CRITICAL',
                'alert_status'      => 'ACTIVE',
                'alert_type'        => 'INCREASED_CAPABILITIES_ELIGIBILITY_FAILED',
                'alert_description' => 'Based on your activity, limits cannot be increased for your business.',
            ],
        ]]);

        $row = AccountEvent::where('account_id', $account->id)->first();
        $this->assertNotNull($row, 'an account alert left no row');
        $this->assertEquals('account_alerts', $row->event_type);
        $this->assertEquals('error', $row->severity, 'CRITICAL is the one severity Meta documents as a rejection or denial');

        $details = json_decode($row->details, true);
        $this->assertEquals('INCREASED_CAPABILITIES_ELIGIBILITY_FAILED', $details['alert_type']);
        $this->assertEquals('BUSINESS', $details['entity_type']);
        $this->assertArrayNotHasKey('alert_description', $details);
        $this->assertArrayNotHasKey('entity_id', $details);
    }

    /**
     * Meta's own page says this field is about a business phone number's
     * throughput level, whatever its name suggests, and the two events it
     * documents are ONBOARDING and THROUGHPUT_UPGRADE. It carries the number
     * it is about, so it is filed on that channel and not on every channel of
     * the WABA: a second number's limit is not this number's news.
     */
    public function test_a_throughput_change_is_filed_on_the_channel_it_names()
    {
        \Option::set('metawhatsapp.account_events', '1');
        // Distinct numbers, or the question this test asks has two right
        // answers and the one it gets is whichever row has the lower id.
        $mine  = $this->createTestAccount(['waba_id' => 'waba-two-numbers', 'phone_number' => '+34600111001']);
        $other = $this->createTestAccount(['waba_id' => 'waba-two-numbers', 'phone_number' => '+34600111002']);

        WebhookEventRouter::route($other, ['field' => 'phone_number_quality_update', 'value' => [
            'display_phone_number'                => ltrim($mine->phone_number, '+'),
            'event'                               => 'THROUGHPUT_UPGRADE',
            'current_limit'                       => 'TIER_UNLIMITED',
            'max_daily_conversations_per_business' => 'TIER_2K',
        ]]);

        $this->assertEquals(
            1,
            AccountEvent::where('account_id', $mine->id)->count(),
            'the change was not filed on the channel whose number it names'
        );
        $this->assertEquals(
            0,
            AccountEvent::where('account_id', $other->id)->count(),
            'the change was filed on a channel it was not about'
        );

        $details = json_decode(AccountEvent::where('account_id', $mine->id)->value('details'), true);
        $this->assertEquals('THROUGHPUT_UPGRADE', $details['event']);
        $this->assertEquals('TIER_UNLIMITED', $details['current_limit']);
        $this->assertEquals('TIER_2K', $details['max_daily_conversations_per_business']);
    }

    /**
     * A switched-off channel is still the owner of its number.
     *
     * Which channel a number belongs to is a question about identity, not
     * about whether the administrator has the channel switched on, and
     * filing its throughput change on a different channel is exactly the
     * misattribution the scope rules exist to prevent. The fan-out of an
     * account-wide fact still goes to the active channels only.
     */
    public function test_a_throughput_change_for_a_switched_off_channel_still_lands_on_it()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $resolved = $this->createTestAccount(['waba_id' => 'waba-one-off', 'phone_number' => '+34600111003']);
        $off      = $this->createTestAccount([
            'waba_id'      => 'waba-one-off',
            'is_active'    => false,
            'phone_number' => '+34600111004',
        ]);

        WebhookEventRouter::route($resolved, ['field' => 'phone_number_quality_update', 'value' => [
            'display_phone_number' => ltrim($off->phone_number, '+'),
            'event'                => 'THROUGHPUT_UPGRADE',
            'current_limit'        => 'TIER_2K',
        ]]);

        $this->assertEquals(
            1,
            AccountEvent::where('account_id', $off->id)->count(),
            'the change about a switched-off channel was filed somewhere else'
        );
        $this->assertEquals(0, AccountEvent::where('account_id', $resolved->id)->count());
    }

    /**
     * And when the number it names is not one of ours, the row goes on the
     * channel the webhook resolved rather than on all of them, with a warning
     * naming the field. Writing to fewer is the smaller mistake, which is the
     * rule this file already applies to a family with no declared scope.
     */
    public function test_a_throughput_change_for_a_number_we_do_not_have_lands_on_one_channel()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $one = $this->createTestAccount(['waba_id' => 'waba-strangers']);
        $two = $this->createTestAccount(['waba_id' => 'waba-strangers']);

        WebhookEventRouter::route($one, ['field' => 'phone_number_quality_update', 'value' => [
            'display_phone_number' => '15550000000',
            'event'                => 'ONBOARDING',
        ]]);

        $this->assertEquals(1, AccountEvent::where('account_id', $one->id)->count());
        $this->assertEquals(0, AccountEvent::where('account_id', $two->id)->count());
    }

    /**
     * A review decision belongs to the whole WhatsApp account, so every
     * channel on it hears about it, and a rejection means none of them can be
     * used with the API at all.
     */
    public function test_a_review_decision_reaches_every_channel_of_the_waba()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $one = $this->createTestAccount(['waba_id' => 'waba-reviewed']);
        $two = $this->createTestAccount(['waba_id' => 'waba-reviewed']);

        WebhookEventRouter::route($one, ['field' => 'account_review_update', 'value' => [
            'decision' => 'REJECTED',
        ]]);

        foreach ([$one, $two] as $account) {
            $row = AccountEvent::where('account_id', $account->id)->first();
            $this->assertNotNull($row, 'a channel of the reviewed account was not told');
            $this->assertEquals('error', $row->severity);
            $this->assertEquals('REJECTED', json_decode($row->details, true)['decision']);
        }
    }

    /** An approval is the same field and is not a fault. */
    public function test_an_approved_review_is_recorded_as_information()
    {
        \Option::set('metawhatsapp.account_events', '1');
        $account = $this->createTestAccount(['waba_id' => 'waba-approved']);

        WebhookEventRouter::route($account, ['field' => 'account_review_update', 'value' => [
            'decision' => 'APPROVED',
        ]]);

        $this->assertEquals('info', AccountEvent::where('account_id', $account->id)->value('severity'));
    }

    protected function templateStatusChange(string $event, string $name, string $language, ?string $reason = null): array
    {
        $value = [
            'event'                     => $event,
            'message_template_id'       => 123456,
            'message_template_name'     => $name,
            'message_template_language' => $language,
        ];
        if ($reason !== null) {
            $value['reason'] = $reason;
        }

        return ['field' => 'message_template_status_update', 'value' => $value];
    }
}
