<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
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

    public function test_an_unknown_field_says_what_arrived()
    {
        \Log::spy();
        $account = $this->createTestAccount();

        WebhookEventRouter::route($account, ['field' => 'account_alerts', 'value' => ['entity_type' => 'WABA']]);

        \Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) {
            return strpos($message, 'not handled') !== false
                && ($context['field'] ?? null) === 'account_alerts';
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
