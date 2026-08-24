<?php

namespace Modules\MetaWhatsApp\Support;

use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * Single place where an outbound delivery failure is logged and its error
 * code persisted, whichever channel reported it (issue #25).
 *
 * Meta returns Cloud API errors "either synchronously as a Graph API
 * response, asynchronously via Webhook, or sometimes through both", and
 * the documented channel is not reliable: 131047 is listed as synchronous
 * yet arrives over the webhook in practice. Keeping the semantics here
 * means neither channel can silently drift from the other.
 *
 * This class deliberately does not decide what to do with the account.
 * Deactivating on a synchronous 190 stays with the caller that saw the
 * rejected call, because an asynchronous 190 attached to a delivery
 * status is weaker evidence and must not deactivate anything.
 */
class DeliveryFailure
{
    /** Reported in the Graph API response to our own call. */
    const SOURCE_SYNC = 'sync';

    /** Reported later through the statuses webhook. */
    const SOURCE_ASYNC = 'async';

    /** What failed to arrive, so the log line still says it plainly. */
    const SUBJECT_MESSAGE  = 'message';
    const SUBJECT_MEDIA    = 'media';
    const SUBJECT_TEMPLATE = 'template';

    /** Customer service window closed; free-form messages are rejected. */
    const CODE_WINDOW_EXPIRED = '131047';

    /** Access token expired or revoked. */
    const CODE_INVALID_TOKEN = '190';

    /**
     * Logs a delivery failure at error level and, when a message row is
     * given, persists the error code on it.
     *
     * Callers own de-duplication: this always logs. The asynchronous path
     * guards the whole sequence with failure_noted_at before calling.
     */
    public static function record(
        WhatsAppAccount $account,
        string $source,
        string $subject,
        ?string $errorCode,
        ?string $errorMessage = null,
        array $context = [],
        ?WhatsAppMessage $record = null
    ): void {
        $errorCode = (string) $errorCode;

        if ($record) {
            self::persistErrorCode($record, $errorCode, $account, $source);
        }

        // The caller's context wins nothing and loses nothing: base keys are
        // applied over it with array_merge (a '+' union would silently drop
        // any key the base already holds), and nulls are kept, because
        // "conversation_id: null" is precisely the diagnostic worth having.
        Log::error(self::message($subject, $errorCode), array_merge($context, [
            'account_id' => $account->id,
            'source'     => $source,
            'error_code' => $errorCode !== '' ? $errorCode : null,
            'error'      => $errorMessage,
        ]));
    }

    public static function isWindowExpired(?string $errorCode): bool
    {
        return (string) $errorCode === self::CODE_WINDOW_EXPIRED;
    }

    public static function isInvalidToken(?string $errorCode): bool
    {
        return (string) $errorCode === self::CODE_INVALID_TOKEN;
    }

    /**
     * Pulls the most useful error text out of a Meta status payload. Meta
     * puts the actionable wording in error_data.details and only a short
     * label in title, so details wins when both are present.
     */
    public static function errorTextFrom(array $error): ?string
    {
        foreach (['details', 'message', 'title'] as $key) {
            $value = $key === 'details'
                ? ($error['error_data']['details'] ?? null)
                : ($error[$key] ?? null);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Never blanks a code we already had: an empty errors array on a later
     * status must not erase the reason the message failed. A genuine change
     * of code is kept but reported, because it means Meta told us two
     * different things about the same message.
     */
    protected static function persistErrorCode(
        WhatsAppMessage $record,
        string $errorCode,
        WhatsAppAccount $account,
        string $source
    ): void {
        if ($errorCode === '') {
            return;
        }

        $previous = (string) $record->error_code;
        if ($previous === $errorCode) {
            return;
        }

        if ($previous !== '') {
            Log::error('[MetaWhatsApp] Meta reported a second, different error code for the same message', [
                'account_id'    => $account->id,
                'source'        => $source,
                'wamid'         => $record->wamid,
                'previous_code' => $previous,
                'error_code'    => $errorCode,
            ]);
        }

        $record->error_code = substr($errorCode, 0, 20);
        $record->save();
    }

    /**
     * Log message identifying the failure class and what failed to arrive,
     * so the reason is legible in laravel-*.log without cross-referencing
     * the code table or the context keys.
     */
    protected static function message(string $subject, string $errorCode): string
    {
        if (self::isWindowExpired($errorCode)) {
            return '[MetaWhatsApp] Outside the 24h window (131047): ' . $subject . ' not delivered';
        }

        if (self::isInvalidToken($errorCode)) {
            return '[MetaWhatsApp] Access token rejected by Meta (190)';
        }

        return '[MetaWhatsApp] Meta reported a delivery failure sending ' . $subject;
    }
}
