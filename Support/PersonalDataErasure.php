<?php

namespace Modules\MetaWhatsApp\Support;

use App\Customer;
use App\CustomerChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * Answering a right-of-erasure request for one person.
 *
 * FreeScout has no "forget this customer" action of its own: the only
 * place the core deletes a customer is a merge, and there is no route,
 * no button and no command for anything else. So this is the action,
 * and it is ours.
 *
 * What it takes out of our own table is the identity and nothing else.
 * The row survives on purpose: the unique wamid is the module's
 * idempotency guard, and Meta redelivers webhooks, so a deleted row
 * would let an old redelivery recreate the person who was just erased.
 */
class PersonalDataErasure
{
    /**
     * Every identifier we hold for the same person as $input.
     *
     * Iterating to a fixed point is not belt and braces. Outbound rows
     * never carry a BSUID, and Meta regenerates the BSUID when someone
     * re-registers, so a person's rows form chains like
     * (P1,B1) -> (P1,B2) -> (null,B2). One round of expansion reaches P1
     * and B2 but never searches by B2, leaving the last rows behind with
     * a permanent identifier while the receipt says the work is done.
     *
     * Only identifiers we really hold come back, never the search
     * variants: the receipt must not list something that was never here.
     *
     * The module's own table is not the only place it writes an identifier.
     * Deleting a channel or a mailbox takes the message rows out and leaves
     * the contact links in `customer_channel`, so a search that read only
     * the messages answered "this module holds nothing under that
     * identifier" with the person's number still in a row this screen is the
     * only thing that can reach. A false nothing is the worst output this
     * screen can produce: it ends the search and the administrator reports
     * back in good faith.
     */
    public static function resolveIdentifiers(string $input): array
    {
        $search = self::inputVariants($input);
        $held   = [];

        while (!empty($search)) {
            $rows = WhatsAppMessage::where(function ($query) use ($search) {
                $query->whereIn('contact_phone', $search)
                    ->orWhereIn('contact_user_id', $search);
            })->get(['contact_phone', 'contact_user_id']);

            $found = [];
            foreach ($rows as $row) {
                if (!empty($row->contact_phone)) {
                    $found[] = $row->contact_phone;
                }
                if (!empty($row->contact_user_id)) {
                    $found[] = $row->contact_user_id;
                }
            }

            foreach (self::contactLinks($search)->pluck('channel_id') as $channelId) {
                if (!empty($channelId)) {
                    $found[] = (string) $channelId;
                }
            }

            // And a record whose whole name is the identifier, which is the
            // one place it still sits after the v3.0.0 screen has run: that
            // version took the number off the rows, the links and the phone
            // list and left the name, and without this the screen would
            // answer "nothing held" about a number that is on the customer
            // list in plain sight.
            foreach (self::namedAfter($search) as $name) {
                $found[] = $name;
            }

            $merged = array_values(array_unique(array_merge($held, $found)));
            if (count($merged) === count($held)) {
                break;
            }

            $held   = $merged;
            $search = $held;
        }

        return $held;
    }

    /**
     * What we hold about this person, or null if nothing.
     */
    public static function summary(string $input): ?array
    {
        $identifiers = self::resolveIdentifiers($input);
        if (empty($identifiers)) {
            return null;
        }

        $rows = self::rowsFor($identifiers);

        $phonesHeld = self::phoneIdentifiers($identifiers);
        $records    = self::recordsHolding($identifiers, $phonesHeld);

        $conversationIds = $rows->pluck('conversation_id')->filter()->unique()->values();
        $liveConversations = $conversationIds->isEmpty()
            ? []
            : \App\Conversation::whereIn('id', $conversationIds)->pluck('id')->all();

        $orphaned = 0;
        foreach ($rows as $row) {
            if (empty($row->conversation_id) || !in_array($row->conversation_id, $liveConversations)) {
                $orphaned++;
            }
        }

        return [
            'identifiers'    => $identifiers,
            'messages'       => $rows->count(),
            // Account names, not a row count: 'channel_rows' below is the
            // count of contact links, which is a different thing.
            'channel_names'  => WhatsAppAccount::whereIn('id', $rows->pluck('account_id')->unique()->values())
                ->pluck('name', 'id')->all(),
            'first_at'       => $rows->min('created_at'),
            'last_at'        => $rows->max('created_at'),
            'orphaned'       => $orphaned,
            // Both are shown before the button and not only on the receipt:
            // with the channel deleted there are no message rows left, and a
            // screen reporting only those said there was nothing here.
            'channel_rows'   => self::contactLinks($identifiers)->count(),
            'records'            => count($records),
            'records_other_data' => self::recordsHoldingOtherData($records, $identifiers, $phonesHeld),
        ];
    }

