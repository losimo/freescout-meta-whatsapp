<?php

namespace Modules\MetaWhatsApp\Support;

use App\Conversation;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * Decides and renders the clock and banner partials from a conversation's
 * live state — the single place both decisions are made, shared by the
 * conversation.after_subject/after_subject_block hooks (page load, in
 * MetaWhatsAppServiceProvider) and the window-state endpoint (live
 * refresh, MetaWhatsAppController::windowState()), so neither can ever
 * show something the other wouldn't.
 *
 * Deliberately separate from WindowClock (Support/WindowClock.php), which
 * stays pure number-formatting only — see
 * WindowClockTest::test_window_clock_source_never_references_clock_b.
 */
class WindowState
{
    public static function renderClock(Conversation $conversation, WhatsAppAccount $account): string
    {
        if (!$account->is_active || WhatsAppMessage::windowExpired($conversation->id, $account)) {
            return '';
        }

        $remainingMinutes = WhatsAppMessage::realWindowRemainingMinutes($conversation->id);
        if ($remainingMinutes === null) {
            return '';
        }

        return view('metawhatsapp::partials/window_clock', [
            'remainingMinutes' => $remainingMinutes,
        ])->render();
    }

    public static function renderBanner(Conversation $conversation, WhatsAppAccount $account): string
    {
        $inactive = !$account->is_active;

        if (!($inactive || WhatsAppMessage::windowExpired($conversation->id, $account))) {
            return '';
        }

        $phone = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->whereNotNull('contact_phone')
            ->orderByDesc('id')
            ->value('contact_phone');

        return view('metawhatsapp::partials/window_banner', [
            'conversation'  => $conversation,
            'account'       => $account,
            'phone'         => $phone,
            'accountActive' => !$inactive,
        ])->render();
    }
}
