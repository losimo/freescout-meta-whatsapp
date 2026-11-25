<?php

namespace Modules\MetaWhatsApp\Jobs;

use App\Conversation;
use App\Customer;
use App\CustomerChannel;
use App\Events\CustomerCreatedConversation;
use App\Events\CustomerReplied;
use App\Subscription;
use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;
use Modules\MetaWhatsApp\Support\ConversationReopen;
use Modules\MetaWhatsApp\Support\CoreCompat;
use Modules\MetaWhatsApp\Support\CustomerMatcher;
use Modules\MetaWhatsApp\Support\DeliveryFailure;
use Modules\MetaWhatsApp\Support\InboundStatusRank;
use Modules\MetaWhatsApp\Support\IdentityChangeAudit;
use Modules\MetaWhatsApp\Support\Logger as MetaWhatsAppLogger;
use Modules\MetaWhatsApp\Support\NoteAuthor;
use Modules\MetaWhatsApp\Support\WebhookEventRouter;
use Modules\MetaWhatsApp\Support\WhatsAppTextFormatter;

class ProcessInboundWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // El backoff entre reintents el gestiona el worker (Laravel 5.5).
    public $tries = 3;

    /** @var int */
    protected $accountId;

    /** @var array */
    protected $payload;

    /**
     * Customer ids that hold the sender's number besides the one this message
     * was filed under. FreeScout does not require a phone number to be
     * unique, so the matching can answer with more than one record; they are
     * collected here and named in a note once the conversation exists, which
     * is the only place an agent would see them (issue #59).
     *
     * @var int[]
     */
    protected $otherMatchedCustomerIds = [];

    public function __construct(int $accountId, array $payload)
    {
        $this->accountId = $accountId;
        $this->payload   = $payload;
    }

    public function handle()
    {
        $account = WhatsAppAccount::with('mailbox')->find($this->accountId);
        if (!$account || !$account->is_active) {
            Log::warning('[MetaWhatsApp] ProcessInboundWebhook: account missing or inactive', [
                'account_id' => $this->accountId,
            ]);
            // No flush on this path on purpose: nothing above this line can
            // register a subscription event, so there is nothing of ours in
            // the buffer. If something else is in there it belongs to
            // whatever ran before us in this worker process, and flushing or
            // clearing another job's events is not ours to decide.
            return;
        }

        Log::info('[MetaWhatsApp] Processing inbound webhook', ['account_id' => $account->id]);
        MetaWhatsAppLogger::debugData('[MetaWhatsApp] Inbound webhook payload', ['account_id' => $account->id, 'payload' => $this->payload]);

        // Whatever is in the buffer before we start is not ours. The buffer is
        // static and lives as long as the worker process, so a job that ran
        // before us in the same process could have left something in it. The
        // early-return path above says that flushing or clearing another job's
        // events is not ours to decide, and the failure path below has to obey
        // the same rule rather than clear the lot.
        $preexisting = Subscription::$occurred_events;

        try {
            $this->processPayload($account);
        } catch (\Throwable $e) {
            // Issue #46, failure half. The buffer is a static array, so it is
            // NOT rolled back with the transaction that registered into it.
            // An exception thrown inside the DB::transaction() of
            // processMessage() after firePostEvents() has run (another
            // module's listener on conversation.created_by_customer, a failed
            // commit) leaves an event in there for a conversation or thread
            // that no longer exists.
            //
            // Not a finally: flushing after a rollback is worse than not
            // flushing. On a reopened conversation the row survives the
            // rollback and only the new thread disappears, so processEvents()
            // would pick the previous thread and notify the whole mailbox
            // about a message the agents already read, three times over
            // because the job retries.
            //
            // Accepted case: when a batch carries several messages and a
            // later one throws, the events already registered by the earlier,
            // committed ones are discarded with it, and the retry will not
            // register them again (the wamid is already stored, so they are a
            // no-op). Those agents get no notification for messages that are
            // in their mailbox. It is the quieter of the two failures, and it
            // needs both a multi-message batch and an exception to happen at
            // all.
            // And it says so. A dropped notification that leaves no trace is
            // the same shape of defect as the one this whole change fixes:
            // something reaches the mailbox and nobody is told.
            //
            // The number is buffer entries, and it is named that way because
            // it is not a count of messages: Subscription::registerEvent()
            // appends a second EVENT_TYPE_UPDATED entry for every event that
            // is not NEW, so one customer reply leaves two. Reporting it as
            // "notifications" would be off by roughly double. Entries only,
            // never the events themselves, which would put a customer's
            // conversation in the log for no operational gain.
            $ours = count(Subscription::$occurred_events) - count($preexisting);
            if ($ours > 0) {
                Log::warning('[MetaWhatsApp] Inbound batch failed: agent notifications discarded', [
                    'account_id'       => $account->id,
                    'buffered_entries' => $ours,
                    'error'            => $e->getMessage(),
                ]);
            }

            // Only what we added. registerEvent() appends, so everything past
            // the point we started is ours and the rest goes back untouched.
            Subscription::$occurred_events = $preexisting;
            throw $e;
        }

        // Issue #46. The core listener (App\Listeners\SendNotificationToUsers)
        // does not notify anyone: it only appends to Subscription's static
        // buffer, and core flushes that buffer in exactly two places, the
        // TerminateHandler middleware and the FetchEmails command. Neither one
        // runs in a queue worker, so every event our job registered used to
        // die in the worker's memory and no agent ever heard about an inbound
        // message.
        //
        // Here and not inside firePostEvents(): that runs inside
        // DB::transaction(), and flushing there would dispatch notifications
        // for a transaction that can still roll back. Here and not per
        // message: this is also where FetchEmails flushes, once per run, so
        // several messages for the same conversation coalesce into one
        // notification instead of one per message.
        Subscription::processEvents();
    }

    protected function processPayload(WhatsAppAccount $account)
    {
        foreach ($this->payloadList($this->payload, 'entry') as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            foreach ($this->payloadList($entry, 'changes') as $change) {
                if (!is_array($change)) {
                    continue;
                }

                $value = $change['value'] ?? [];

                // La signatura del POST es valida amb el secret del compte
                // resolt pel PRIMER phone_number_id. Un change amb metadata
                // d'un altre número no s'ha d'atribuir mai a aquest compte
                // (evita misatribució entre canals i injecció creuada).
                $changePhoneId = $value['metadata']['phone_number_id'] ?? null;

                // Messages and statuses are identified by phone_number_id and
                // handled here; everything else goes to the router, which
                // decides by field. This used to be decided by the absence of
                // `metadata`, which was a stand-in for "not a message" and
                // worked by luck: `message_echoes` carries metadata too, and
                // would have sailed past this check into the message path,
                // found nothing to read and done nothing at all, without even
                // logging that it had arrived.
                if (!in_array($change['field'] ?? null, ['messages'], true)) {
                    // Same reason as the phone_number_id guard below: the
                    // account was resolved from the first entry, and a batch
                    // carrying a second entry from another WABA must never be
                    // attributed to this account. This only logged before;
                    // now it writes state, so the guard has to exist.
                    if (($entry['id'] ?? null) !== $account->waba_id) {
                        Log::warning('[MetaWhatsApp] Account level event from another WABA, discarded', [
                            'account_id' => $account->id,
                        ]);
                        continue;
                    }

                    WebhookEventRouter::route($account, $change);
                    continue;
                }

                // A message needs somewhere to be filed; an account level fact
                // does not, so the mailbox check only guards this path. A
                // channel whose mailbox has been deleted must still hear
                // about its own broken templates.
                if (!$account->mailbox) {
                    Log::warning('[MetaWhatsApp] Message event discarded: account has no mailbox', [
                        'account_id' => $account->id,
                    ]);
                    continue;
                }

                if ($changePhoneId === null) {
                    Log::warning('[MetaWhatsApp] Message event without phone_number_id, discarded', [
                        'account_id' => $account->id,
                    ]);
                    continue;
                }

                if ($changePhoneId !== $account->phone_number_id) {
                    Log::warning('[MetaWhatsApp] Change with phone_number_id not matching the account, discarded', [
                        'account_id' => $account->id,
                    ]);
                    continue;
                }

                $messages = $this->payloadList($value, 'messages');
                $contacts = $this->payloadList($value, 'contacts');

                foreach ($messages as $message) {
                    // A malformed element is skipped rather than allowed to
                    // raise a TypeError on the parameter below: nothing in
                    // this loop catches one, so it would take every other
                    // message in the delivery with it, which is the T21
                    // failure mode.
                    if (!is_array($message)) {
                        Log::error('[MetaWhatsApp] Malformed message in the delivery, skipped', [
                            'account_id' => $account->id,
                        ]);
                        continue;
                    }

                    $this->processMessage($account, $message, $contacts);
                }

                foreach ($this->payloadList($value, 'statuses') as $status) {
                    // The same skip as for a message, and here it costs more
                    // than a status: the messages of this delivery are
                    // already written, so a throw would reach handle(), which
                    // discards the buffered agent notifications and rethrows.
                    // The customer's message would sit in the mailbox with
                    // nobody told about it, on every retry.
                    if (!is_array($status)) {
                        Log::error('[MetaWhatsApp] Malformed status in the delivery, skipped', [
                            'account_id' => $account->id,
                        ]);
                        continue;
                    }

                    $this->processStatus($account, $status);
                }
            }
        }
    }

    protected function processMessage(WhatsAppAccount $account, array $message, array $contacts = [])
    {
        $wamid = $this->payloadString($message, 'id');
        if (!$wamid) {
            return;
        }

        // Groups are not supported. A group message carries `group_id`, and a
        // `from` that is the participant who wrote rather than the group, so
        // processing it would file it as a private conversation with that
        // person and send any agent reply to them alone instead of to the
        // group. Refused loudly rather than filed as something it is not.
        //
        // The group id identifies it well enough. The participant's phone stays
        // out of this line on purpose: a group brings in the numbers of people
        // who never wrote to us, and the log is the one place deletion cannot
        // reach. Error level because, from the customer's side, a message they
        // sent has vanished.
        $groupId = $this->payloadString($message, 'group_id');
        if ($groupId) {
            Log::error('[MetaWhatsApp] Message from a group discarded: this module does not support groups', [
                'account_id' => $account->id,
                'group_id'   => $groupId,
                'wamid'      => $wamid,
            ]);
            return;
        }

        $from  = $this->payloadString($message, 'from');
        $type  = $this->payloadString($message, 'type');

        // Meta omits `from` when the person has adopted a WhatsApp username
        // and their phone number is not available to us, and sends
        // `from_user_id` with their business-scoped id instead. Every webhook
        // reference says so in the same warning: "wa_id and from are omitted
        // when the user has adopted a username" (dated corpus of 2026-11-22).
        $fromUserId = $this->payloadString($message, 'from_user_id');

        // Issue #60. Meta announces an identity change with a system message,
        // and it used to fall off the allowlist below and be logged as an
        // error. It is handled here, before anything else reads the payload,
        // and deliberately leaves no row of its own: the window clock counts
        // inbound rows, so recording one would hand the customer a fresh 24
        // hours they never earned.
        if ($type === 'system') {
            $this->processIdentityChange($account, $message);

            return;
        }

        // T21. Nothing to attribute the message to, and this has to be caught
        // here rather than further down: with media, downloadInboundMedia()
        // used to be reached first and raised a TypeError on the null, which
        // nothing in the loop over messages catches, so every message that
        // arrived in the same delivery was lost with it.
        //
        // Error level because, from the customer's side, a message they sent
        // has vanished. Neither identifier goes in the line: there is none to
        // write, and the wamid is what finds it in Meta's own logs.
        if (!$from && !$fromUserId) {
            Log::error('[MetaWhatsApp] Message with no readable sender discarded: neither from nor from_user_id', [
                'account_id' => $account->id,
                'wamid'      => $wamid,
                'type'       => $type,
            ]);

            return;
        }

        // Idempotence, and it sits here rather than further down for a reason
        // that costs money: Meta redelivers a webhook whenever it does not get
        // a 200 from us in time, and below this line the next thing that
        // happens to a media message is a full download of the file from the
        // Graph API, discarded moments later by this very check (T30).
        //
        // Below the sender guard above, though, so that a redelivery of a
        // message we cannot attribute still says so in the log instead of
        // being answered with silence.
        if (WhatsAppMessage::existsForWamid($wamid)) {
            return;
        }

        $mediaTypes = ['image', 'video', 'audio', 'document', 'sticker'];

        // array_merge() i no un spread dins del literal d'array: aquella
        // sintaxi és de PHP 7.4, i el README promet que el mòdul va amb 7.1,
        // que és el terra que declara FreeScout. Amb 7.1, 7.2 o 7.3 aquest
        // fitxer ni es parseja, i com que només es carrega al processar un
        // webhook, la pantalla funcionava i els missatges entrants es perdien
        // amb un 500 que no es veu des de dins del FreeScout.
        $accepted = array_merge($mediaTypes, ['text', 'button', 'location', 'reaction', 'contacts']);

        // An announcement about a message, which is not a message (T39).
        // Writing "a message arrived we cannot show" when what the person did
        // was delete or pin one would be worse than saying nothing, and a row
        // for it would hand them a fresh 24 hours of free window they never
        // earned, which is the reasoning already written for `system`.
        //
        // The top-level types are Coexistence-only, so today they do not
        // reach an installation like this one. **The wrapped ones do**, and a
        // review found that this branch was missing them: the dated corpus of
        // 2026-11-22 lists `edit`, `pin` and `keep_in_chat` among the values
        // of `unsupported.type`, so they arrive inside Meta's `unsupported`
        // envelope rather than as a type of their own.
        if ($this->isAnnouncement($type, $message)) {
            Log::info('[MetaWhatsApp] Announcement about a message, no conversation entry written', [
                'account_id' => $account->id,
                'wamid'      => $wamid,
                'type'       => $type,
            ]);

            return;
        }

        // Somebody opening a chat without writing, which Meta sends as
        // `request_welcome` when no thread exists yet, typically from an ad
        // that clicks to WhatsApp. Found in review (2026-11-24); it is not in
        // Meta's dated corpus of 2026-11-22 and was confirmed from their
        // conversational components page and 360dialog's documentation.
        //
        // It is neither of the two tiers above and below. Nobody sent a
        // message, so the unrenderable sentence would be a lie, but the row
        // belongs here all the same: the event opens the customer service
        // window, which is the whole reason a business can greet somebody who
        // has not written yet. Leaving it out would tell the agent the window
        // is closed while a free-form reply would be accepted.
        //
        // No log line of its own, unlike the branch below: this is ordinary
        // operation rather than something the module could not do, and it
        // leaves a thread and a row, which is a better trace than a log entry
        // nobody reads. A line here would also have been written before the
        // sender is validated, so a malformed payload would have announced
        // that somebody opened a chat and then that the message was discarded.
        $openedChat = $type === 'request_welcome';

        // T39. A type we cannot draw is still a message the person sent, so it
        // goes to the conversation with a sentence saying so, instead of being
        // dropped into the log where only an administrator would ever find it.
        //
        // The expensive half was not the drawing: both window clocks read
        // inbound rows, and a dropped message left none, so Meta's 24 hours
        // were running while the module told the agent the window had closed
        // and offered a paid template in place of a free reply.
        //
        // Warning and no longer error, because the message is no longer
        // invisible, and without the sender's phone number: the log is one of
        // the few places erasure cannot reach, which is the rule the group
        // branch above already follows.
        $unrenderable = !$openedChat && !in_array($type, $accepted, true);
        if ($unrenderable) {
            Log::warning('[MetaWhatsApp] Message of a type this module cannot render, shown as a note', [
                'account_id' => $account->id,
                'wamid'      => $wamid,
                'type'       => $type,
            ]);
        }

        // Missatge multimèdia: extreu l'objecte del tipus corresponent.
        $media = null;
        if (in_array($type, $mediaTypes, true)) {
            // is_array and not a truth test: a scalar here reached
            // downloadInboundMedia(array $mediaObject) and raised a TypeError
            // that nothing in the loop over messages catches, which is the
            // same class as the payload reads above.
            $media = isset($message[$type]) && is_array($message[$type]) ? $message[$type] : null;
            if (!$media) {
                return;
            }
            // Descarrega l'adjunt. Si falla, crea igualment el thread amb avís.
            $media = $this->downloadInboundMedia($account, $type, $media, $wamid);
        }

        // Missatge de text o multimèdia amb caption.
        if ($openedChat) {
            $text = __('metawhatsapp::metawhatsapp.inbound_chat_opened');
        } elseif ($unrenderable) {
            $text = $this->unrenderableText($message, $type);
        } elseif ($type === 'button') {
            $text = trim($message['button']['text'] ?? '');
        } elseif ($type === 'location') {
            $text = $this->formatLocationText($message['location'] ?? []);
        } elseif ($type === 'reaction') {
            $text = $this->formatReactionText($account, $message['reaction'] ?? []);
        } elseif ($type === 'contacts') {
            $text = $this->formatContactsText($message['contacts'] ?? []);
        } else {
            $text = trim($message['text']['body'] ?? '');
        }
        if ($media && $media['ok'] && $media['caption']) {
            $text = $media['caption'];
        } elseif ($media && !$media['ok']) {
            // T16. The caption is the customer's own sentence and the notice
            // is ours, so a download that failed costs the file and not the
            // words. Their words first: the thread is a conversation, not a
            // status page.
            $notice = __('metawhatsapp::metawhatsapp.media_attachment_unavailable');
            $text   = $media['caption'] !== ''
                ? $media['caption'] . "\n" . $notice
                : $notice;
        } elseif ($media && $media['ok']) {
            $text = __('metawhatsapp::metawhatsapp.media_preview_no_caption', ['type' => $type]);
        }

        if ($text === '' && !$media) {
            return;
        }

        // BSUID (Business-Scoped User ID): amb els usernames de WhatsApp,
        // contacts[].user_id és l'identificador estable i 'from' pot deixar
        // de ser un telèfon usable.
        //
        // contacts[] stays the preferred source and from_user_id is only the
        // fallback, because the contact block is also what carries the profile
        // name. Both identifiers are handed to the selection, so the block is
        // searched by whichever of them the message actually came with.
        $contact     = $this->selectContact($contacts, $from, $fromUserId);
        $userId      = $this->extractContactUserId($account, $contact)
            ?: $this->sanitisedUserId($account, $fromUserId, 'from_user_id');
        // Meta's profile name is deliberately not read at all since #59.
        // The reporter's argument was that it is often initials or a nickname,
        // so it looks like a real name without being one, while a number or a
        // business-scoped id reads as "not matched to anybody yet". Nothing in
        // this module writes it, so there is nothing to extract it for.

        // Telèfon usable: E.164 sense '+' (format de Meta). Valors estranys
        // reventarien contact_phone (VARCHAR 20) i embrutarien customer_channel.
        // Un 'from' idèntic al user_id és el BSUID, encara que sigui numèric.
        // Through normalisedPhone() and not a second inline rule: this file
        // had two shape rules for the same question and they disagreed, so a
        // `from` could be matched against a contact block as a phone number
        // here and then refused as one four lines later, which turned a phone
        // sender into a customer known only by their business-scoped id.
        $candidate = $this->normalisedPhone($from);

        // A `from` identical to the business-scoped id is that id, even when
        // it is all digits.
        $phone = $candidate !== null && ltrim($candidate, '+') !== $userId
            ? $candidate
            : null;

        if (!$phone && !$userId) {
            Log::error('[MetaWhatsApp] Sender without valid phone or user_id, discarded', [
                'account_id' => $account->id,
            ]);
            return;
        }

        // BSUID massa llarg per a customer_channel (VARCHAR 64): sense telèfon
        // no hi ha cap via de resolució. Es persisteix el missatge (VARCHAR 100)
        // i es falla de manera controlada, com a la fase 1.
        if (!$phone && strlen($userId) > 64) {
            Log::warning('[MetaWhatsApp] BSUID exceeds customer_channel.channel_id length; stored in module message only, not learned as customer channel.', [
                'account_id' => $account->id,
            ]);
            if (!WhatsAppMessage::existsForWamid($wamid)) {
                try {
                    WhatsAppMessage::create([
                        'wamid'           => $wamid,
                        'account_id'      => $account->id,
                        'contact_user_id' => $userId,
                        'direction'       => WhatsAppMessage::DIRECTION_INBOUND,
                        'status'          => WhatsAppMessage::STATUS_RECEIVED,
                        'meta_sent_at'    => $this->metaSentAt($message),
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // Carrera de duplicats: el UNIQUE de wamid ha fet la seva feina.
                    if ((string) ($e->errorInfo[1] ?? '') !== '1062') {
                        throw $e;
                    }
                }
            }
            return;
        }

        $metaSentAt = $this->metaSentAt($message);

        try {
            DB::transaction(function () use ($account, $wamid, $phone, $userId, $text, $media, $metaSentAt) {
                $customer = $this->resolveCustomer($account, $phone, $userId);

                // Patró de xat del core (#4902): es reutilitza la darrera conversa
                // encara que estigui tancada (es reobre), tret que l'opció de
                // bústia 'chat_start_new' digui de començar-ne una de nova.
                // El filtre per type és la meitat defensiva de l'issue #44: el
                // botó "New Conversation" del menú lateral del core deixa que
                // un agent creï una conversa de correu en una bústia de
                // WhatsApp, i com que aquesta tria ordena per created_at,
                // aquella conversa (més nova) s'enduia els entrants següents.
                // El mòdul només és propietari de converses de xat: la línia
                // 298 és l'únic lloc del codi de producció que crea una
                // conversa i sempre hi posa TYPE_CHAT, així que això només pot
                // saltar files que no ha creat el mòdul.
                //
                // The channel filter is the other half of the same defence,
                // and it became necessary with issue #59: the customer this
                // message is filed under may be a record that existed before
                // WhatsApp, and another chat channel may already own a chat
                // conversation of theirs. The null branch keeps the
                // conversations created by module versions that did not stamp
                // the channel yet, so an upgrade does not split a history.
                //
                // The candidates are walked instead of taking the newest one,
                // because a conversation belongs to a number and not only to
                // a person: since issue #59 the same customer record can hold
                // two WhatsApp numbers, and filing a message from the second
                // into the first one's conversation would make a reply go to
                // whoever wrote last. The limit is there because this is a
                // list of one or two rows in every real case.
                $conversation = null;
                $candidates   = Conversation::where('mailbox_id', $account->mailbox_id)
                    ->where('customer_id', $customer->id)
                    ->where('type', Conversation::TYPE_CHAT)
                    ->where('status', '!=', Conversation::STATUS_SPAM)
                    ->where(function ($query) {
                        $query->where('channel', WhatsAppAccount::CHANNEL)
                            ->orWhereNull('channel');
                    })
                    ->orderBy('created_at', 'desc')
                    ->limit(20)
                    ->get();

                foreach ($candidates as $candidate) {
                    if ($phone !== null && $this->conversationHoldsAnotherNumber($candidate, $phone)) {
                        continue;
                    }
                    $conversation = $candidate;
                    break;
                }

                if ($conversation && $conversation->chatShouldStartNew($account->mailbox)) {
                    $conversation = null;
                }

                $isNew = !$conversation;
                // ENT_SUBSTITUTE, and it is a landmine rather than a live
                // defect (our own finding 16). Without it htmlspecialchars()
                // answers the empty string for a malformed UTF-8 sequence and
                // the message would be stored with no text at all. Measured on
                // PHP 8.2.28, though, json_decode() refuses such a body before
                // this module sees it, json_encode() refuses to queue it, and
                // no substr() here cuts customer text, so there is no route in
                // today. The constant costs nothing and closes the route
                // before someone opens one.
                $body  = nl2br(WhatsAppTextFormatter::format(
                    htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                ));

                if ($isNew) {
                    $conversation = new Conversation();
                    $conversation->type                   = Conversation::TYPE_CHAT;
                    // Sense això, Conversation::getChannelName() torna '' i el
                    // core amaga tant el botó "Chat Mode" com el fs-tag del
                    // canal (conversations/view.blade.php: @if ($conversation
                    // ->isChat() && $conversation->getChannelName())) — arrel
                    // de la issue #5 (botó Chat + label WhatsApp absents).
                    $conversation->channel                = WhatsAppAccount::CHANNEL;
                    $conversation->state                  = Conversation::STATE_PUBLISHED;
                    $displayPhone = $phone ?: $userId;
                    $conversation->subject                = $account->conversation_subject_template
                        ? str_replace(['%YEAR%', ':phone'], [date('Y'), $displayPhone], $account->conversation_subject_template)
                        : __('metawhatsapp::metawhatsapp.conversation_subject', ['phone' => $displayPhone]);
                    $conversation->mailbox_id             = $account->mailbox_id;
                    $conversation->customer_id            = $customer->id;
                    $conversation->customer_email         = '';
                    $conversation->created_by_customer_id = $customer->id;
                    $conversation->source_via             = Conversation::PERSON_CUSTOMER;
                    $conversation->source_type            = Conversation::SOURCE_TYPE_API;
                }

                // Patró canònic de FetchEmails (inclou el matís de last_reply_at
                // de la issue #5225 del core).
                $conversation->status = \Eventy::filter(
                    'conversation.status_changing',
                    Conversation::STATUS_ACTIVE,
                    $conversation
                );
                if ($conversation->last_reply_from != Conversation::PERSON_CUSTOMER
                    || !$conversation->last_reply_at
                ) {
                    $conversation->last_reply_at = now();
                }
                $conversation->last_reply_from = Conversation::PERSON_CUSTOMER;
                $conversation->setPreview($text);
                if ($conversation->state == Conversation::STATE_DELETED) {
                    $conversation->state = Conversation::STATE_PUBLISHED;
                }
                $conversation->updateFolder();
                $conversation->save();

                // Before the customer's thread and not after it: the core's
                // ThreadObserver sets the conversation preview from any note
                // it sees (app/Observers/ThreadObserver.php, the preview
                // branch includes TYPE_NOTE), so a note written afterwards
                // would replace the message in the folder list with our own
                // warning. Written first, the customer's message overwrites
                // the preview again and the warning still sits above it in
                // the conversation.
                $this->noteOtherMatchedCustomers($conversation);

                $thread = new Thread();
                $thread->conversation_id        = $conversation->id;
                $thread->user_id                = $conversation->user_id;
                $thread->type                   = Thread::TYPE_CUSTOMER;
                $thread->status                 = $conversation->status;
                $thread->state                  = Thread::STATE_PUBLISHED;
                $thread->body                   = $body;
                $thread->from                   = $phone ?: $userId;
                $thread->source_via             = Thread::PERSON_CUSTOMER;
                $thread->source_type            = Thread::SOURCE_TYPE_API;
                $thread->customer_id            = $customer->id;
                $thread->created_by_customer_id = $customer->id;
                $thread->first                  = $isNew;
                $thread->save();

                // Crea l'adjunt si s'ha descarregat correctament.
                if ($media && $media['ok']) {
                    $category = $this->attachmentCategory($media['type'] ?? 'image');
                    $typeInt = \App\Attachment::typeNameToInt($category);
                    \App\Attachment::create(
                        $media['filename'],
                        $media['mime_type'],
                        $typeInt,
                        $media['bytes'],
                        null,
                        false,
                        $thread->id
                    );
                    $thread->has_attachments = true;
                    $thread->save();
                    $conversation->has_attachments = true;
                    $conversation->save();
                }

                WhatsAppMessage::create([
                    'wamid'           => $wamid,
                    'account_id'      => $account->id,
                    'conversation_id' => $conversation->id,
                    'thread_id'       => $thread->id,
                    'contact_phone'   => $phone,
                    'contact_user_id' => $userId,
                    'direction'       => WhatsAppMessage::DIRECTION_INBOUND,
                    'status'          => WhatsAppMessage::STATUS_RECEIVED,
                    'meta_sent_at'    => $metaSentAt,
                ]);

                $this->firePostEvents($account, $conversation, $thread, $customer, $isNew);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Carrera de duplicats: el UNIQUE de wamid ha fet la seva feina.
            if ((string) ($e->errorInfo[1] ?? '') === '1062') {
                return;
            }
            throw $e;
        }
    }

    /**
     * A scalar field of the payload as a string, or null.
     *
     * One reader for all of them, and the reason is a whole class of defect
     * rather than tidiness. Meta's payload is JSON from outside, so any field
     * can arrive as an array, and this file hands those fields straight to
     * typed parameters. Each one raised a TypeError that nothing in the loops
     * over messages and statuses catches, so a single malformed field took
     * every other message in the delivery with it. That is the T21 failure
     * mode arriving one field at a time, found in the fifth review round
     * after four rounds of guarding one field at a time.
     *
     * Null for anything that is not scalar, which the callers already handle:
     * they have to, because the field can also be absent.
     *
     * **What is deliberately not routed through here, and the bound on it.**
     * The nested reads of a message's own content still use `?? ''` and a
     * cast: the text body, a button's text, a location's name and address, a
     * shared contact's name and phone, a reaction's emoji, and an error's
     * code. Each would raise the same exception if Meta sent an object where
     * a string is documented. They are left because the bound is real rather
     * than hopeful: the webhook's HMAC signature is verified before this job
     * is dispatched, so the only sender is Meta, and reaching any of them
     * needs Meta to change a field's type, which would appear in the dated
     * corpus before it appeared in a payload. The fields that are routed
     * through here are the ones that decide **who** a message belongs to and
     * which identifiers move between customers, where the cost of being
     * wrong is somebody else's conversation rather than a lost line of text.
     */
    protected function payloadString(array $source, string $key): ?string
    {
        if (!isset($source[$key]) || !is_scalar($source[$key])) {
            return null;
        }

        return (string) $source[$key];
    }

    /**
     * A list field of the payload, or an empty list.
     *
     * Same reason as payloadString(), one level up: `foreach` over a scalar
     * raises a warning that Laravel's handler turns into an exception. A
     * collection that is present but is not one loses every message in the
     * delivery, so it is logged at error level, which is what this file does
     * everywhere a customer's message vanishes.
     */
    protected function payloadList(array $source, string $key): array
    {
        if (!isset($source[$key])) {
            return [];
        }

        if (!is_array($source[$key])) {
            Log::error('[MetaWhatsApp] Malformed ' . $key . ' in the webhook payload, nothing processed from it', [
                'account_id' => $this->accountId,
            ]);

            return [];
        }

        return $source[$key];
    }

    /**
     * The contact block entry that belongs to this message's sender.
     *
     * **Positive match only, after four review rounds spent on a fallback
     * that should never have existed.** An entry is this sender's when it
     * carries an identifier the message carries. Nothing else is used: no
     * first entry of the block, no counting of entries or messages, no
     * reasoning about which entries contradict the sender.
     *
     * Every one of those was mine and every one leaked, each in a shape the
     * rule before it could not see: the first entry of the block gave a
     * sender with no phone number the previous person's identity; allowing
     * that only for a single contact object leaked when Meta sent two senders
     * and one entry; and treating only a two-sided difference as a
     * contradiction adopted an entry carrying `wa_id` for a sender who has no
     * phone at all, which is the one sender whose entry Meta omits. The last
     * of those also learned somebody else's business-scoped id onto the wrong
     * customer, which is the identity merge the BSUID channel exists to
     * prevent (#59).
     *
     * **The fallback was solving a problem Meta does not create.** In the
     * dated corpus of 2026-11-22 the contact block's `user_id` carries no
     * condition, while `wa_id` is "omitted if user's phone number is
     * unavailable", and the BSUID is documented as "included whether or not
     * the user has adopted a username". The identifier that is always present
     * is therefore the one a match can be made on, and an entry identifying
     * nobody is not a delivery Meta makes. Three rounds of argument came from
     * test fixtures of mine that invented one.
     *
     * The cost, if it happens anyway: the profile name is lost and the
     * customer is named after their identifier. That is worth less than
     * attributing a message to the right person, which is the same trade
     * written into the phone matching of #59.
     */
    protected function selectContact(array $contacts, ?string $from, ?string $fromUserId = null): ?array
    {
        $wantedPhone  = $this->normalisedPhone($from);
        $wantedUserId = $fromUserId !== null && $fromUserId !== '' ? $fromUserId : null;

        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }

            // is_scalar and a cast rather than is_string, so the reach is the
            // same on both fields, and never a bare cast: these values come
            // from outside, and casting an array raises an exception that
            // nothing in the loop over messages catches, which is the T21
            // failure this file exists to have fixed.
            $waId = isset($contact['wa_id']) && is_scalar($contact['wa_id'])
                ? $this->normalisedPhone((string) $contact['wa_id'])
                : null;
            $userId = isset($contact['user_id']) && is_scalar($contact['user_id'])
                ? (string) $contact['user_id']
                : null;

            if ($wantedUserId !== null && $userId === $wantedUserId) {
                return $contact;
            }

            if ($wantedPhone !== null && $waId === $wantedPhone) {
                return $contact;
            }

            // `from` can itself be a business-scoped id, which this module has
            // always allowed and normalisedPhone() answers null for.
            if ($from !== null && $from !== '' && $userId === $from) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * Whether this is an announcement about a message rather than a message.
     *
     * Two places have to be read, which is what a review found missing. Meta
     * sends some of these as a type of their own, and the same events also
     * arrive wrapped: the dated corpus of 2026-11-22 lists `edit`, `pin` and
     * `keep_in_chat` among the values of `unsupported.type`, where the
     * top-level type is `unsupported`.
     *
     * Error 131060 was excluded here too, on a reading of the corpus that was
     * mine and not in it: that it fires on the owner of a number when
     * somebody else writes to a number already in use with the API. The
     * corpus says the opposite, twice. It is a WhatsApp user messaging a
     * business whose number is also in the WhatsApp Business app, "for the
     * first time", or one writing from an unsupported companion device, and
     * Meta's own instruction is to "check the WhatsApp Business app for the
     * message" (`webhooks_reference_messages_unsupported` and
     * `embedded-signup_onboarding-business-app-users`, corpus of
     * 2026-11-22). So it is a customer writing, and discarding it threw away
     * somebody's first message, which is the exact loss T39 was opened for.
     */
    protected function isAnnouncement(?string $type, array $message): bool
    {
        $announcements = ['revoke', 'edit', 'pin', 'keep_in_chat'];

        if (in_array($type, $announcements, true)) {
            return true;
        }

        if ($type !== 'unsupported') {
            return false;
        }

        return in_array($this->unsupportedKind($message), $announcements, true);
    }

    /** The code of the first error Meta attached to a message, if any. */
    protected function firstErrorCode(array $message): ?string
    {
        $errors = isset($message['errors']) && is_array($message['errors']) ? $message['errors'] : [];
        $first  = isset($errors[0]) && is_array($errors[0]) ? $errors[0] : [];

        return $this->payloadString($first, 'code');
    }

    /** The original kind Meta names inside its `unsupported` envelope. */
    protected function unsupportedKind(array $message): ?string
    {
        $wrapper = isset($message['unsupported']) && is_array($message['unsupported'])
            ? $message['unsupported']
            : [];
        $kind    = $this->payloadString($wrapper, 'type');

        return $kind !== null && trim($kind) !== '' ? trim($kind) : null;
    }

    /**
     * The sentence an agent reads in place of a message this module cannot
     * draw (T39).
     *
     * Meta's own `unsupported` type names the original kind in
     * `unsupported.type`, and that name is worth printing: it is what lets an
     * agent say "you sent a poll" rather than "something went wrong". For any
     * other type the type itself is the best name there is. Both are Meta's
     * vocabulary and neither is personal data.
     */
    protected function unrenderableText(array $message, ?string $type): string
    {
        // Error 131060 is not an unsupported kind of message, it is a message
        // WhatsApp did not hand over, and the sentence it deserves is the one
        // Meta gives for it: look for it in the WhatsApp Business app. Naming
        // whatever is in `unsupported.type` here would tell the agent the
        // customer sent that kind of thing, which this payload does not say.
        if ($this->firstErrorCode($message) === '131060') {
            return __('metawhatsapp::metawhatsapp.inbound_unavailable');
        }

        $kind = $type === 'unsupported' ? $this->unsupportedKind($message) : $type;

        if ($kind === null || $kind === '') {
            return __('metawhatsapp::metawhatsapp.inbound_unrenderable');
        }

        return __('metawhatsapp::metawhatsapp.inbound_unrenderable_named', ['kind' => $kind]);
    }

    /**
     * Formata una ubicació compartida com a text amb enllaç a Google Maps.
     */
    protected function formatLocationText(array $location): string
    {
        $lat = $location['latitude'] ?? null;
        $lng = $location['longitude'] ?? null;
        if ($lat === null || $lng === null) {
            return '';
        }

        $name    = trim($location['name'] ?? '');
        $address = trim($location['address'] ?? '');
        $label   = trim($name . ($name !== '' && $address !== '' ? ' - ' : '') . $address);
        $link    = "https://www.google.com/maps?q={$lat},{$lng}";

        return $label !== '' ? "{$label}\n{$link}" : $link;
    }

    /**
     * Formata un missatge type:contacts (targeta/es de contacte compartides
     * — issue #14, item 4). Sense adjunt ni descàrrega: només nom + primer
     * telèfon de cada contacte compartit, un per línia.
     */
    protected function formatContactsText(array $contacts): string
    {
        $lines = [];
        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $name  = trim($contact['name']['formatted_name'] ?? '');
            $phone = trim($contact['phones'][0]['phone'] ?? '');
            if ($name === '' && $phone === '') {
                continue;
            }
            $lines[] = $phone !== ''
                ? ($name !== '' ? "{$name} ({$phone})" : $phone)
                : $name;
        }

        if (empty($lines)) {
            return __('metawhatsapp::metawhatsapp.contacts_shared_empty');
        }

        return __('metawhatsapp::metawhatsapp.contacts_shared') . "\n" . implode("\n", $lines);
    }

    /**
     * Formata una reacció (emoji) a un missatge previ com a text.
     */
    /**
     * Formata una reacció, citant un extracte del missatge al qual reacciona
     * (issue #14) quan el podem localitzar per message_id. Sense això, al fil
     * de la conversa no queda clar a quin missatge es refereix la reacció.
     */
    protected function formatReactionText(WhatsAppAccount $account, array $reaction): string
    {
        $emoji  = trim($reaction['emoji'] ?? '');
        $excerpt = $this->excerptForReactionTarget($account, $reaction['message_id'] ?? null);

        if ($excerpt !== null) {
            return $emoji !== ''
                ? __('metawhatsapp::metawhatsapp.reaction_text_quoted', ['emoji' => $emoji, 'excerpt' => $excerpt])
                : __('metawhatsapp::metawhatsapp.reaction_removed_quoted', ['excerpt' => $excerpt]);
        }

        return $emoji !== ''
            ? __('metawhatsapp::metawhatsapp.reaction_text', ['emoji' => $emoji])
            : __('metawhatsapp::metawhatsapp.reaction_removed');
    }

    /**
     * Extracte de text pla (sense HTML) del thread del missatge al qual es
     * reacciona, o null si no el trobem (missatge fora de rang, esborrat,
     * o d'abans que el mòdul enregistrés thread_id).
     */
    protected function excerptForReactionTarget(WhatsAppAccount $account, ?string $targetWamid): ?string
    {
        if (!$targetWamid) {
            return null;
        }

        $target = WhatsAppMessage::where('wamid', $targetWamid)
            ->where('account_id', $account->id)
            ->first();
        if (!$target || !$target->thread_id) {
            return null;
        }

        $thread = Thread::find($target->thread_id);

        return $thread ? $this->excerptFromBody($thread->body) : null;
    }

    /**
     * Extracte curt i en text pla del cos d'un thread, per citar-lo dins
     * d'una nota. Mateix límit de 60 caràcters per a reaccions (#14) i per
     * a lliuraments fallits (#19).
     */
    protected function excerptFromBody(?string $body): ?string
    {
        if (!$body) {
            return null;
        }

        $plain = trim(html_entity_decode(strip_tags(str_replace(
            ['<br>', '<br/>', '<br />'],
            ' ',
            $body
        )), ENT_QUOTES, 'UTF-8'));

        if ($plain === '') {
            return null;
        }

        return mb_strlen($plain) > 60 ? mb_substr($plain, 0, 60) . '…' : $plain;
    }

    /**
     * Extreu i saneja el BSUID (user_id) del contacte seleccionat.
     */
    protected function extractContactUserId(WhatsAppAccount $account, ?array $contact): ?string
    {
        $userId = is_array($contact) ? ($contact['user_id'] ?? null) : null;

        return $this->sanitisedUserId($account, $userId, 'contacts[].user_id');
    }

    /**
     * One validation for both places a business-scoped id can arrive from,
     * `contacts[].user_id` and the message's own `from_user_id`. Shared on
     * purpose: two copies of this check is the shape that produced the same
     * bug four times over (#18, #25, #28, #29).
     *
     * @param string|null $userId raw value as it came in the payload
     * @param string      $source named in the log, so a malformed value can be
     *                            traced back to the field it came from
     */
    protected function sanitisedUserId(WhatsAppAccount $account, $userId, string $source): ?string
    {
        if (!is_string($userId) || $userId === '') {
            return null;
        }

        // Sanejament: cap a la columna (VARCHAR 191); només ASCII imprimible
        // sense espais. El sostre real de Meta és de 131 (codi de país, punt i
        // fins a 128 alfanumèrics); la columna en deixa més perquè rebutjar
        // una cosa que hi cabria no ens aporta res, i abans el límit de 100
        // feia perdre el missatge sencer quan no venia telèfon.
        if (!preg_match('/^[\x21-\x7E]{1,191}$/', $userId)) {
            Log::warning('[MetaWhatsApp] Business-scoped user id has unexpected format, ignored', [
                'account_id' => $account->id,
                'source'     => $source,
            ]);

            return null;
        }

        return $userId;
    }

    /**
     * Resol el client del missatge. Phone-first: si hi ha telèfon usable, el
     * comportament és exactament l'actual; el BSUID només resol quan no n'hi ha.
     */
    protected function resolveCustomer(WhatsAppAccount $account, ?string $phone, ?string $userId): Customer
    {
        if ($phone) {
            $customer = $this->findOrCreateCustomer($phone);
            if ($userId) {
                $this->learnBsuid($account, $customer, $userId);
            }

            return $customer;
        }

        return $this->findOrCreateCustomerByBsuid($userId);
    }

    /**
     * Client per BSUID: el troba pel canal o crea un placeholder sense telèfon.
     */
    protected function findOrCreateCustomerByBsuid(string $userId): Customer
    {
        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $userId);
        if ($customer) {
            return $customer;
        }

        $customer = new Customer();
        // Named after the identifier, not after Meta's profile name, which
        // the reporter asked for in #59 with the better argument: a profile
        // name is often initials or a nickname, so it looks like a real name
        // without being one, while an identifier reads as "not matched to
        // anybody yet", which is what an agent needs to see.
        //
        // Written straight in, as the phone path does. It used to go through
        // the core's name splitter, which was there to read "Smith, John"
        // out of a profile name; with no profile name read anywhere there is
        // no name to split, and a business-scoped id has no first and last
        // half. The column takes 255 and sanitisedUserId() caps the value at
        // 191.
        $customer->first_name = $userId;
        $customer->last_name  = '';
        $customer->save();
        $customer->addChannel(WhatsAppAccount::CHANNEL_BSUID, $userId);

        return $customer;
    }

    /**
     * Aprèn el mapping BSUID→client quan telèfon i BSUID arriben junts.
     * Període de transició de Meta: és el que permet reconèixer el client
     * quan més endavant amagui el número.
     */
    protected function learnBsuid(WhatsAppAccount $account, Customer $customer, string $userId): void
    {
        if (strlen($userId) > 64) {
            Log::warning('[MetaWhatsApp] BSUID exceeds customer_channel.channel_id length; stored in module message only, not learned as customer channel.', [
                'account_id'  => $account->id,
                'customer_id' => $customer->id,
            ]);
            return;
        }

        $row = CustomerChannel::where('channel', WhatsAppAccount::CHANNEL_BSUID)
            ->where('channel_id', $userId)
            ->first();

        if (!$row) {
            // addChannel és idempotent per al mateix client: actualitza la fila
            // existent per (customer_id, channel) en lloc de duplicar-la. El
            // UNIQUE(channel, channel_id) només cobreix la carrera entre
            // clients diferents pel mateix BSUID.
            $customer->addChannel(WhatsAppAccount::CHANNEL_BSUID, $userId);
            return;
        }

        if ((int) $row->customer_id === (int) $customer->id) {
            return;
        }

        $owner = $row->customer;
        if ($owner && $this->isPurePlaceholder($owner, $userId)) {
            $this->mergePlaceholder($owner, $customer, $row);
            return;
        }

        // Client real amb el mateix BSUID: anomalia (Meta regenera el BSUID en
        // canviar de número). Mai fusionem dos clients humans automàticament.
        Log::warning('[MetaWhatsApp] BSUID already linked to a different non-placeholder customer; no merge performed', [
            'account_id'        => $account->id,
            'bsuid_customer_id' => $row->customer_id,
            'phone_customer_id' => $customer->id,
        ]);
    }

    /**
     * Placeholder pur: el seu únic canal és exactament aquest BSUID i no té
     * ni emails ni telèfons. És la porta de seguretat de la fusió automàtica.
     */
    protected function isPurePlaceholder(Customer $customer, string $userId): bool
    {
        $channels = CustomerChannel::where('customer_id', $customer->id)->get();
        if ($channels->count() !== 1) {
            return false;
        }
        $only = $channels->first();
        if ((int) $only->channel !== WhatsAppAccount::CHANNEL_BSUID || $only->channel_id !== $userId) {
            return false;
        }
        if ($customer->getMainEmail()) {
            return false;
        }
        if (!empty($customer->getPhones())) {
            return false;
        }

        return true;
    }

    /**
     * Fusiona un placeholder pur en el client real: mou les converses,
     * re-apunta el canal BSUID i deixa el placeholder inert i anotat.
     * No s'esborra (decisió operativa conservadora): sense canals, cap
     * resolució futura no el pot tornar a seleccionar.
     */
    protected function mergePlaceholder(Customer $placeholder, Customer $target, CustomerChannel $channelRow): void
    {
        foreach (Conversation::where('customer_id', $placeholder->id)->get() as $conversation) {
            // Mateix camí de codi que la UI del core: comptadors i esdeveniments coberts.
            $conversation->changeCustomer('', $target);
        }

        // Cas límit conegut: si el client destí ja tingués un altre BSUID
        // après al canal 101, aquest re-point hi deixaria dues files. És
        // improbable (Meta regenera el BSUID) i deliberadament no es gestiona
        // en aquest increment.
        $channelRow->customer_id = $target->id;
        $channelRow->save();

        $placeholder->notes = trim(
            ($placeholder->notes ? $placeholder->notes . "\n" : '')
            . 'Merged into customer #' . $target->id . ' by MetaWhatsApp (BSUID).'
        );
        $placeholder->save();

        Log::info('[MetaWhatsApp] BSUID placeholder merged into existing customer', [
            'placeholder_id' => $placeholder->id,
            'customer_id'    => $target->id,
        ]);
    }

    /**
     * The person behind a phone number, in three steps: the WhatsApp channel
     * first, then a customer who already holds that number, and only then a
     * new record.
     *
     * The middle step is issue #59. Without it an administrator who had
     * imported their customers with their phone numbers got a second copy of
     * every one of them, named after the number, the first time they wrote.
     */
    protected function findOrCreateCustomer(string $phone): Customer
    {
        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, $phone);
        if ($customer) {
            return $customer;
        }

        $matchedIds = CustomerMatcher::matchingIds($phone);
        if ($matchedIds) {
            // The oldest record wins, because it is the deterministic answer
            // and the likeliest to be the one an agent has been using. The
            // others are not touched and not merged: merging people is not a
            // decision a webhook gets to take, so they are named in a note
            // instead.
            $customer = Customer::find(array_shift($matchedIds));
            if ($customer) {
                $this->otherMatchedCustomerIds = $matchedIds;
                CustomerMatcher::linkPhoneChannel($customer, $phone);
                return $customer;
            }
        }

        $customer = new Customer();
        // The number and never the profile name (#59), for the reason above.
        $customer->first_name = $phone;
        $customer->last_name  = '';
        $customer->setPhones([
            ['value' => $phone, 'type' => Customer::PHONE_TYPE_MOBILE],
        ]);
        $customer->save();
        $customer->addChannel(WhatsAppAccount::CHANNEL, $phone);

        return $customer;
    }

    /**
     * An identity change, which Meta sends as a message of type `system`
     * (issue #60).
     *
     * Two kinds, and the payload says which: `user_changed_number` when the
     * person changed their phone number and it can still be shared with us,
     * and `user_changed_user_id` when their business-scoped id changed. Both
     * mean the same thing for this module: an identifier it holds is about to
     * stop arriving, and the one replacing it belongs to the same person.
     *
     * Reading it is what keeps #59 from happening in reverse. Without it the
     * next message comes from an identifier we have never seen, so it builds
     * a second customer and a second conversation while the history stays
     * behind on the first.
     */
    protected function processIdentityChange(WhatsAppAccount $account, array $message)
    {
        $system = isset($message['system']) && is_array($message['system']) ? $message['system'] : [];
        $kind   = $this->payloadString($system, 'type');

        if ($kind === 'user_changed_number') {
            $this->moveIdentity(
                $account,
                WhatsAppAccount::CHANNEL,
                'contact_phone',
                $this->normalisedPhone($this->payloadString($message, 'from')),
                $this->normalisedPhone($this->payloadString($system, 'wa_id'))
            );

            // A phone number change regenerates the business-scoped id as
            // well, and Meta reports the previous one on this message type for
            // exactly that reason: "A phone number change also regenerates
            // the BSUID, so the previous values are now reported on both
            // system message types" (business-scoped user IDs reference, copy
            // of 2026-11-22). Without this the module would keep a BSUID that
            // will never arrive again, and the next message would add a second
            // one to the same customer.
            $this->moveBsuid($account, $system);

            return;
        }

        if ($kind === 'user_changed_user_id') {
            $this->moveBsuid($account, $system);

            return;
        }

        // Info and not warning: a system message we do not act on is Meta
        // working as documented, and the log is where an administrator looks
        // for things that went wrong.
        Log::info('[MetaWhatsApp] System message of a kind this module does not act on', [
            'account_id' => $account->id,
            'type'       => $kind,
        ]);
    }

    /**
     * The business-scoped half of an identity change, which both kinds of
     * system message carry.
     *
     * Through the same validation as every other place a business-scoped id
     * arrives from, and that is the whole point of the method existing. The
     * phone side of this message is normalised by `normalisedPhone()`, while
     * these two values used to go into `customer_channel` exactly as they
     * came: a value with a space or a byte outside printable ASCII was
     * written to the row, and then `sanitisedUserId()` refused the very same
     * value on the next message, so the contact arrived with no identifier
     * and the message was dropped. Issue #60 exists to stop that happening,
     * so letting it in through this door undid it.
     *
     * A value this refuses leaves `moveIdentity()` with nothing to move,
     * which it reports and returns from. That is the right outcome: the rows
     * this module writes only ever hold ids that passed here, so an id that
     * does not can match none of them.
     */
    protected function moveBsuid(WhatsAppAccount $account, array $system)
    {
        $this->moveIdentity(
            $account,
            WhatsAppAccount::CHANNEL_BSUID,
            'contact_user_id',
            $this->sanitisedUserId($account, $this->payloadString($system, 'previous_user_id'), 'system.previous_user_id'),
            $this->sanitisedUserId($account, $this->payloadString($system, 'user_id'), 'system.user_id')
        );
    }

    /**
     * Moves one identifier of one channel onto its replacement.
     *
     * The channel row is the only place that decides who an incoming message
     * belongs to, so that moves first. The module's own rows move with it,
     * for a reason that is not bookkeeping: the recipient of a reply is the
     * conversation's last inbound number, so leaving them behind would turn
     * every existing conversation into a trap where an agent writes to a
     * number its owner has given up.
     */
    protected function moveIdentity(WhatsAppAccount $account, int $channel, string $column, ?string $old, ?string $new)
    {
        if (!$old || !$new || $old === $new) {
            Log::info('[MetaWhatsApp] Identity change with nothing usable to move', [
                'account_id' => $account->id,
                'channel'    => $channel,
            ]);

            return;
        }

        // customer_channel.channel_id is varchar(64) in the core and Meta's
        // business-scoped ids run longer, which this file already guards for
        // twice. Without the guard, strict MySQL fails the job and a lenient
        // one truncates in silence, leaving a row that no incoming message
        // will ever match.
        //
        // Below the lookup, although it needs nothing from it, so that the
        // audit record can name the person this leaves pointing at an
        // identifier that will never arrive again. Somebody has to repair
        // that by hand, and until they know which contact it is they cannot.
        $oldRow = CustomerChannel::where('channel', $channel)->where('channel_id', $old)->first();

        if (!$oldRow) {
            // No audit record either: an announcement about somebody who is
            // not a contact of ours names nobody and changed nothing.
            Log::info('[MetaWhatsApp] Identity change for a contact this module does not know', [
                'account_id' => $account->id,
                'channel'    => $channel,
            ]);

            return;
        }

        if (strlen($new) > 64) {
            Log::warning('[MetaWhatsApp] Identity change not applied: the new identifier is longer than customer_channel allows', [
                'account_id'  => $account->id,
                'channel'     => $channel,
                'customer_id' => $oldRow->customer_id,
                'length'      => strlen($new),
            ]);

            IdentityChangeAudit::record(
                $account,
                $channel,
                (int) $oldRow->customer_id,
                IdentityChangeAudit::OUTCOME_TOO_LONG
            );

            return;
        }

        $existing = CustomerChannel::where('channel', $channel)->where('channel_id', $new)->first();

        if ($existing && (int) $existing->customer_id !== (int) $oldRow->customer_id) {
            // Two people are never merged by a webhook. Both records are left
            // exactly as they are, including the old link, because guessing
            // which of them the person is would be worse than doing nothing.
            Log::warning('[MetaWhatsApp] Identity change refused: the new identifier already belongs to another customer', [
                'account_id'  => $account->id,
                'channel'     => $channel,
                'customer_id' => $oldRow->customer_id,
                'holder_id'   => $existing->customer_id,
            ]);

            // The one refusal that needs a person and not a log file: only a
            // manual merge resolves two records for the same human being.
            IdentityChangeAudit::record(
                $account,
                $channel,
                (int) $oldRow->customer_id,
                IdentityChangeAudit::OUTCOME_ANOTHER_CUSTOMER
            );

            return;
        }

        $customerId = (int) $oldRow->customer_id;

        // The conversations that actually held the old identifier, read before
        // the rows move, because afterwards there is no way to tell them from
        // the person's other numbers. One customer record can hold two
        // WhatsApp numbers since issue #59, each with its own conversation,
        // and a note about this change belongs in the one that moved.
        $conversationIds = WhatsAppMessage::where($column, $old)
            ->whereNotNull('conversation_id')
            ->distinct()
            ->pluck('conversation_id')
            ->all();

        // One transaction, because a half-applied move is worse than none and
        // is not recoverable by a retry: the channel row would already point
        // at the new identifier, so the retry would find nothing to move and
        // say so as if all were well, while every existing conversation still
        // answered to the identifier its owner had given up.
        DB::transaction(function () use ($oldRow, $existing, $channel, $column, $old, $new, $customerId, $conversationIds, $account) {
            if ($existing) {
                // The same person already held both, which #59 made possible.
                // The identifier Meta says is gone goes with it.
                $oldRow->delete();
            } else {
                $oldRow->channel_id = $new;
                $oldRow->save();
            }

            // Not scoped to this account: customer_channel is instance-wide,
            // so after the move every mailbox resolves the new identifier to
            // this person. Leaving another WhatsApp channel's rows on the old
            // one would keep that mailbox replying to the abandoned number.
            WhatsAppMessage::where($column, $old)->update([$column => $new]);

            if ($channel !== WhatsAppAccount::CHANNEL) {
                // No note for a business-scoped id: an agent never sees one
                // and never acts on one, so a note would only be noise in a
                // thread they are reading for the customer's words.
                return;
            }

            $customer = Customer::find($customerId);
            if ($customer) {
                $this->movePhoneOnRecord($customer, $old, $new);
            }

            $this->noteNumberChange($conversationIds);
        });

        // After the transaction and not inside it: this is a record of
        // something that did happen, and a rollback would leave nothing to
        // audit. The note is the one that has to live or die with the move.
        IdentityChangeAudit::record($account, $channel, $customerId, IdentityChangeAudit::OUTCOME_APPLIED);

        Log::info('[MetaWhatsApp] Identity change applied', [
            'account_id'  => $account->id,
            'channel'     => $channel,
            'customer_id' => $customerId,
        ]);
    }

    /**
     * E.164 with the '+', the same shape the rest of the module stores, and
     * null for anything that is not a phone number we could act on.
     */
    protected function normalisedPhone(?string $raw): ?string
    {
        // Only separators come out, never letters. Stripping every non-digit
        // turned an identifier that is not a phone number into one: a
        // business-scoped id can arrive in `from`, this module documents that
        // as allowed, and 'US.34611222333' became '+34611222333', which then
        // matched the contact entry of whoever owns that number and filed the
        // message in their conversation (fifth review round, and the rule had
        // been copied rather than written, so deleting its copy did not reach
        // it).
        $trimmed = preg_replace('/[\s\-()+]/', '', (string) $raw);

        return preg_match('/^\d{6,15}$/', $trimmed) ? '+' . $trimmed : null;
    }

    /**
     * The customer record's own phone list follows the number that changed.
     *
     * This used to only fill a record that had no phone at all, under the
     * rule that a webhook does not edit an administrator's data. The rule
     * was right and the conclusion was wrong: the number left behind is one
     * its owner has given up, so an agent reads a record and calls nobody,
     * and when the operator reassigns that number `Customer::byPhone()` goes
     * on finding this record, filing a stranger's first message under this
     * person's name. The reasoning, and the line that keeps every other
     * number on the record untouched, are in CustomerMatcher::movePhone().
     */
    protected function movePhoneOnRecord(Customer $customer, string $old, string $new)
    {
        if (CustomerMatcher::movePhone($customer, $old, $new)) {
            $customer->save();
        }
    }

    /**
     * The agent has to learn that the number changed, and the conversation is
     * where they are. Neither number goes in the body: a note is text that
     * the right-of-erasure screen cannot reach, and both numbers already live
     * where it can.
     *
     * Takes the conversations that carried the old number, read before the
     * rows moved. Picking the customer's newest chat conversation instead
     * would write it into the thread of their other number, where it is not
     * true, and leave the one that moved without a word.
     */
    protected function noteNumberChange(array $conversationIds)
    {
        if (!$conversationIds) {
            return;
        }

        $conversations = Conversation::whereIn('id', $conversationIds)
            ->where('type', Conversation::TYPE_CHAT)
            ->where('status', '!=', Conversation::STATUS_SPAM)
            ->where('state', '!=', Conversation::STATE_DELETED)
            ->where(function ($query) {
                $query->where('channel', WhatsAppAccount::CHANNEL)
                    ->orWhereNull('channel');
            })
            ->get();

        // The newest one per mailbox: that is where the agent is, and a
        // customer who writes to two of this installation's WhatsApp channels
        // has an agent in each.
        foreach ($conversations->groupBy('mailbox_id') as $perMailbox) {
            $conversation = $perMailbox->sortByDesc('created_at')->first();

            $note                     = new Thread();
            $note->conversation_id    = $conversation->id;
            $note->user_id            = $conversation->user_id;
            $note->created_by_user_id = NoteAuthor::forNote(null);
            $note->type               = Thread::TYPE_NOTE;
            $note->status             = $conversation->status;
            $note->state              = Thread::STATE_PUBLISHED;
            $note->body               = '[WhatsApp] ' . __('metawhatsapp::metawhatsapp.contact_number_changed');
            $note->source_via         = Thread::PERSON_USER;
            $note->source_type        = Thread::SOURCE_TYPE_WEB;
            $note->customer_id        = $conversation->customer_id;
            $note->save();
        }
    }

    /**
     * The time Meta says the message happened, which is the clock the
     * 24-hour window runs on (T10). Null when the payload carries no usable
     * value, and the row then falls back to created_at, which is the best
     * estimate there is: Meta delivers in near real time.
     *
     * Both ends are bounded, and both bounds cost money in opposite
     * directions.
     *
     * A time ahead of ours would hold the window open for as long as the
     * payload claimed, which is the belief this fix exists to correct. A
     * minute of margin, for clock skew between Meta and here.
     *
     * A time far behind ours reads as a window that closed long ago, so the
     * module offers a paid template while Meta's own 24 hours are running.
     * That is what a garbage value did: the previous reading was a bare
     * `(int)` cast, so a `"1"`, a `true` or anything else that casts to a
     * small number stored 1970 and shut the window on a message that had
     * just arrived. Seven days is the floor because that is how long Meta
     * retries a delivery our server refused, "with decreasing frequency
     * until the request succeeds, for up to 7 days" (`webhooks_overview`,
     * corpus of 2026-11-22), so no genuine delivery can be older than that
     * and still be arriving.
     *
     * The value is read as a string first, by the same reader as every other
     * scalar field: a `(int)` cast of an array is 1 with a warning, and 1 is
     * precisely the value that did the damage.
     */
    protected function metaSentAt(array $message)
    {
        $at = $this->payloadTimestamp($message);

        if ($at === null) {
            return null;
        }

        if ($at->lt(now()->subDays(7))) {
            Log::warning('[MetaWhatsApp] Inbound message timestamp is older than any delivery can be, ignored for the window clock', [
                'account_id' => $this->accountId,
            ]);

            return null;
        }

        return $at;
    }

    /**
     * A `timestamp` field of a Meta payload, read as a time, or null when it
     * is not one.
     *
     * The shape only, so the two callers can each keep their own floor: a
     * message's time runs the 24-hour window and a status's time is a moment
     * shown to an agent, and the ranges a value can sensibly have are not
     * the same. What is the same is the reading, and both used a bare
     * `(int)` cast of whatever was in the key, which answers 1 for an array
     * and for `true`.
     *
     * A time ahead of ours is refused here for both: on the window clock it
     * would hold the window open for as long as the payload claimed, and on
     * a status it would tell an agent a message was read in two hours' time.
     * A minute of margin, for clock skew between Meta and here.
     */
    protected function payloadTimestamp(array $source)
    {
        $raw = $this->payloadString($source, 'timestamp');

        if ($raw === null || !preg_match('/^\d{1,11}$/', $raw)) {
            return null;
        }

        $at = \Carbon\Carbon::createFromTimestamp((int) $raw);

        if ($at->gt(now()->addMinute())) {
            Log::warning('[MetaWhatsApp] A timestamp from Meta is in the future, ignored', [
                'account_id' => $this->accountId,
            ]);

            return null;
        }

        return $at;
    }

    /**
     * Whether this conversation already carries inbound messages from a
     * different WhatsApp number. A conversation with none, which is what a
     * contact known only by their business-scoped id has, is not excluded:
     * that history belongs to the same person and splitting it would be the
     * worse answer.
     */
    protected function conversationHoldsAnotherNumber(Conversation $conversation, string $phone): bool
    {
        return WhatsAppMessage::where('conversation_id', $conversation->id)
            ->whereNotNull('contact_phone')
            ->where('contact_phone', '!=', $phone)
            ->exists();
    }

    /**
     * The note that says the number was ambiguous. It names records and not
     * the number itself: the body of a note is readable from more places
     * than the customer page, and the identifier is already everywhere it
     * needs to be.
     */
    protected function noteOtherMatchedCustomers(Conversation $conversation)
    {
        if (!$this->otherMatchedCustomerIds) {
            return;
        }

        $ids = implode(', ', array_map(function ($id) {
            return '#' . $id;
        }, $this->otherMatchedCustomerIds));

        $note                       = new Thread();
        $note->conversation_id      = $conversation->id;
        $note->user_id              = $conversation->user_id;
        $note->created_by_user_id   = NoteAuthor::forNote(null);
        $note->type                 = Thread::TYPE_NOTE;
        $note->status               = $conversation->status;
        $note->state                = Thread::STATE_PUBLISHED;
        $note->body                 = '[WhatsApp] ' . __('metawhatsapp::metawhatsapp.customer_matched_several', ['ids' => $ids]);
        $note->source_via           = Thread::PERSON_USER;
        $note->source_type          = Thread::SOURCE_TYPE_WEB;
        $note->customer_id          = $conversation->customer_id;
        $note->save();

        $this->otherMatchedCustomerIds = [];
    }

    protected function firePostEvents($account, $conversation, $thread, $customer, bool $isNew)
    {
        // Notificacions, comptadors i realtime del core, sense codi propi.
        $account->mailbox->updateFoldersCounters();

        if ($isNew) {
            event(new CustomerCreatedConversation($conversation, $thread));
            \Eventy::action('conversation.created_by_customer', $conversation, $thread, $customer);
        } else {
            event(new CustomerReplied($conversation, $thread));
            \Eventy::action('conversation.customer_replied', $conversation, $thread, $customer);
        }

        // No conversation.status_changed here, and the reason is the core's
        // own shape rather than taste (issue #53). The core fires that event
        // for an agent moving a status (ConversationsController.php:1107,
        // FetchEmails.php:1763) and never for anything a customer did: a
        // customer's reply sets the status through the
        // `conversation.status_changing` filter and announces itself with
        // `conversation.customer_replied` (FetchEmails.php:1568 and :1665).
        //
        // The module used to fire it anyway, which meant inventing a user,
        // because the signature carries one that modules dereference without
        // checking for null (issue #7, where that broke Workflows). Inventing
        // one is what the reporter opened #53 about: the reopening showed up
        // attributed to an arbitrary agent.
        //
        // What the module still owes the core on this path is the other half,
        // the `conversation.*` filters it fires as actions but does not apply
        // as filters. That is issue #56.
    }

    protected function processStatus(WhatsAppAccount $account, array $status)
    {
        $wamid     = $this->payloadString($status, 'id');
        $newStatus = $this->payloadString($status, 'status');
        if (!$wamid || !$newStatus) {
            return;
        }

        $record = WhatsAppMessage::where('wamid', $wamid)
            ->where('account_id', $account->id)
            ->first();
        if (!$record) {
            // Missatge anterior al mòdul o d'un altre sistema: best-effort.
            Log::debug('[MetaWhatsApp] Status for unknown wamid', ['account_id' => $account->id]);
            return;
        }

        // 'played' arrives for voice notes once the customer actually plays
        // them (Meta changelog check, folded into the #10/#41/#42 batch). It
        // maps to the same STATUS_READ and gets the same downstream
        // treatment as 'read' below: a played voice note is read in every
        // sense that matters to an agent, and there is no separate "played"
        // state anywhere else in this module (no migration needed).
        $map = [
            'sent'      => WhatsAppMessage::STATUS_SENT,
            'delivered' => WhatsAppMessage::STATUS_DELIVERED,
            'read'      => WhatsAppMessage::STATUS_READ,
            'played'    => WhatsAppMessage::STATUS_READ,
            'failed'    => WhatsAppMessage::STATUS_FAILED,
        ];
        if (!isset($map[$newStatus])) {
            return;
        }

        $mapped = $map[$newStatus];

        // T11. Nothing arriving after a failure is believed, not even a
        // timestamp: the column a late status writes is the one
        // ServiceUsage::sentThisMonth() bills by and the health panel reads.
        //
        // This does not make a contradictory row impossible, and the comment
        // here used to claim it did. A delivery followed by a failure, in that
        // order, is believed in both halves and leaves a row that says failed
        // with a delivery time on it, because the delivery really did happen
        // and clearing its time would be inventing a different story.
        if (InboundStatusRank::isTerminal($record->status) && $mapped !== WhatsAppMessage::STATUS_FAILED) {
            Log::debug('[MetaWhatsApp] Status arriving after a failure ignored', [
                'account_id' => $account->id,
                'status'     => $newStatus,
            ]);

            return;
        }

        // Out of order is ordinary: Meta promises no order, redelivers
        // webhooks, and the queue can pick two deliveries up either way
        // round. The timestamps below were already guarded against walking
        // backwards; the status was not.
        if (InboundStatusRank::outranks($mapped, $record->status)) {
            $record->status = $mapped;
        }

        $countsAsRead = in_array($newStatus, ['read', 'played'], true);

        if (in_array($newStatus, ['delivered', 'read', 'played'], true)) {
            // Through the shared reader, and with a floor of its own: a
            // status is about a message that already existed, so it cannot
            // predate the row, and a value that casts to a small number was
            // writing 1970 into a column an agent reads as "delivered at".
            // A minute of margin because Meta's `sent` can carry the moment
            // it accepted the message, fractionally before we wrote the row.
            $statusAt = $this->payloadTimestamp($status);

            if ($statusAt !== null && $record->created_at
                && $statusAt->lt($record->created_at->copy()->subMinute())
            ) {
                Log::warning('[MetaWhatsApp] A delivery status is dated before the message it is about, ignored', [
                    'account_id' => $account->id,
                    'status'     => $newStatus,
                ]);

                $statusAt = null;
            }

            $statusAt = $statusAt ?: now();

            // Meta no sempre envia 'delivered' abans de 'read'/'played'; si
            // arriba sense que ho haguem vist, deduïm delivered_at del
            // mateix esdeveniment en lloc de deixar-lo buit.
            if (!$record->delivered_at) {
                $record->delivered_at = $statusAt;
            }
            if ($countsAsRead && !$record->read_at) {
                $record->read_at = $statusAt;
            }
        }

        $record->save();

        // Indicador de lectura natiu (issue #3): el 'read'/'played' de Meta
        // marca el thread outbound com a obert, igual que el píxel de
        // tracking dels emails (OpenController). Només la primera lectura.
        if ($countsAsRead
            && $record->direction === WhatsAppMessage::DIRECTION_OUTBOUND
            && $record->thread_id
        ) {
            $thread = Thread::find($record->thread_id);
            if ($thread && !$thread->opened_at) {
                $thread->opened_at = now();
                $thread->save();
            }
        }

        if ($newStatus === 'failed') {
            $this->handleAsyncDeliveryFailure($account, $record, $status);
        }
    }

    /**
     * Whole asynchronous failure sequence, in one place so the order is
     * not an implicit dependency between two call sites (issue #25).
     *
     * Both guards run before anything is written. A 'failed' status on an
     * INBOUND row is not ours to report: Meta's delivery statuses describe
     * our own sends. Logging those would also never stop, because nothing
     * would mark them as seen and Meta redelivers webhooks.
     *
     * An asynchronous 190 is deliberately NOT deactivating the account: a
     * token rejection attached to a delivery status is weaker evidence
     * than one returned to our own call, and deactivating is disruptive.
     */
    protected function handleAsyncDeliveryFailure(WhatsAppAccount $account, WhatsAppMessage $record, array $status): void
    {
        if ($record->direction !== WhatsAppMessage::DIRECTION_OUTBOUND || !$record->conversation_id) {
            return;
        }

        // Idempotència per fila de missatge (issue #19), que aquí cobreix
        // el registre i la nota alhora: Meta pot lliurar el mateix error
        // pels dos canals i reenvia els webhooks.
        if ($record->failure_noted_at) {
            return;
        }

        $error = $status['errors'][0] ?? [];

        DeliveryFailure::record(
            $account,
            DeliveryFailure::SOURCE_ASYNC,
            DeliveryFailure::SUBJECT_MESSAGE,
            (string) ($error['code'] ?? ''),
            DeliveryFailure::errorTextFrom($error),
            [
                'conversation_id' => $record->conversation_id,
                'thread_id'       => $record->thread_id,
                'wamid'           => $record->wamid,
            ],
            $record
        );

        $this->recordAsyncDeliveryFailureNote($account, $record, $status);
    }

    protected function recordAsyncDeliveryFailureNote(WhatsAppAccount $account, WhatsAppMessage $record, array $status): void
    {
        $conversation = Conversation::find($record->conversation_id);
        if (!$conversation) {
            return;
        }

        $errorCode  = (string) ($status['errors'][0]['code'] ?? '');
        $errorTitle = trim((string) ($status['errors'][0]['title'] ?? ''));
        $label      = $errorTitle !== '' ? ($errorCode . ' - ' . $errorTitle) : $errorCode;

        // Extracte del missatge que no s'ha lliurat, en lloc del wamid cru
        // (issue #19).
        $excerpt = $this->excerptForOutboundRecord($record);

        // When no text can be derived (media with no caption, a thread
        // already deleted) this used to fall back to the raw wamid. It
        // cannot: Meta's message id is base64 with the contact's phone
        // number inside it, so the note left a readable copy of that
        // number in `threads.body`, which no erasure reaches and which
        // every agent can see (T02). It told the agent nothing anyway.
        if ($excerpt !== null) {
            $identifier = '"' . $excerpt . '"';
        } elseif ($record->attachment_id) {
            $identifier = __('metawhatsapp::metawhatsapp.failed_note_attachment');
        } else {
            $identifier = __('metawhatsapp::metawhatsapp.failed_note_message');
        }

        // Issue #43. L'avatar i la disposició de la nota els decideix el core
        // amb Thread::getPerson(), que per a notes llegeix
        // created_by_user_cached; sense aquest camp la nota sortia sense cara.
        // L'agent de qui parla la nota és qui va escriure el missatge que no
        // s'ha lliurat, i hi arribem pel thread original. Es deixa nul quan no
        // n'hi ha cap de resoluble (thread esborrat, usuari esborrat,
        // plantilla enviada pel sistema), cas en què es veu com fins ara.
        //
        // Naming that agent is what the administrator can now override, for
        // the reason in NoteAuthor: the core renders the author as "you" to
        // whoever is reading, so the agent saw a note about their own failed
        // message as if they had written it. With no chosen user, or one
        // since deleted, this is exactly the attribution above.
        //
        // user_id is the conversation's assignee mirrored onto the thread,
        // which is what the core does in Conversation::createUserThread, and
        // null when nobody is assigned. It used to hold whichever user
        // happened to have access to the mailbox, so every delivery-failure
        // note printed that person's name on the right no matter whose
        // message had failed. Reported in #43.
        $failedThread = $record->thread_id ? Thread::find($record->thread_id) : null;
        $agentId      = $failedThread
            ? ($failedThread->created_by_user_id ?: $failedThread->user_id)
            : null;

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->user_id         = $conversation->user_id;
        $thread->created_by_user_id = NoteAuthor::forNote($agentId ?: null);
        $thread->type            = Thread::TYPE_NOTE;
        $thread->status          = $conversation->status;
        $thread->state           = Thread::STATE_PUBLISHED;
        $thread->body            = '[WhatsApp delivery failed] ' . $identifier . ' '
            . __('metawhatsapp::metawhatsapp.async_delivery_failed', ['error' => $label ?: '-']);
        $thread->source_via      = Thread::PERSON_USER;
        $thread->source_type     = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id     = $conversation->customer_id;
        $thread->save();

        $record->failure_noted_at = now();
        $record->save();

        ConversationReopen::afterFailure($conversation);
    }

    /**
     * Extracte del cos del thread associat a un missatge outbound nostre.
     */
    protected function excerptForOutboundRecord(WhatsAppMessage $record): ?string
    {
        if (!$record->thread_id) {
            return null;
        }

        $thread = Thread::find($record->thread_id);

        return $thread ? $this->excerptFromBody($thread->body) : null;
    }

    public function failed(\Throwable $e)
    {
        Log::error('[MetaWhatsApp] ProcessInboundWebhook failed permanently', [
            'account_id' => $this->accountId,
            'error'      => $e->getMessage(),
        ]);
    }

    /**
     * Descarrega l'adjunt d'un missatge multimèdia inbound. Si falla, es
     * retorna ok=false: processMessage() crea igualment el thread amb un
     * avís, mai perd el missatge silenciosament (mateix principi que el fix
     * de la issue #7).
     */
    protected function downloadInboundMedia(WhatsAppAccount $account, string $type, array $mediaObject, string $wamid): array
    {
        $mediaId = $mediaObject['id'] ?? null;
        $caption = trim($mediaObject['caption'] ?? '');

        if (!$mediaId) {
            Log::error('[MetaWhatsApp] Media message without media id, attachment not downloaded', [
                'account_id' => $account->id,
                'wamid'      => $wamid,
                'type'       => $type,
            ]);
            return ['ok' => false, 'caption' => $caption, 'type' => $type];
        }

        $result = $this->apiClient($account)->downloadMedia($mediaId);
        if (!$result['ok']) {
            Log::error('[MetaWhatsApp] Failed to download inbound media, attachment not stored', [
                'account_id' => $account->id,
                'wamid'      => $wamid,
                'type'       => $type,
                'error'      => $result['error_message'],
            ]);
            return ['ok' => false, 'caption' => $caption, 'type' => $type];
        }

        $filename = $mediaObject['filename'] ?? $this->syntheticFilename($type, $result['mime_type']);

        return [
            'ok'        => true,
            'bytes'     => $result['bytes'],
            'mime_type' => $result['mime_type'],
            'filename'  => $filename,
            'caption'   => $caption,
            'type'      => $type,
        ];
    }

    /**
     * The name invented for a file that arrives without one.
     *
     * A media type may carry parameters, and a voice note always does:
     * `audio/ogg; codecs=opus`. Everything after the slash used to be kept
     * and then stripped of punctuation, so the agent was handed
     * `audio_68f3c1.oggcodecsopus`, which no player opens (T20).
     *
     * The length cap is the same mistake in another disguise: a structured
     * subtype such as `vnd.openxmlformats-officedocument.wordprocessingml
     * .document` ran together into a fifty-three character extension. No
     * media type table is kept here on purpose. Guessing an extension we do
     * not know is worse than admitting we do not, because the file is served
     * by its stored media type either way, and a table is one more thing
     * that silently goes out of date.
     */
    protected function syntheticFilename(string $type, string $mimeType): string
    {
        $subtype = explode('/', $mimeType)[1] ?? '';
        $subtype = trim(explode(';', $subtype)[0]);
        $ext     = preg_replace('/[^a-z0-9]/i', '', $subtype);

        if ($ext === '' || strlen($ext) > 10) {
            $ext = 'bin';
        }

        return $type . '_' . uniqid() . '.' . $ext;
    }

    protected function attachmentCategory(string $waType): string
    {
        if ($waType === 'document') {
            return 'application';
        }
        // Els stickers de WhatsApp són WEBP (estàtics o animats): es
        // reutilitza la mateixa categoria/pipeline que les imatges.
        if ($waType === 'sticker') {
            return 'image';
        }

        return $waType;
    }

    protected function apiClient(WhatsAppAccount $account): WhatsAppApiClient
    {
        return new WhatsAppApiClient($account);
    }
}
