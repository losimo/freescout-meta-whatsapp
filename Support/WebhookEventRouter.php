<?php

namespace Modules\MetaWhatsApp\Support;

use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Support\CustomerMatcher;

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
     * The channel the payload names, when it names one.
     *
     * Different from SCOPE_CHANNEL, which means the channel the webhook was
     * resolved on. These payloads carry no metadata, so the resolved channel
     * is whichever one answered for the WABA, and filing a second number's
     * messaging limit on it would be filing it on the wrong channel.
     */
    const SCOPE_PHONE = 'phone';

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
        'account_update'                 => self::SCOPE_WABA,
        'account_review_update'          => self::SCOPE_WABA,
        'account_alerts'                 => self::SCOPE_PHONE,
        'phone_number_quality_update'    => self::SCOPE_PHONE,
    ];

    /**
     * Which key of a payload can name one business phone number, for the
     * families whose facts may belong to a single number.
     *
     * Declared beside the scope rather than inside the handler for the same
     * reason as the scope itself. The two keys are not interchangeable: one
     * is a phone number ID and the other the number as Meta writes it, with
     * no plus sign.
     */
    const PHONE_KEYS = [
        'account_alerts'              => 'entity_id',
        'phone_number_quality_update' => 'display_phone_number',
    ];

    /**
     * Which account_update events are a fault and which are news (#66).
     *
     * An event nobody has taught this class about is a warning and not an
     * info line, and Meta's own page is the argument: two of the events on
     * this field are marked "New" in the corpus read for this change
     * (docs/referencia/meta-whatsapp-docs-2026-11-22), and one of them,
     * ACCOUNT_OFFBOARDED, stops the number working. A default of info would
     * file the next one of those where nobody looks.
     *
     * DISABLED_UPDATE is missing on purpose: it is both the ban and the
     * reinstatement, so its severity comes from the ban state.
     */
    const ACCOUNT_UPDATE_SEVERITY = [
        'ACCOUNT_DELETED'                            => 'error',
        'ACCOUNT_OFFBOARDED'                         => 'error',
        'ACCOUNT_RESTRICTION'                        => 'error',
        'ACCOUNT_VIOLATION'                          => 'error',
        'ACCOUNT_RECONNECTED'                        => 'info',
        'AD_ACCOUNT_LINKED'                          => 'info',
        'AUTH_INTL_PRICE_ELIGIBILITY_UPDATE'         => 'info',
        'BUSINESS_PRIMARY_LOCATION_COUNTRY_UPDATE'   => 'info',
        'CALLING_QUALITY_UPDATE'                     => 'info',
        'MM_LITE_TERMS_SIGNED'                       => 'info',
        'PARTNER_ADDED'                              => 'info',
        'PARTNER_APP_INSTALLED'                      => 'info',
        'PARTNER_APP_UNINSTALLED'                    => 'info',
        'PARTNER_CLIENT_CERTIFICATION_STATUS_UPDATE' => 'info',
        'PARTNER_REMOVED'                            => 'info',
        'VOLUME_BASED_PRICING_TIER_UPDATE'           => 'info',
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

        // Issue #66. The four families below were arriving and being dropped
        // into the "not handled" line below, while the switch that turns this
        // record on promised them.
        $handlers = [
            'account_update'              => 'accountUpdate',
            'account_alerts'              => 'accountAlert',
            'phone_number_quality_update' => 'throughputChange',
            'account_review_update'       => 'accountReview',
        ];

        if (isset($handlers[$field])) {
            self::dispatch($account, $field, $value, $handlers[$field]);

            return;
        }

        Log::info('[MetaWhatsApp] Webhook event not handled by this module', [
            'account_id' => $account->id,
            'field'      => $field,
        ]);
    }

    protected static function dispatch(WhatsAppAccount $account, string $field, array $value, string $handler): void
    {
        foreach (self::channelsFor($account, $field, $value) as $channel) {
            self::$handler($channel, $value);
        }
    }

    /**
     * The channels a family's facts belong to. An undeclared family touches
     * only the channel the webhook resolved: writing to fewer is the smaller
     * mistake, and the warning names what was forgotten.
     */
    protected static function channelsFor(WhatsAppAccount $account, string $field, array $value = [])
    {
        $scope = self::SCOPE[$field] ?? null;

        if ($scope === null) {
            Log::warning('[MetaWhatsApp] Webhook family with no declared scope, applied to one channel only', [
                'account_id' => $account->id,
                'field'      => $field,
            ]);

            return [$account];
        }

        if ($scope === self::SCOPE_PHONE) {
            return self::channelsForNumber($account, $field, $value);
        }

        return $scope === self::SCOPE_WABA ? self::channelsOfTheSameWaba($account) : [$account];
    }

    /**
     * The channel a payload names, or the whole WABA when it names no number.
     *
     * Three answers and each one is a decision. A payload that names a number
     * we have is about that channel. A payload that names none is an account
     * fact, so every channel on the WABA hears it, which is the same rule a
     * template follows. A payload that names a number this installation does
     * not have goes on the channel the webhook resolved, with a warning:
     * writing to fewer channels is the smaller mistake, which is the rule
     * this file already applies to a family with no declared scope.
     */
    protected static function channelsForNumber(WhatsAppAccount $account, string $field, array $value)
    {
        $named = self::namedNumber($field, $value);

        if ($named === null) {
            return self::channelsOfTheSameWaba($account);
        }

        // Every channel of the WABA and not only the active ones: which
        // channel a number belongs to is a question about identity, and a
        // channel an administrator has switched off for a while still owns
        // its number. The fan-out of an account-wide fact below stays on the
        // active ones.
        foreach (self::everyChannelOfTheSameWaba($account) as $channel) {
            if ((string) $channel->phone_number_id === $named) {
                return [$channel];
            }

            // Meta writes the number without a plus sign and we store it with
            // one, so the comparison is about the number and not about how it
            // is written. CustomerMatcher answers exactly that question, with
            // the same floor the inbound path uses, and a fourth copy of
            // digit normalisation in this module is how #18 to #29 happened.
            if ($channel->phone_number && CustomerMatcher::sameNumber((string) $channel->phone_number, $named)) {
                return [$channel];
            }
        }

        Log::warning('[MetaWhatsApp] Webhook event about a business phone number this installation does not have', [
            'account_id' => $account->id,
            'field'      => $field,
        ]);

        return [$account];
    }

    /**
     * The number a payload points at, or null when it points at none.
     *
     * An account alert carries `entity_id`, which is a phone number ID only
     * when `entity_type` says so: for a portfolio-wide alert the same key
     * holds a business portfolio ID, and matching that against our phone
     * numbers would file a portfolio fact on whichever channel happened to
     * collide.
     */
    protected static function namedNumber(string $field, array $value): ?string
    {
        $key = self::PHONE_KEYS[$field] ?? null;

        if ($key === null) {
            return null;
        }

        if ($field === 'account_alerts' && ($value['entity_type'] ?? null) !== 'PHONE_NUMBER') {
            return null;
        }

        $named = isset($value[$key]) ? trim((string) $value[$key]) : '';

        return $named === '' ? null : $named;
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

    /** The same set without the activity filter, for resolving a number. */
    protected static function everyChannelOfTheSameWaba(WhatsAppAccount $account)
    {
        if (!$account->waba_id) {
            return [$account];
        }

        return WhatsAppAccount::where('waba_id', $account->waba_id)
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

    /**
     * What Meta reports about the WhatsApp account itself: restrictions,
     * violations, bans and reinstatements, offboarding, and the money and
     * partner facts (issue #66).
     *
     * One handler for the whole field rather than one per event, so an event
     * nobody has met yet still leaves a row saying its name. Nothing is
     * invented from it: only the values Meta documents as enumerations are
     * stored, and the whitelist in AccountEventLog is the second half of the
     * same rule.
     */
    protected static function accountUpdate(WhatsAppAccount $account, array $value): void
    {
        $event = trim((string) ($value['event'] ?? ''));

        if ($event === '') {
            Log::info('[MetaWhatsApp] Account update with no event, nothing recorded', [
                'account_id' => $account->id,
            ]);

            return;
        }

        $restrictions = [];
        foreach ((array) ($value['restriction_info'] ?? []) as $restriction) {
            if (is_array($restriction) && !empty($restriction['restriction_type'])) {
                $restrictions[] = (string) $restriction['restriction_type'];
            }
        }

        AccountEventLog::record(
            $account,
            'account_update',
            self::accountUpdateSeverity($event, $value),
            [
                'event'             => $event,
                // A list and not the first one: a business that can neither
                // start conversations nor answer them is a different day from
                // one that cannot start them.
                'restriction_types' => $restrictions ? implode(', ', $restrictions) : null,
                'violation_type'    => $value['violation_info']['violation_type'] ?? null,
                'ban_state'         => $value['ban_info']['waba_ban_state'] ?? null,
                'ban_date'          => $value['ban_info']['waba_ban_date'] ?? null,
            ]
        );
    }

    /**
     * DISABLED_UPDATE is the ban and the reinstatement at once, so its
     * severity is the ban state and not the event. A log that files good news
     * as a fault is a log nobody reads twice.
     */
    protected static function accountUpdateSeverity(string $event, array $value): string
    {
        if ($event === 'DISABLED_UPDATE') {
            return ($value['ban_info']['waba_ban_state'] ?? null) === 'REINSTATE' ? 'info' : 'error';
        }

        return self::ACCOUNT_UPDATE_SEVERITY[$event] ?? 'warning';
    }

    /**
     * Messaging limit increases denied or deferred, Official Business Account
     * decisions, and a business profile photo that was deleted (#66).
     *
     * The description is the only free text in any of these payloads and it
     * is deliberately not stored. Meta documents eight sentences for it today
     * and the next revision can document others, while `alert_type` says what
     * happened and Meta's own page maps each type to what to do about it.
     */
    protected static function accountAlert(WhatsAppAccount $account, array $value): void
    {
        $alert = (array) ($value['alert_info'] ?? []);

        AccountEventLog::record(
            $account,
            'account_alerts',
            self::alertSeverity((string) ($alert['alert_severity'] ?? '')),
            [
                'entity_type'    => $value['entity_type'] ?? null,
                'alert_type'     => $alert['alert_type'] ?? null,
                'alert_severity' => $alert['alert_severity'] ?? null,
                'alert_status'   => $alert['alert_status'] ?? null,
            ]
        );
    }

    /** Meta's three documented levels, and a warning for anything new. */
    protected static function alertSeverity(string $severity): string
    {
        $map = [
            'CRITICAL'      => 'error',
            'WARNING'       => 'warning',
            'INFORMATIONAL' => 'info',
        ];

        return $map[$severity] ?? 'warning';
    }

    /**
     * `phone_number_quality_update`, which despite its name is about a
     * business phone number's throughput level and messaging limit.
     *
     * Worth knowing before anyone looks for a quality rating here: on the
     * dated page read for issue #66 this field documents two events,
     * ONBOARDING and THROUGHPUT_UPGRADE, and carries no quality score at all.
     * The messaging quality rating (GREEN, YELLOW, RED) has no webhook: it is
     * only readable by asking the Phone Numbers API for `quality_rating`. The
     * only quality rating with a webhook is the calling one, which arrives on
     * account_update as CALLING_QUALITY_UPDATE and is about calls this module
     * does not make.
     *
     * Both shapes of the limit are stored because the page announces the
     * removal of `old_limit` and `current_limit` in favour of
     * `max_daily_conversations_per_business` on a date that has already
     * passed, so an installation may see either.
     */
    protected static function throughputChange(WhatsAppAccount $account, array $value): void
    {
        AccountEventLog::record(
            $account,
            'phone_number_quality_update',
            'info',
            [
                'event'                                => $value['event'] ?? null,
                'old_limit'                            => $value['old_limit'] ?? null,
                'current_limit'                        => $value['current_limit'] ?? null,
                'max_daily_conversations_per_business'  => $value['max_daily_conversations_per_business'] ?? null,
            ]
        );
    }

    /**
     * The policy review of the whole WhatsApp account (#66).
     *
     * A rejection, a pending decision and a deferral all mean the account
     * cannot be used with Meta's APIs, so none of the three is information:
     * the rejection is an error and the other two are warnings, because they
     * can still end either way.
     */
    protected static function accountReview(WhatsAppAccount $account, array $value): void
    {
        $decision = trim((string) ($value['decision'] ?? ''));

        $map = [
            'APPROVED' => 'info',
            'REJECTED' => 'error',
            'PENDING'  => 'warning',
            'DEFERRED' => 'warning',
        ];

        AccountEventLog::record(
            $account,
            'account_review_update',
            $map[$decision] ?? 'warning',
            ['decision' => $decision !== '' ? $decision : null]
        );
    }
}