    /**
     * Takes the identity out, in one transaction so the numbers on the
     * receipt match what really happened.
     *
     * The identity is resolved again here, not taken from whatever the
     * search returned: a message arriving while the administrator
     * confirms would otherwise survive.
     */
    public static function erase(string $input): array
    {
        return DB::transaction(function () use ($input) {
            $identifiers = self::resolveIdentifiers($input);
            if (empty($identifiers)) {
                return ['identifiers' => [], 'messages' => 0, 'channel_rows' => 0];
            }

            // Both read before anything is cleared: afterwards the columns
            // that say which identifier is a phone number, and which record
            // carries it, are the columns this routine has just emptied.
            $phones    = self::phoneIdentifiers($identifiers);
            $recordIds = self::recordsHolding($identifiers, $phones);

            // Before anything is taken off them, for the same reason as the
            // two lines above: afterwards every one of these records would
            // look like it holds nothing else.
            $recordsOtherData = self::recordsHoldingOtherData($recordIds, $identifiers, $phones);

            // Row by row and not one mass update, because the new
            // identifier is derived from the old one and so differs per
            // row. Meta's message id is base64 with the contact's phone
            // number inside it, so leaving it as it was meant the number
            // survived in the row this screen had just reported as
            // cleaned (T02). The count comes from the loop, so the
            // receipt still reports what actually happened.
            $messages = 0;
            WhatsAppMessage::where(function ($query) use ($identifiers) {
                $query->whereIn('contact_phone', $identifiers)
                    ->orWhereIn('contact_user_id', $identifiers);
            })->chunkById(500, function ($rows) use (&$messages) {
                foreach ($rows as $row) {
                    $row->wamid           = WhatsAppMessage::erasedWamid($row->wamid);
                    $row->contact_phone   = null;
                    $row->contact_user_id = null;
                    $row->save();
                    $messages++;
                }
            });

            // By identifier, never by customer: learnBsuid() can leave the
            // same BSUID on one customer and the phone on another without
            // merging them, so resolving "the" customer would leave the
            // other row alive with the identity in it.
            $channelRows = self::contactLinks($identifiers)->delete();

            // Last, because recordsHolding() reads the links above to decide
            // which of these identifiers is a phone number.
            $customerRecords = self::cleanCustomerRecords($recordIds, $phones, $identifiers);
            $records         = $customerRecords['records'];
            $names           = $customerRecords['names'];

            // How many and when, never who. The log is the one place
            // erasure does not reach, so a log line naming the person
            // would undo the work.
            Log::info('[MetaWhatsApp] Erasure: cleared identity from message rows, contact links and customer records', [
                'messages'     => $messages,
                'channel_rows' => $channelRows,
                'records'      => $records,
                'names'        => $names,
            ]);

            return [
                'identifiers'        => $identifiers,
                'messages'           => $messages,
                'channel_rows'       => $channelRows,
                'records'            => $records,
                'names'              => $names,
                // Counted before the cleaning, with the same call the screen
                // made, so the two figures can be compared. Afterwards the
                // answer would be about what is left on the record.
                'records_other_data' => $recordsOtherData,
            ];
        });
    }

    /**
     * The contact links this module owns, for the identifiers given.
     *
     * One query in one place, because three callers need exactly the same
     * one: the search that decides whether anything is held, the screen that
     * counts it, and the erasure that deletes it.
     */
    protected static function contactLinks(array $identifiers)
    {
        return CustomerChannel::whereIn('channel', [
                WhatsAppAccount::CHANNEL,
                WhatsAppAccount::CHANNEL_BSUID,
            ])
            ->whereIn('channel_id', $identifiers);
    }

