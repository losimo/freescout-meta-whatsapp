<?php

namespace Modules\MetaWhatsApp\Support;

use App\Customer;
use App\CustomerChannel;
use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;

/**
 * Finding the person a WhatsApp number already belongs to, and naming them.
 *
 * Issue #59: the module used to look only for a customer that already carried
 * the WhatsApp channel, so a customer imported into FreeScout with the same
 * phone number was never found and arrived a second time, named after their
 * own number.
 *
 * The comparison here is deliberately exact on the digits, and the only
 * notation it forgives is the ITU international prefix. Two stricter
 * implementations were read before settling on that: Odoo's falls back to a
 * prefix match on the national number, Chatwoot refuses alternative formats
 * except in three countries where the transformation is known, and writes the
 * reason in its own source: the alternative may be another valid number. We
 * went with the strict reading, because a false match attaches a conversation
 * to the wrong person and never looks like a failure. FreeScout also gives us
 * nothing to resolve a national number against: customers carry a country
 * field but the core has no dialling-code table, and there is no phone
 * library in its vendor directory.
 */
class CustomerMatcher
{
    /**
     * The digit strings a number may have been stored as. Meta always sends
     * E.164 without the '+', and FreeScout stores a pre-computed digits-only
     * key next to every phone (Customer::formatPhones), so the exact digits
     * cover '+34...', '34 650 ...' and any other separators. The second
     * candidate is the same number written with the international prefix,
     * which is how a lot of address books hold it.
     *
     * @return string[]
     */
    public static function searchCandidates(string $phone): array
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }

        // Same floor the inbound path uses for a usable phone number: below it
        // the value is not a number we can attribute to anybody.
        if (strlen($digits) < 6) {
            return [];
        }

        return [$digits, '00' . $digits];
    }

    /**
     * Ids of the customers holding this number, oldest first, and only the
     * ids: all but the first are there to be named in a note, so there is no
     * reason to hydrate them.
     *
     * FreeScout does not require a phone number to be unique, so more than
     * one answer is a real possibility rather than a corrupt database.
     *
     * @return int[]
     */
    public static function matchingIds(string $phone): array
    {
        $ids = [];

        foreach (self::searchCandidates($phone) as $candidate) {
            foreach (Customer::byPhone($candidate)->pluck('id') as $id) {
                $ids[(int) $id] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * Whether two written forms are the same number, by the same rule
     * matchingIds() uses to decide who a message belongs to.
     *
     * Shared on purpose rather than written twice: a number that resolves to
     * a person on the way in and not on the way out is how a dead number
     * stays on a record and collects somebody else's messages later.
     */
    public static function sameNumber(string $a, string $b): bool
    {
        $left  = self::searchCandidates($a);
        $right = self::searchCandidates($b);

        if (empty($left) || empty($right)) {
            return false;
        }

        return !empty(array_intersect($left, $right));
    }

    /**
     * Puts the replacement number in the place the old one held on a customer
     * record, and returns whether the record changed.
     *
     * **Why a webhook edits a record it does not own.** The rest of the
     * module never touches somebody else's data on Meta's word, and this is
     * the one exception, because here Meta is the only authority there is:
     * the person told WhatsApp this number is no longer theirs. Leaving it
     * does two kinds of damage. An agent reads a record and calls a number
     * nobody answers, and, when the operator gives that number to somebody
     * else, `Customer::byPhone()` still finds this record, so the new owner's
     * first message lands in a stranger's file under a stranger's name. That
     * is the mirror image of the promise issue #60 was opened for.
     *
     * Only the number that changed moves. Every other number on the record
     * is somebody's work, and nothing here says anything about those.
     */
    public static function movePhone(Customer $customer, string $old, string $new): bool
    {
        if (empty($customer->getPhones())) {
            $customer->setPhones([
                ['value' => $new, 'type' => Customer::PHONE_TYPE_MOBILE],
            ]);

            return true;
        }

        return self::rewritePhones($customer, $old, $new);
    }

    /**
     * Takes a number off a customer record, for the right-of-erasure screen.
     *
     * The module writes a number onto a record in two places, when it creates
     * the customer and when a number change moves one, so erasure has to be
     * able to take it off again: a receipt saying the identifiers are gone
     * while `Customer::byPhone()` still finds the person by one of them is a
     * receipt that is not true.
     */
    public static function removePhone(Customer $customer, string $phone): bool
    {
        return self::rewritePhones($customer, $phone, null);
    }

    /**
     * The one pass over a record's phone list, for both callers above: a null
     * replacement drops the entry, a string replaces it.
     *
     * One method and not two near-copies, which is the shape that produced
     * the same bug four times in this module (#18, #25, #28, #29).
     */
    protected static function rewritePhones(Customer $customer, string $old, ?string $new): bool
    {
        $kept    = [];
        $changed = false;

        foreach ($customer->getPhones() as $phone) {
            $value = isset($phone['value']) ? (string) $phone['value'] : '';

            if ($value !== '' && self::sameNumber($value, $old)) {
                $changed = true;

                if ($new === null) {
                    continue;
                }

                // The type is kept: whoever filed it as a mobile or a work
                // number knew which it was, and the number changing does not
                // change that.
                $phone['value'] = $new;
            }

            $kept[] = $phone;
        }

        if ($changed) {
            // setPhones() recomputes the numeric key Customer::byPhone()
            // searches on, which is the column that was still finding this
            // person by a number they no longer have.
            $customer->setPhones($kept);
        }

        return $changed;
    }

    /**
     * Links a number to a person on the WhatsApp channel, including the case
     * of a second number for the same person.
     *
     * Customer::addChannel() keeps one row per (customer, channel) and
     * overwrites the identifier when one is already there (core,
     * app/Customer.php:1618). Measured on 1.8.252: adding a second number
     * leaves a single row holding the second, and the first stops resolving
     * to anybody, so the row would swap back and forth as the two numbers
     * write. The table itself has no such limit, since its unique index is on
     * (channel, channel_id), and both numbers then resolve to the same
     * person.
     *
     * The first row still goes through addChannel(), because that is also
     * what fills the customers.channel and customers.channel_id columns the
     * core keeps as a flag for other modules.
     */
    public static function linkPhoneChannel(Customer $customer, string $phone)
    {
        $existing = CustomerChannel::where('customer_id', $customer->id)
            ->where('channel', WhatsAppAccount::CHANNEL)
            ->first();

        if (!$existing) {
            $customer->addChannel(WhatsAppAccount::CHANNEL, $phone);

            return;
        }

        if ((string) $existing->channel_id === $phone) {
            return;
        }

        // Returns null when the row exists under another customer, which the
        // caller has already ruled out by looking the number up first. A
        // warning rather than an exception: the message itself must still be
        // filed, and the next one will try again.
        if (!CustomerChannel::create($customer->id, WhatsAppAccount::CHANNEL, $phone)) {
            Log::warning('[MetaWhatsApp] Could not add a second WhatsApp number to a customer', [
                'customer_id' => $customer->id,
            ]);
        }
    }
}
