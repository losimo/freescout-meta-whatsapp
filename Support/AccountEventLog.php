<?php

namespace Modules\MetaWhatsApp\Support;

use Modules\MetaWhatsApp\Models\AccountEvent;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;

/**
 * Optional record of what Meta reports about the account.
 *
 * Off by default, one switch for the whole instance rather than one per
 * channel, and account-level only: never anything that identifies a customer.
 * If that rule ever has to change, it is a new decision and not something
 * inherited from the switch already being on.
 */
class AccountEventLog
{
    const OPTION_ENABLED = 'metawhatsapp.account_events';
    const RETENTION_DAYS = 90;

    /**
     * Which fields each event type may store, and nothing else gets through.
     *
     * The rule that this table rests on is that it holds facts about the
     * account and never anything that identifies a customer. Until now that
     * rule lived in the discipline of whoever wrote the call, one allow-list
     * per call site and one privacy test per family that somebody had to
     * remember to write. It lives here instead, so a new family that forgets
     * stores nothing rather than everything.
     */
    const ALLOWED_DETAILS = [
        'message_template_status_update' => ['event', 'template', 'language', 'reason'],
        'template_category_update'       => ['stage', 'template', 'language', 'from', 'to'],
    ];

    public static function isEnabled(): bool
    {
        return (bool) self::option(self::OPTION_ENABLED, '');
    }

    public static function record(WhatsAppAccount $account, string $eventType, string $severity, array $details): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if (!isset(self::ALLOWED_DETAILS[$eventType])) {
            // Fail closed rather than open: an event type nobody declared
            // writes its type and nothing else, which is visible on the screen
            // and invites fixing, instead of quietly storing whatever it was
            // handed.
            \Log::warning('[MetaWhatsApp] Account event type with no declared fields, details dropped', [
                'event_type' => $eventType,
            ]);
            $details = [];
        } else {
            $details = array_intersect_key($details, array_flip(self::ALLOWED_DETAILS[$eventType]));
        }

        AccountEvent::create([
            'account_id' => $account->id,
            'event_type' => $eventType,
            'severity'   => $severity,
            'details'    => json_encode($details),
        ]);

        self::prune();
    }

    /**
     * Retention runs on write rather than on a schedule. A scheduled task
     * would depend on the administrator's cron being set up and could fail in
     * silence; at a few dozen rows this delete costs nothing and depends on
     * nothing outside.
     */
    protected static function prune(): void
    {
        AccountEvent::where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }

    /**
     * Read without Option's static cache, and the reason matters.
     *
     * Option::set() writes to the database and never touches Option::$cache,
     * which is static and lives as long as the process. In a web request that
     * goes unnoticed, but this flag is read from ProcessInboundWebhook, which
     * runs inside a long-lived queue:work. A worker started while the switch
     * was off would see it off forever, however many times an administrator
     * turned it on, until somebody restarted the queue.
     *
     * Do not "optimise" this into a cached read.
     */
    protected static function option(string $name, $default)
    {
        return \Option::get($name, $default, true, false);
    }
}
