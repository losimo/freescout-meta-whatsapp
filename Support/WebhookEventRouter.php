<?php

namespace Modules\MetaWhatsApp\Support;

use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;

/**
 * Everything arriving on the webhook that is not a message or a status.
 *
 * Subscribing a WABA subscribes it to every field Meta offers, so template
 * status and category changes, quality ratings, account alerts and the rest
 * all land here. This class is where they are told apart, so that
 * ProcessInboundWebhook does not grow a seventh responsibility.
 */
class WebhookEventRouter
{
    const SCOPE_WABA    = 'waba';
    const SCOPE_CHANNEL = 'channel';

    /**
     * Whose fact is each family? A template belongs to the WABA, so every
     * channel sharing it is affected; a phone number's quality belongs to that
     * number alone.
     *
     * Declared here rather than remembered inside each handler. A handler that
     * forgets writes to one channel and leaves the others saying, by the
     * absence of a row, that they have nothing wrong, which is the failure
     * this module already had once.
     */
    const SCOPE = [
        'message_template_status_update' => self::SCOPE_WABA,
        'template_category_update'       => self::SCOPE_WABA,
    ];

    public static function route(WhatsAppAccount $account, array $change): void
    {
        $field = $change['field'] ?? null;
        $value = $change['value'] ?? [];

        // One field, two payloads: a status change carries `event`, while a
        // quality change carries previous_quality_score/new_quality_score and
        // no `event` at all. Only the first is handled here.
        if ($field === 'message_template_status_update' && isset($value['event'])) {
            self::dispatch($account, $field, $value, 'templateStatus');

            return;
        }

        if ($field === 'template_category_update') {
            self::dispatch($account, $field, $value, 'templateCategory');

            return;
        }

        Log::info('[MetaWhatsApp] Webhook event not handled by this module', [
            'account_id' => $account->id,
            'field'      => $field,
        ]);
    }

    protected static function dispatch(WhatsAppAccount $account, string $field, array $value, string $handler): void
    {
        foreach (self::channelsFor($account, $field) as $channel) {
            self::$handler($channel, $value);
        }
    }

    /**
     * The channels a family's facts belong to. An undeclared family touches
     * only the channel the webhook resolved: writing to fewer is the smaller
     * mistake, and the warning names what was forgotten.
     */
    protected static function channelsFor(WhatsAppAccount $account, string $field)
    {
        $scope = self::SCOPE[$field] ?? null;

        if ($scope === null) {
            Log::warning('[MetaWhatsApp] Webhook family with no declared scope, applied to one channel only', [
                'account_id' => $account->id,
                'field'      => $field,
            ]);

            return [$account];
        }

        return $scope === self::SCOPE_WABA ? self::channelsOfTheSameWaba($account) : [$account];
    }

    /**
     * Every active channel on this account's WABA, this one included. A
     * fallback to the account itself when it has no WABA id on file, so a
     * half-configured channel still gets told.
     */
    protected static function channelsOfTheSameWaba(WhatsAppAccount $account)
    {
        if (!$account->waba_id) {
            return [$account];
        }

        return WhatsAppAccount::where('waba_id', $account->waba_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    protected static function templateStatus(WhatsAppAccount $account, array $value): void
    {
        $event  = (string) $value['event'];
        $key    = TemplateStatus::key($value);
        $issues = $account->templates_issue ?: [];

        if (TemplateStatus::isProblem($event)) {
            $summary = TemplateStatus::summary($value);

            // Meta redelivers webhooks until it gets a 2xx. Without this
            // guard, every redelivery would rewrite the date the panel
            // presents as when the problem started, and add an identical
            // audit row.
            if (($issues[$key]['summary'] ?? null) === $summary) {
                return;
            }

            $issues[$key] = ['summary' => $summary, 'at' => now()->toDateTimeString()];
            $account->templates_issue = $issues;
            $account->save();

            Log::error('[MetaWhatsApp] Meta moved a template to a state that blocks sending', [
                'account_id' => $account->id,
                'template'   => $value['message_template_name'] ?? null,
                'event'      => $event,
            ]);

            AccountEventLog::record(
                $account,
                'message_template_status_update',
                TemplateStatus::severity($event),
                [
                    'event'    => $event,
                    'template' => $value['message_template_name'] ?? null,
                    'language' => $value['message_template_language'] ?? null,
                    'reason'   => $value['reason'] ?? null,
                ]
            );

            return;
        }

        // A resolution only counts if this template was one of the ones we
        // had marked broken. An APPROVED for a different one must not clear
        // anything.
        if (TemplateStatus::clears($event) && isset($issues[$key])) {
            unset($issues[$key]);
            $account->templates_issue = $issues ?: null;
            $account->save();

            // The resolution is also a fact, and it is exactly what someone
            // will look for once the panel row has disappeared and they want
            // to know what happened. It is not an "all clear": it exists
            // because something happened, not because nothing did.
            AccountEventLog::record(
                $account,
                'message_template_status_update',
                TemplateStatus::severity($event),
                [
                    'event'    => $event,
                    'template' => $value['message_template_name'] ?? null,
                    'language' => $value['message_template_language'] ?? null,
                    'reason'   => null,
                ]
            );
        }
    }

    /**
     * Meta recategorises a template on its own, and the category is what sets
     * its price. Nothing breaks: this is money, not a fault, so it is recorded
     * and not put on the panel.
     *
     * Two messages arrive on this field and Meta's naming is a trap. The
     * warning sent 24 hours ahead carries `correct_category` for what the
     * template will become and **`new_category` for what it still is**; the
     * message for the change that already happened carries `previous_category`
     * and `new_category`, where `new_category` does mean the new one. Both are
     * normalised to from/to here so that nobody downstream has to know.
     */
    protected static function templateCategory(WhatsAppAccount $account, array $value): void
    {
        $impending = isset($value['correct_category']);

        $from = $impending ? ($value['new_category'] ?? null)      : ($value['previous_category'] ?? null);
        $to   = $impending ? ($value['correct_category'] ?? null)  : ($value['new_category'] ?? null);

        AccountEventLog::record(
            $account,
            'template_category_update',
            $impending ? 'warning' : 'info',
            [
                'stage'    => $impending ? 'impending' : 'completed',
                'template' => $value['message_template_name'] ?? null,
                'language' => $value['message_template_language'] ?? null,
                'from'     => $from,
                'to'       => $to,
            ]
        );
    }
}
