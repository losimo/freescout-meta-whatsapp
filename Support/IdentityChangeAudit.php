<?php

namespace Modules\MetaWhatsApp\Support;

use Modules\MetaWhatsApp\Models\WhatsAppAccount;

/**
 * An auditable trace of every identity change that concerns a contact of ours
 * (issue #60).
 *
 * Not of every announcement, and the difference was pointed out in review:
 * when the payload carries no usable pair of identifiers, or when the
 * identifier Meta says is gone belongs to nobody we know, there is no person
 * to name and nothing changed, so no row is written. Those two leave a log
 * line only. Anything that touches a contact of ours leaves a row, applied or
 * refused.
 *
 * The conversation note was the only trace, and it is not always possible to
 * write one: the contact may have no live conversation, theirs may have been
 * deleted or marked as spam, or the move may be refused before a note would
 * be reached. In all of those cases the only record was a log line that did
 * not even name the person.
 *
 * It is also what the reporter needs for a different reason. They work in
 * healthcare and verify every change of a customer's phone number by hand;
 * WhatsApp's own mechanism verifies the person and the new number, so a record
 * they can audit is enough for them.
 *
 * **Where it is written, and why there.** FreeScout's own activity log, which
 * means the screen under Manage » Logs (SecureController::logs). That screen
 * renders any log name present in the table, links the causer as the customer
 * when it is not a user, and makes a column out of each property written.
 * `APP_ALERT_LOGS` can mail it. It is the core's own API, so this is not a
 * module inventing a place to keep records.
 *
 * **What is deliberately not in it: the numbers.** Not the old one and not
 * the new one. The module's erasure screen does not reach the activity log,
 * so a number written here would outlive an erasure request made later, which
 * is the same reason the conversation note names neither.
 *
 * This docblock used to add that the old number is genuinely gone once the
 * move overwrites it, so recording it here would be the only copy. That was
 * wrong, and it is the kind of claim worth correcting where it was made: what
 * the move rewrites is this module's rows, the channel links and the entry on
 * the customer's phone list. The number stays in the customer's name, in the
 * `from` of every inbound thread, in the conversation subject when the
 * template includes it, and inside Meta's message ids. The screen under
 * Manage » Logs even prints it, because it names the causer and a contact the
 * module created is named after their number. `docs/personal-data.md` has the
 * inventory. Whether an audit needs the numbers in the row itself is a
 * controller's decision about their own retention, and it is being asked in
 * the issue rather than guessed at here.
 *
 * **Why the text is English and not translated.** The row is written by a
 * queue worker, which has no reader and therefore no locale; storing a
 * translated sentence would freeze whichever locale that worker happened to
 * run in. The core's own log names on that screen (`out_emails`,
 * `send_errors`) are untranslated identifiers for the same reason.
 */
class IdentityChangeAudit
{
    /** Appears as its own entry in the log picker under Manage » Logs. */
    const LOG_NAME = 'metawhatsapp_identity';

    const DESCRIPTION = 'WhatsApp identity change';

    /** The identifier moved onto its replacement. */
    const OUTCOME_APPLIED = 'applied';

    /**
     * Refused: the new identifier already belongs to a different customer.
     * The one that needs a person rather than a log file, because only a
     * manual merge resolves it.
     */
    const OUTCOME_ANOTHER_CUSTOMER = 'refused: the new identifier belongs to another customer';

    /** Refused: the new identifier does not fit customer_channel.channel_id. */
    const OUTCOME_TOO_LONG = 'refused: the new identifier is longer than the core allows';

    /**
     * One row, with no identifier in it.
     *
     * A retry of the same job writes the row again, and that is accepted
     * rather than solved (review, 2026-11-24). Identity changes are handled
     * before the per-wamid idempotence guard on purpose, so there is nothing
     * on this path to recognise a second pass; for an applied move the second
     * pass finds nothing left to move and writes nothing, but a refused one
     * is refused again and recorded again. Each row is a true record of a
     * processing attempt, the retry only happens when something else in the
     * same delivery failed, and the alternative is a deduplication window.
     * This project has just spent four review rounds learning what those
     * cost: see the refusal notes in lot 3.
     *
     * @param int      $channel    WhatsAppAccount::CHANNEL or ::CHANNEL_BSUID
     * @param int|null $customerId null only when there is nobody to name, in
     *                             which case no row is written at all: an
     *                             announcement about somebody who is not a
     *                             contact of ours is not an audit event
     */
    public static function record(WhatsAppAccount $account, int $channel, ?int $customerId, string $outcome): void
    {
        if (!$customerId) {
            return;
        }

        $customer = \App\Customer::find($customerId);

        if (!$customer) {
            return;
        }

        try {
            activity()
                ->causedBy($customer)
                ->withProperties([
                    'identifier' => self::identifierName($channel),
                    'outcome'    => $outcome,
                    'channel'    => $account->name,
                ])
                ->useLog(self::LOG_NAME)
                ->log(self::DESCRIPTION);
        } catch (\Exception $e) {
            // The audit row is a record of something that already happened, so
            // failing to write it must not undo it. It is logged and the move
            // stands.
            \Illuminate\Support\Facades\Log::warning('[MetaWhatsApp] Could not write the identity change audit row', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /** Which of the two identifiers changed, in words an administrator reads. */
    protected static function identifierName(int $channel): string
    {
        return $channel === WhatsAppAccount::CHANNEL
            ? 'phone number'
            : 'business-scoped user id';
    }
}
