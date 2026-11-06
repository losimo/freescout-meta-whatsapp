/**
 * Live updates for the window clock (#41) and the expired-window banner
 * (#42) — see docs/superpowers/specs/2026-11-03-metawhatsapp-window-clock-live-update-design.md.
 *
 * Two triggers call the SAME server endpoint for the real current state:
 *   1. The client-side tick's own zero-crossing (the clock might be
 *      lying — only the server knows if a reply reset it, §3 of the spec).
 *   2. FreeScout's own Polycast channel firing a new thread for this
 *      conversation — reused directly, never a second real-time system.
 *
 * No text/translation logic lives here: the server sends already-translated
 * label/template strings as data attributes (Task 1), this file only does
 * arithmetic and a literal :placeholder substitution.
 */
(function () {
    'use strict';

    // Checked before jQuery readiness on purpose: getGlobalAttr is a plain
    // global function defined by core's own <script> tags (not something
    // assigned inside a $(document).ready handler), so it's already
    // available at top-level parse time — unlike `poly` below, there's no
    // ordering reason to defer this check into the ready handler.
    if (typeof getGlobalAttr === 'undefined') {
        return;
    }
    var conversationId = getGlobalAttr('conversation_id');
    if (!conversationId) {
        return;
    }

    // Everything else must wait for $(document).ready: core's main.js
    // declares `var poly;` at top level but only ASSIGNS it inside its own
    // $(document).ready handler. jQuery ready-handlers run FIFO, and this
    // script is loaded after main.js in the bundle, so by the time this
    // handler runs, core's handler (registered first) has already run and
    // `poly` is guaranteed assigned.
    $(document).ready(function () {
        var clockWrapper  = document.getElementById('metawhatsapp-window-clock-wrapper');
        var bannerWrapper = document.getElementById('metawhatsapp-window-banner');
        if (!clockWrapper && !bannerWrapper) {
            return;
        }

        var windowStateUrl = clockWrapper ? clockWrapper.dataset.windowStateUrl : null;

        function updateClockDisplay() {
            var clockEl = document.getElementById('metawhatsapp-window-clock');
            if (!clockEl) {
                return;
            }

            var expiresAt = new Date(clockEl.dataset.expiresAt);
            var remainingMinutes = Math.round((expiresAt.getTime() - Date.now()) / 60000);

            if (remainingMinutes <= 0) {
                // Might genuinely be closed, or might be stale because a reply
                // reset the real window — only the server knows which.
                refreshWindowState();
                return;
            }

            // Single threshold for both the label/class AND the
            // minutes-vs-hours template choice, mirroring
            // WindowClock::describe()'s own single-threshold design
            // (Support/WindowClock.php) — the two decisions must never
            // disagree, so both are derived from the same closingSoon
            // boolean rather than two separate comparisons.
            var closingSoon = remainingMinutes <= 60;
            clockEl.className = closingSoon ? 'metawhatsapp-window-clock-warning' : 'metawhatsapp-window-clock-open';

            // split/join instead of a single replace(): a translation that
            // repeats the same placeholder would otherwise only get the
            // first occurrence substituted.
            var countdown;
            if (closingSoon) {
                countdown = clockEl.dataset.templateMinutes.split(':minutes').join(remainingMinutes);
            } else {
                var hours = Math.floor(remainingMinutes / 60);
                var minutes = remainingMinutes % 60;
                countdown = minutes === 0
                    ? clockEl.dataset.templateHours.split(':hours').join(hours)
                    : clockEl.dataset.templateHoursMinutes.split(':hours').join(hours).split(':minutes').join(minutes);
            }

            var stateLabel = closingSoon ? clockEl.dataset.labelClosing : clockEl.dataset.labelOpen;
            var textEl = document.getElementById('metawhatsapp-window-clock-text');
            if (textEl) {
                textEl.textContent = stateLabel + ' · ' + countdown;
            }
        }

        function refreshWindowState() {
            if (!windowStateUrl) {
                return;
            }
            fetch(windowStateUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (data) {
                    if (!data) {
                        return;
                    }
                    if (clockWrapper) {
                        clockWrapper.innerHTML = data.clock;
                    }
                    if (bannerWrapper) {
                        bannerWrapper.innerHTML = data.banner;
                    }
                });
        }

        setInterval(updateClockDisplay, 30000);

        // By ready-time this should always be assigned (see comment above);
        // if it's still undefined, something deeper than script-ordering is
        // wrong, so warn instead of silently skipping the Polycast listener.
        if (typeof poly === 'undefined') {
            console.warn('MetaWhatsApp: poly is still undefined at $(document).ready time; live window-state updates via Polycast will not work.');
            return;
        }

        var channel = poly.subscribe('conv.' + conversationId);
        channel.on('App\\Events\\RealtimeConvNewThread', function (data) {
            if (!data || data.conversation_id != conversationId) {
                return;
            }
            refreshWindowState();
        });
    });
}());
