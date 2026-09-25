{{--
    Clock A: the always-visible countdown to Meta's real 24h window,
    shown while the window is open or closing soon. The closed/inactive
    state stays entirely in window_banner.blade.php, untouched — see
    docs/superpowers/specs/2026-09-23-metawhatsapp-window-clock-design.md §3.

    $remainingMinutes is required and must already be a positive int; the
    caller decides when this partial is the right one to render.
--}}
@php
    $described = \Modules\MetaWhatsApp\Support\WindowClock::describe($remainingMinutes);
    $cssClass  = $described['closing_soon'] ? 'metawhatsapp-window-clock-warning' : 'metawhatsapp-window-clock-open';
@endphp
<div class="{{ $cssClass }}" style="margin: 6px 15px 0; font-size: 12px;">
    <span title="{{ __('metawhatsapp::metawhatsapp.window_clock_tooltip') }}"
          aria-label="{{ __('metawhatsapp::metawhatsapp.window_clock_tooltip') }}">
        <svg width="12" height="12" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
             stroke-linejoin="round" style="vertical-align: -1px;">
            <circle cx="12" cy="12" r="10"/>
            <polyline points="12 6 12 12 16 14"/>
        </svg>
        {{ $described['state_label'] }} &middot; {{ $described['countdown_label'] }}
    </span>
</div>
