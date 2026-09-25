<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * Clock A: the real 24h window from Meta's rule, always visible in the
 * conversation. Deliberately independent from Clock B (the internal,
 * configurable `template_threshold_minutes` that already exists) — see
 * test_clock_a_ignores_clock_bs_threshold below and
 * docs/superpowers/specs/2026-09-23-metawhatsapp-window-clock-design.md §2.
 */
class WindowClockTest extends TestCase
{
    use DatabaseTransactions;

    public function test_no_inbound_ever_returns_null()
    {
        $account = $this->createTestAccount();

        $this->assertNull(WhatsAppMessage::realWindowRemainingMinutes(555001));
    }

    public function test_inbound_one_minute_ago_leaves_almost_24h()
    {
        $account = $this->createTestAccount();
        $this->makeInbound($account, 555002, now()->subMinute());

        $remaining = WhatsAppMessage::realWindowRemainingMinutes(555002);

        $this->assertGreaterThanOrEqual(1438, $remaining);
        $this->assertLessThanOrEqual(1439, $remaining);
    }

    public function test_inbound_23h55_ago_leaves_under_an_hour()
    {
        $account = $this->createTestAccount();
        $this->makeInbound($account, 555003, now()->subHours(23)->subMinutes(55));

        $remaining = WhatsAppMessage::realWindowRemainingMinutes(555003);

        $this->assertGreaterThanOrEqual(4, $remaining);
        $this->assertLessThanOrEqual(5, $remaining);
    }

    public function test_inbound_over_24h_ago_is_negative()
    {
        $account = $this->createTestAccount();
        $this->makeInbound($account, 555004, now()->subHours(25));

        $this->assertLessThan(0, WhatsAppMessage::realWindowRemainingMinutes(555004));
    }

    public function test_describe_far_from_closing_shows_hours_and_minutes()
    {
        $described = \Modules\MetaWhatsApp\Support\WindowClock::describe(119);

        $this->assertFalse($described['closing_soon']);
        $this->assertEquals(
            __('metawhatsapp::metawhatsapp.window_clock_open'),
            $described['state_label']
        );
        $this->assertEquals(
            __('metawhatsapp::metawhatsapp.window_clock_hours_minutes_left', ['hours' => 1, 'minutes' => 59]),
            $described['countdown_label']
        );
    }

    public function test_describe_whole_hour_omits_the_zero_minutes()
    {
        $described = \Modules\MetaWhatsApp\Support\WindowClock::describe(120);

        $this->assertEquals(
            __('metawhatsapp::metawhatsapp.window_clock_hours_left', ['hours' => 2]),
            $described['countdown_label']
        );
    }

    public function test_describe_at_the_boundary_is_closing_soon_in_minutes()
    {
        $described = \Modules\MetaWhatsApp\Support\WindowClock::describe(60);

        $this->assertTrue($described['closing_soon']);
        $this->assertEquals(
            __('metawhatsapp::metawhatsapp.window_clock_closing_soon'),
            $described['state_label']
        );
        $this->assertEquals(
            __('metawhatsapp::metawhatsapp.window_clock_minutes_left', ['minutes' => 60]),
            $described['countdown_label']
        );
    }

    public function test_describe_under_an_hour_shows_exact_minutes()
    {
        $described = \Modules\MetaWhatsApp\Support\WindowClock::describe(42);

        $this->assertTrue($described['closing_soon']);
        $this->assertEquals(
            __('metawhatsapp::metawhatsapp.window_clock_minutes_left', ['minutes' => 42]),
            $described['countdown_label']
        );
    }

    /**
     * The independence guarded in the spec §2: moving Clock B's threshold
     * must never change Clock A's value. If someone ever couples them by
     * accident (e.g. reusing a variable), this test breaks.
     */
    public function test_clock_a_ignores_clock_bs_threshold()
    {
        $account = $this->createTestAccount();
        $this->makeInbound($account, 555010, now()->subMinutes(30));

        $account->template_threshold_minutes = 5;
        $account->save();
        $withLowThreshold = WhatsAppMessage::realWindowRemainingMinutes(555010);

        $account->template_threshold_minutes = 1440;
        $account->save();
        $withHighThreshold = WhatsAppMessage::realWindowRemainingMinutes(555010);

        $this->assertEquals($withLowThreshold, $withHighThreshold);
    }

