<?php

namespace Modules\MetaWhatsApp\Support;

use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * Whether a delivery status Meta just sent is newer than the one on the row
 * (T11).
 *
 * Meta promises no order, redelivers webhooks, and a queue worker can pick two
 * deliveries up in either sequence, so `read` arriving before `delivered` is
 * ordinary. Applying whatever came last made the row walk backwards.
 *
 * `failed` is not the top of the ranking, it is outside it, and that
 * asymmetry is the whole point of this class:
 *
 * - It may always be applied. When Meta says a message failed, that is
 *   information we have no reason to doubt and no cost in believing: the
 *   failure shows on the health panel and the message stops being counted.
 * - Nothing may be applied after it. ServiceUsage::sentThisMonth() counts
 *   everything that is not `failed` as a billable service message, and the
 *   health panel finds the last failure by the same column, so a late `sent`
 *   or `delivered` on a failed row took the breakage off the panel and turned
 *   it into a message counted as paid for.
 *
 * A ranking with `failed` as the highest value would get the first half right
 * and the second half wrong, which is why the ticket's proposed `$rank` could
 * not be used as written.
 */
class InboundStatusRank
{
    /** How far along a delivery is. `failed` is deliberately absent. */
    const PROGRESS = [
        WhatsAppMessage::STATUS_RECEIVED  => 0,
        WhatsAppMessage::STATUS_SENT      => 1,
        WhatsAppMessage::STATUS_DELIVERED => 2,
        WhatsAppMessage::STATUS_READ      => 3,
    ];

    /**
     * Whether a row in this status is done being updated.
     *
     * @param string|null $current status on the row
     */
    public static function isTerminal(?string $current): bool
    {
        return $current === WhatsAppMessage::STATUS_FAILED;
    }

    /**
     * Whether `$incoming` should replace `$current`.
     *
     * Unknown values on either side answer true, which keeps this from
     * silently swallowing a status a later version adds: a status nobody
     * ranked is better applied and seen than dropped and not.
     *
     * @param string      $incoming mapped module status, not Meta's word
     * @param string|null $current  status on the row
     */
    public static function outranks(string $incoming, ?string $current): bool
    {
        if ($incoming === WhatsAppMessage::STATUS_FAILED) {
            return true;
        }

        if (self::isTerminal($current)) {
            return false;
        }

        if ($current === null
            || !isset(self::PROGRESS[$current])
            || !isset(self::PROGRESS[$incoming])
        ) {
            return true;
        }

        return self::PROGRESS[$incoming] > self::PROGRESS[$current];
    }
}
