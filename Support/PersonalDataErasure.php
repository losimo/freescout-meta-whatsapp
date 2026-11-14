<?php

namespace Modules\MetaWhatsApp\Support;

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
            // Account names, not a row count: see erase() below for the
            // other, differently-shaped 'channel_rows'.
            'channel_names'  => WhatsAppAccount::whereIn('id', $rows->pluck('account_id')->unique()->values())
                ->pluck('name', 'id')->all(),
            'first_at'       => $rows->min('created_at'),
            'last_at'        => $rows->max('created_at'),
            'orphaned'       => $orphaned,
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

            $messages = WhatsAppMessage::where(function ($query) use ($identifiers) {
                $query->whereIn('contact_phone', $identifiers)
                    ->orWhereIn('contact_user_id', $identifiers);
            })->update([
                'contact_phone'   => null,
                'contact_user_id' => null,
            ]);

            // By identifier, never by customer: learnBsuid() can leave the
            // same BSUID on one customer and the phone on another without
            // merging them, so resolving "the" customer would leave the
            // other row alive with the identity in it.
            $channelRows = \App\CustomerChannel::whereIn('channel', [
                    WhatsAppAccount::CHANNEL,
                    WhatsAppAccount::CHANNEL_BSUID,
                ])
                ->whereIn('channel_id', $identifiers)
                ->delete();

            // How many and when, never who. The log is the one place
            // erasure does not reach, so a log line naming the person
            // would undo the work.
            Log::info('[MetaWhatsApp] Erasure: cleared identity from message rows and removed contact channel rows', [
                'messages'     => $messages,
                'channel_rows' => $channelRows,
            ]);

            return [
                'identifiers'  => $identifiers,
                'messages'     => $messages,
                'channel_rows' => $channelRows,
            ];
        });
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