    /**
     * Which of these identifiers are phone numbers.
     *
     * Decided by where the identifier is held and never by how it looks. A
     * business-scoped id can be numeric and indistinguishable from a phone,
     * and reading one as a phone is exactly how 'US.34611222333' once
     * matched the owner of that number and filed a message in their
     * conversation. The phone column of our own rows and the phone channel
     * of a contact link are the two places that answer the question without
     * guessing.
     */
    protected static function phoneIdentifiers(array $identifiers): array
    {
        $fromRows = WhatsAppMessage::whereIn('contact_phone', $identifiers)
            ->distinct()
            ->pluck('contact_phone')
            ->all();

        $fromLinks = CustomerChannel::where('channel', WhatsAppAccount::CHANNEL)
            ->whereIn('channel_id', $identifiers)
            ->distinct()
            ->pluck('channel_id')
            ->all();

        return array_values(array_unique(array_merge($fromRows, $fromLinks)));
    }

    /**
     * Customer records whose own columns still carry one of these
     * identifiers, whoever put them there.
     *
     * Not only the records this module wrote, and that is deliberate rather
     * than an oversight: `customers.phones` is the column
     * `Customer::byPhone()` searches, so a number left on anybody's record
     * goes on resolving to this person and the erasure would be incomplete
     * in the one way that matters for a module whose whole job is resolving
     * a number to a customer. An administrator's own imported contact can
     * be one of these, which is why the screen counts separately the ones
     * that hold other data as well (recordsHoldingOtherData), and why only
     * the erased identifiers are taken off them.
     *
     * Two columns. `customers.phones` gets the number
     * when the module creates the customer and when a number change moves
     * one, and it is the column `Customer::byPhone()` searches, so a number
     * left there goes on resolving to this person. `customers.channel_id`
     * gets the identifier from the core's own `Customer::addChannel()`
     * (app/Customer.php:1622), which keeps the pair as a flag meaning "this
     * customer has rows in customer_channel"; nothing in FreeScout clears
     * it, so an erased identifier stayed there as plain data under a receipt
     * that said it was gone.
     *
     * @param array|null $phones which identifiers are phone numbers, when
     *                           the caller has already worked it out
     * @return int[]
     */
    protected static function recordsHolding(array $identifiers, array $phones = null): array
    {
        $ids = [];

        foreach ($phones === null ? self::phoneIdentifiers($identifiers) : $phones as $phone) {
            foreach (CustomerMatcher::matchingIds($phone) as $id) {
                $ids[(int) $id] = true;
            }
        }

        $flagged = Customer::whereIn('channel', [
                WhatsAppAccount::CHANNEL,
                WhatsAppAccount::CHANNEL_BSUID,
            ])
            ->whereIn('channel_id', $identifiers)
            ->pluck('id');

        foreach ($flagged as $id) {
            $ids[(int) $id] = true;
        }

        // And the name, which is a third place the identifier sits and the
        // only one that survives a run of the earlier version of this
        // screen. That is why it is searched rather than migrated: a
        // migration cannot tell a record whose name is a number because it
        // was erased from one whose name is a number because the person
        // still writes every day, while asking for this identifier again
        // says which one is meant.
        foreach (self::namedAfterIds($identifiers) as $id) {
            $ids[(int) $id] = true;
        }

        return array_keys($ids);
    }

    /**
     * How many of those records hold something besides this identity.
     *
     * The administrator needs this before the button, because the figure
     * beside it includes records this module never created: an imported
     * contact that happens to carry the number is reached like any other
     * (see recordsHolding, which explains why it has to be).
     *
     * "Written by this module" is not a question the data can answer. When
     * an inbound message lands on a record that already existed, the core's
     * own addChannel() puts the same channel flag on it as on a record the
     * module created, and an agent can rename either one. What the data does
     * answer is whether the record holds anything else: another number, an
     * e-mail address, or a name somebody typed. That is also the question
     * the administrator is really asking, which is whether they are looking
     * at their own CRM contact.
     *
     * The same figure is reported before and after, so the receipt can be
     * compared with the screen.
     */
    protected static function recordsHoldingOtherData(array $recordIds, array $identifiers, array $phones): int
    {
        if (empty($recordIds)) {
            return 0;
        }

        $count = 0;

        foreach (Customer::whereIn('id', $recordIds)->get() as $customer) {
            if (self::holdsOtherData($customer, $identifiers, $phones)) {
                $count++;
            }
        }

        return $count;
    }

