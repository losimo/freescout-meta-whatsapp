<?php

namespace Modules\MetaWhatsApp\Support;

/**
 * Formats Clock A's raw minute count (WhatsAppMessage::realWindowRemainingMinutes())
 * into what the conversation shows. Pure: no database access, no dependency
 * on WhatsAppAccount or Clock B (template_threshold_minutes) — see
 * docs/superpowers/specs/2026-09-23-metawhatsapp-window-clock-design.md §2.
 *
 * One threshold, used for both the visual state and the display format, so
 * they can never disagree: at or under it, the window is "closing soon" and
 * shows exact minutes; above it, it shows exact hours and minutes.
 */
class WindowClock
{
    const CLOSING_SOON_THRESHOLD_MINUTES = 60;

    public static function describe(int $remainingMinutes): array
    {
        $closingSoon = $remainingMinutes <= self::CLOSING_SOON_THRESHOLD_MINUTES;

        return [
            'closing_soon'    => $closingSoon,
            'state_label'     => $closingSoon
                ? __('metawhatsapp::metawhatsapp.window_clock_closing_soon')
                : __('metawhatsapp::metawhatsapp.window_clock_open'),
            'countdown_label' => $closingSoon
                ? self::minutesLabel($remainingMinutes)
                : self::hoursLabel($remainingMinutes),
        ];
    }

    private static function minutesLabel(int $remainingMinutes): string
    {
        return __('metawhatsapp::metawhatsapp.window_clock_minutes_left', [
            'minutes' => $remainingMinutes,
        ]);
    }

    private static function hoursLabel(int $remainingMinutes): string
    {
        $hours   = intdiv($remainingMinutes, 60);
        $minutes = $remainingMinutes % 60;

        if ($minutes === 0) {
            return __('metawhatsapp::metawhatsapp.window_clock_hours_left', ['hours' => $hours]);
        }

        return __('metawhatsapp::metawhatsapp.window_clock_hours_minutes_left', [
            'hours'   => $hours,
            'minutes' => $minutes,
        ]);
    }
}
