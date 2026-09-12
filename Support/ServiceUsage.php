<?php

namespace Modules\MetaWhatsApp\Support;

use Carbon\Carbon;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * How many service messages left this channel through FreeScout in the
 * current calendar month.
 *
 * Deliberately not "how many remain of your allowance". We can only see
 * what this module sent; if the same business number is also used from the
 * WhatsApp Business app or another tool, Meta's total is higher than ours.
 * Naming the number for what it is keeps it true for everyone.
 */
class ServiceUsage
{
    /**
     * First instant of the current month, in FreeScout's own timezone.
     *
     * The panel shows this date literally rather than saying "this month",
     * so nobody has to work out which month, or which clock, is meant.
     */
    public static function monthStart(): Carbon
    {
        return Carbon::now()->startOfMonth();
    }

    public static function sentThisMonth(WhatsAppAccount $account): int
    {
        return WhatsAppMessage::where('account_id', $account->id)
            // Meta's allowance resets every month and does not roll over.
            ->where('created_at', '>=', self::monthStart())
            ->where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)
            ->where('category', WhatsAppMessage::CATEGORY_SERVICE)
            // Meta bills per delivered message, so a send that failed costs
            // nothing and counting it would inflate the figure.
            ->where('status', '!=', WhatsAppMessage::STATUS_FAILED)
            ->count();
    }
}