    /** One record, the same three questions. */
    protected static function holdsOtherData(Customer $customer, array $identifiers, array $phones): bool
    {
        foreach ($customer->getPhones() as $phone) {
            $value = isset($phone['value']) ? (string) $phone['value'] : '';

            if ($value === '') {
                continue;
            }

            $erased = false;
            foreach ($phones as $identifier) {
                if (CustomerMatcher::sameNumber($value, $identifier)) {
                    $erased = true;
                    break;
                }
            }

            if (!$erased) {
                return true;
            }
        }

        if ($customer->emails()->count() > 0) {
            return true;
        }

        // Either name, not the first one: an imported contact filed under a
        // surname alone used to answer that it held nothing but this
        // identity, which is the opposite of true and the one question this
        // figure exists to answer.
        //
        // A name nobody typed is the identifier itself, which is what this
        // module writes, so that one says nothing about the record holding
        // more.
        $hasName = trim((string) $customer->first_name) !== ''
            || trim((string) $customer->last_name) !== '';

        return $hasName && !self::nameIsIdentifier($customer, $identifiers);
    }

    /**
     * Takes the identifiers off the customer records, and returns how many
     * records changed.
     *
     * Only the entries that are one of the erased identifiers. Every other
     * number on a record is somebody's work and this says nothing about it,
     * and no record is deleted: the core has no "forget this customer"
     * action, this module does not invent one, and the person's name and
     * conversations are reported as what the administrator still has to do.
     *
     * The core's channel flag is handed to a surviving link of the same
     * customer when there is one, so it keeps meaning what the core means by
     * it, and emptied when there is not.
     */
    protected static function cleanCustomerRecords(array $recordIds, array $phones, array $identifiers): array
    {
        if (empty($recordIds)) {
            return ['records' => 0, 'names' => 0];
        }

        $cleaned = 0;
        $renamed = 0;

        foreach (Customer::whereIn('id', $recordIds)->get() as $customer) {
            $changed = false;

            foreach ($phones as $phone) {
                if (CustomerMatcher::removePhone($customer, $phone)) {
                    $changed = true;
                }
            }

            if (self::nameIsIdentifier($customer, $identifiers)) {
                $customer->first_name = __('metawhatsapp::metawhatsapp.erased_contact_name');
                $customer->last_name  = '';
                $changed              = true;
                $renamed++;
            }

            if (in_array((int) $customer->channel, [WhatsAppAccount::CHANNEL, WhatsAppAccount::CHANNEL_BSUID], true)
                && in_array((string) $customer->channel_id, $identifiers, true)
            ) {
                $survivor = CustomerChannel::where('customer_id', $customer->id)->first();

                $customer->channel    = $survivor ? (int) $survivor->channel : null;
                $customer->channel_id = $survivor ? $survivor->channel_id : null;
                $changed              = true;
            }

            if ($changed) {
                $customer->save();
                $cleaned++;
            }
        }

        return ['records' => $cleaned, 'names' => $renamed];
    }

    /**
     * Is this record's whole name one of the identifiers being erased?
     *
     * This page used to say that a name was never ours to rewrite, because
     * the module cannot tell a record it created from one that already
     * existed under an e-mail with a WhatsApp channel added to it. That is
     * true in general and false in the one case that matters: when the name
     * is literally the identifier being erased, this module is the only
     * thing that could have written it, since naming a new customer after
     * the number or the business-scoped id is what it does. Any other name
     * is somebody's work and is left exactly as it is, surname included,
     * which is why a surname means this is not ours.
     *
     * It matters because the name is the most visible copy of all: it shows
     * in the customer list, in search results and on every conversation in
     * the list, while the places the erasure already reached are columns
     * nobody reads directly.
     *
     * What follows is the query that applies that rule.
     *
     * Records whose whole name is one of these identifiers, by the same rule
     * nameIsIdentifier() applies row by row: no surname, because the module
     * writes the identifier as the whole name and never a surname.
     *
     * One query for both callers on purpose. The screen must not report a
     * record as holding the identity if the erasure is then going to leave
     * its name alone, and the only way to be sure of that is for the search
     * and the rename to ask the same question.
     *
     * Trimmed on both halves of that question, which it was not: the query
     * asked for the name as written while nameIsIdentifier() trimmed before
     * comparing, so a name carrying stray whitespace, a typo or an import,
     * was found by neither the screen nor the erasure, and the number stayed
     * visible as the label on the record. MySQL ignores trailing spaces when
     * it compares strings, so it was the leading ones that got through.
     *
     * The two are still not identical and the difference is worth writing
     * down rather than claiming away: MySQL's TRIM() strips spaces, while
     * PHP's trim() also strips tabs, newlines and null bytes. A first name
     * that begins with a tab is still reached by neither. Closing that in
     * SQL costs three nested REPLACE() calls on every row of the table, and
     * what it buys is a case nothing in this module or in FreeScout writes;
     * an import that produced one would be visible in the customer list.
     *
     * TRIM() in the column means no index on this one, and that is the right
     * trade here: it runs twice on a screen an administrator opens by hand,
     * against a table scan the erasure itself then writes to anyway.
     */
    protected static function namedAfterQuery(array $identifiers)
    {
        return Customer::whereIn(DB::raw('TRIM(first_name)'), $identifiers)
            ->where(function ($query) {
                $query->whereNull('last_name')->orWhereRaw("TRIM(last_name) = ''");
            });
    }

