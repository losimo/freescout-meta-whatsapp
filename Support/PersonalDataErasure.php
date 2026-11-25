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
            'records'        => count(self::recordsHolding($identifiers)),
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
            $records = self::cleanCustomerRecords($recordIds, $phones, $identifiers);

            // How many and when, never who. The log is the one place
            // erasure does not reach, so a log line naming the person
            // would undo the work.
            Log::info('[MetaWhatsApp] Erasure: cleared identity from message rows, contact links and customer records', [
                'messages'     => $messages,
                'channel_rows' => $channelRows,
                'records'      => $records,
            ]);

            return [
                'identifiers'  => $identifiers,
                'messages'     => $messages,
                'channel_rows' => $channelRows,
                'records'      => $records,
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
     * identifiers, which this module is the one that put there.
     *
     * Two columns, both written by us. `customers.phones` gets the number
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

        return array_keys($ids);
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
    protected static function cleanCustomerRecords(array $recordIds, array $phones, array $identifiers): int
    {
        if (empty($recordIds)) {
            return 0;
        }

        $cleaned = 0;

        foreach (Customer::whereIn('id', $recordIds)->get() as $customer) {
            $changed = false;

            foreach ($phones as $phone) {
                if (CustomerMatcher::removePhone($customer, $phone)) {
                    $changed = true;
                }
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

        return $cleaned;
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
