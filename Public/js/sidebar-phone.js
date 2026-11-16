/**
 * Issue #45. Puts the account's phone number on the line under the mailbox
 * name in the left sidebar, where the core prints the technical address of
 * the channel mailbox (whatsapp-<phone_number_id>@channel.internal).
 *
 * The server cannot do this: there is no Eventy hook after
 * `.sidebar-title-email` in the core's sidebar view (checked against
 * FreeScout 1.8.251), so the text node itself is out of reach from PHP. What
 * the server does instead is hide the element with `visibility: hidden` from
 * inside the <head> and send the number along as a <meta> tag; see
 * sidebarMailboxEmailCss() in Providers/MetaWhatsAppServiceProvider.php for
 * the full reasoning, including why that cannot flash.
 *
 * The order of the two statements at the bottom is the whole point and must
 * not be swapped: the text is replaced while the element is still hidden, and
 * only then is it made visible. Revealing first would paint the technical
 * address for a frame, which is the bug issue #17 already cost this module
 * once.
 *
 * No text or translation logic lives here. A phone number is a phone number
 * in every language, so there is nothing to translate and nothing to format.
 */
(function () {
    'use strict';

    // Inside $(document).ready and not at top level: the sidebar is part of
    // the page body, and this file is loaded from the <head> bundle, so at
    // parse time `.sidebar-title-email` does not exist yet. window-state.js
    // carries the same wrapper for the same kind of reason, after a bug was
    // shipped without it.
    $(document).ready(function () {
        var meta = document.querySelector('meta[name="metawhatsapp-sidebar-phone"]');
        if (!meta) {
            return;
        }

        var phone = meta.getAttribute('content');
        if (!phone) {
            return;
        }

        // querySelectorAll rather than querySelector: one sidebar per page is
        // all the core renders today, and if that ever stops being true the
        // loop does the right thing instead of leaving a hidden empty box
        // behind.
        var targets = document.querySelectorAll('.sidebar-title-email');
        for (var i = 0; i < targets.length; i++) {
            targets[i].textContent = phone;
            targets[i].style.visibility = 'visible';
        }
    });
}());