    /** @return string[] */
    protected static function namedAfter(array $identifiers): array
    {
        return self::namedAfterQuery($identifiers)
            ->distinct()
            ->pluck('first_name')
            ->filter()
            ->map(function ($name) {
                return (string) $name;
            })
            ->values()
            ->all();
    }

    /** @return int[] */
    protected static function namedAfterIds(array $identifiers): array
    {
        return self::namedAfterQuery($identifiers)->pluck('id')->all();
    }

    protected static function nameIsIdentifier(Customer $customer, array $identifiers): bool
    {
        if (trim((string) $customer->last_name) !== '') {
            return false;
        }

        return in_array(trim((string) $customer->first_name), $identifiers, true);
    }

    protected static function rowsFor(array $identifiers)
    {
        return WhatsAppMessage::where(function ($query) use ($identifiers) {
            $query->whereIn('contact_phone', $identifiers)
                ->orWhereIn('contact_user_id', $identifiers);
        })->get();
    }

    /**
     * The spellings worth looking for, because we never guess the type of
     * what was pasted.
     *
     * The raw input comes first: a BSUID can legitimately contain a
     * hyphen (extractContactUserId() accepts any printable ASCII except
     * space), so stripping separators before looking would make a
     * hyphenated BSUID unfindable on a screen whose whole job is to prove
     * what we hold.
     *
     * Then the same without separators, because a phone gets pasted as
     * "+34 611-000-001" while it is stored as E.164 without them. And
     * then with a leading '+', because stored phones carry one and most
     * people paste without it. A BSUID can be numeric and
     * indistinguishable from a phone, so both are looked for, against
     * both columns.
     *
     * Finally the digits alone. People write phone numbers with
     * parentheses, dots and slashes, and an administrator answering a
     * request pastes whatever the requester wrote. Stripping only spaces
     * and hyphens left "+34 (611) 000 021" matching nothing, and this
     * screen answers a search that finds nothing with "this module holds
     * nothing about this person". A false nothing is the worst output it
     * can produce: it ends the search, and the administrator reports back
     * in good faith. A leading "00" is the international prefix written
     * out, so it is also tried as '+'.
     *
     * None of this narrows the search. Every variant is looked for, and
     * resolveIdentifiers() only ever returns identifiers that are really
     * in the table, so a variant matching nothing costs nothing.
     */
    protected static function inputVariants(string $input): array
    {
        $raw = trim($input);
        if ($raw === '') {
            return [];
        }

        $variants = [$raw];

        $stripped = preg_replace('/[\s\-]/', '', $raw);
        if ($stripped !== '' && $stripped !== $raw) {
            $variants[] = $stripped;
        }

        if (preg_match('/^\d+$/', $stripped)) {
            $variants[] = '+' . $stripped;
        }

        $digits = preg_replace('/\D/', '', $raw);
        if ($digits !== '') {
            $variants[] = $digits;
            $variants[] = '+' . $digits;

            if (strpos($digits, '00') === 0 && strlen($digits) > 2) {
                $variants[] = '+' . substr($digits, 2);
                $variants[] = substr($digits, 2);
            }
        }

        return array_values(array_unique($variants));
    }
}