    /**
     * WindowClock::describe(int) takes only a raw int, so at the API level
     * it already cannot accept Clock B's account/threshold. This guard makes
     * sure nobody adds that coupling later by reaching into WhatsAppAccount
     * or the threshold setting from inside the class body.
     */
    public function test_window_clock_source_never_references_clock_b()
    {
        $source = $this->sourceWithoutComments(file_get_contents(__DIR__ . '/../Support/WindowClock.php'));

        $this->assertStringNotContainsString(
            'windowExpired',
            $source,
            'WindowClock must not reference windowExpired() (independence from Clock B)'
        );

        $this->assertStringNotContainsString(
            'template_threshold_minutes',
            $source,
            'WindowClock must not reference template_threshold_minutes (independence from Clock B)'
        );

        $this->assertStringNotContainsString(
            'WhatsAppAccount',
            $source,
            'WindowClock must not reference WhatsAppAccount (independence from Clock B)'
        );
    }

    private function sourceWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }

    public function test_partial_renders_open_state_with_tooltip()
    {
        $html = \View::make('metawhatsapp::partials/window_clock', [
            'remainingMinutes' => 119,
        ])->render();

        $this->assertStringContainsString(__('metawhatsapp::metawhatsapp.window_clock_open'), $html);
        $this->assertStringContainsString('1 h 59', $html);
        $this->assertStringContainsString(e(__('metawhatsapp::metawhatsapp.window_clock_tooltip')), $html);
        $this->assertStringNotContainsString('metawhatsapp-window-clock-warning', $html);
    }

    public function test_partial_renders_closing_soon_with_warning_class()
    {
        $html = \View::make('metawhatsapp::partials/window_clock', [
            'remainingMinutes' => 42,
        ])->render();

        $this->assertStringContainsString(__('metawhatsapp::metawhatsapp.window_clock_closing_soon'), $html);
        $this->assertStringContainsString('metawhatsapp-window-clock-warning', $html);
    }

    public function test_partial_embeds_the_svg_without_an_external_request()
    {
        $html = \View::make('metawhatsapp::partials/window_clock', [
            'remainingMinutes' => 119,
        ])->render();

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('http', $html);
    }

    private function makeInbound($account, int $conversationId, $createdAt): WhatsAppMessage
    {
        $message = new WhatsAppMessage();
        $message->account_id      = $account->id;
        $message->conversation_id = $conversationId;
        $message->direction       = WhatsAppMessage::DIRECTION_INBOUND;
        $message->wamid            = 'wamid.clock-' . $conversationId;
        $message->status            = WhatsAppMessage::STATUS_RECEIVED;
        $message->save();
        $message->created_at = $createdAt;
        $message->save();

        return $message;
    }

    public function test_hook_renders_the_clock_for_an_open_window_and_not_the_banner()
    {
        $account = $this->createTestAccount();
        $this->makeInbound($account, 555020, now()->subMinutes(30));
        $conversation = $this->fakeConversation(555020);

        $html = $this->renderAfterSubjectBlock($conversation, $account);

        $this->assertStringContainsString(__('metawhatsapp::metawhatsapp.window_clock_open'), $html);
        $this->assertStringNotContainsString(__('metawhatsapp::metawhatsapp.window_expired_notice'), $html);
    }

    public function test_hook_renders_only_the_banner_once_the_real_window_is_closed()
    {
        $account = $this->createTestAccount();
        $this->makeInbound($account, 555021, now()->subHours(25));
        $conversation = $this->fakeConversation(555021);

        $html = $this->renderAfterSubjectBlock($conversation, $account);

        $this->assertStringContainsString(__('metawhatsapp::metawhatsapp.window_expired_notice'), $html);
        $this->assertStringNotContainsString(__('metawhatsapp::metawhatsapp.window_clock_open'), $html);
        $this->assertStringNotContainsString(__('metawhatsapp::metawhatsapp.window_clock_closing_soon'), $html);
    }

    public function test_hook_renders_nothing_for_a_conversation_outside_the_module()
    {
        $conversation = $this->fakeConversation(555022);

        $html = $this->renderAfterSubjectBlock($conversation, null);

        $this->assertSame('', $html);
    }

    private function fakeConversation(int $id): \App\Conversation
    {
        $conversation = new \App\Conversation();
        $conversation->id = $id;

        return $conversation;
    }

    private function renderAfterSubjectBlock(\App\Conversation $conversation, $account): string
    {
        ob_start();
        \Eventy::action('conversation.after_subject_block', $conversation, $account ? $account->mailbox : new \App\Mailbox());
        return ob_get_clean();
    }
}
