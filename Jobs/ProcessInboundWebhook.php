<?php

namespace Modules\MetaWhatsApp\Jobs;

use App\Conversation;
use App\Customer;
use App\CustomerChannel;
use App\Events\CustomerCreatedConversation;
use App\Events\CustomerReplied;
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
use Modules\MetaWhatsApp\Support\Logger as MetaWhatsAppLogger;
use Modules\MetaWhatsApp\Support\WhatsAppTextFormatter;

class ProcessInboundWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // El backoff entre reintents el gestiona el worker (Laravel 5.8).
    public $tries = 3;

    /** @var int */
    protected $accountId;

    /** @var array */
    protected $payload;

    public function __construct(int $accountId, array $payload)
    {
        $this->accountId = $accountId;
        $this->payload   = $payload;
    }

    public function handle()
    {
        $account = WhatsAppAccount::with('mailbox')->find($this->accountId);
        if (!$account || !$account->is_active || !$account->mailbox) {
            Log::warning('[MetaWhatsApp] ProcessInboundWebhook: account missing, inactive, or without mailbox', [
                'account_id' => $this->accountId,
            ]);
            return;
        }

        Log::info('[MetaWhatsApp] Processing inbound webhook', ['account_id' => $account->id]);
        MetaWhatsAppLogger::debugData('[MetaWhatsApp] Inbound webhook payload', ['account_id' => $account->id, 'payload' => $this->payload]);

        foreach ($this->payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                // La signatura del POST es valida amb el secret del compte
                // resolt pel PRIMER phone_number_id. Un change amb metadata
                // d'un altre número no s'ha d'atribuir mai a aquest compte
                // (evita misatribució entre canals i injecció creuada).
                $changePhoneId = $value['metadata']['phone_number_id'] ?? null;
                if ($changePhoneId !== $account->phone_number_id) {
                    Log::warning('[MetaWhatsApp] Change with phone_number_id not matching the account, discarded', [
                        'account_id' => $account->id,
                    ]);
                    continue;
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $this->processMessage($account, $message, $value['contacts'] ?? []);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->processStatus($account, $status);
                }
            }
        }
    }

    protected function processMessage(WhatsAppAccount $account, array $message, array $contacts = [])
    {
        $wamid = $message['id'] ?? null;
        if (!$wamid) {
            return;
        }

        $from  = $message['from'] ?? null;
        $type  = $message['type'] ?? null;
        $mediaTypes = ['image', 'video', 'audio', 'document', 'sticker'];

        if (!in_array($type, [...$mediaTypes, 'text', 'button', 'location', 'reaction', 'contacts'], true)) {
            Log::error('[MetaWhatsApp] Unsupported message type, discarded', [
                'account_id' => $account->id,
                'from'       => $from,
                'type'       => $type,
            ]);
            return;
        }

        // Missatge multimèdia: extreu l'objecte del tipus corresponent.
        $media = null;
        if (in_array($type, $mediaTypes, true)) {
            $media = $message[$type] ?? null;
            if (!$media) {
                return;
            }
            // Descarrega l'adjunt. Si falla, crea igualment el thread amb avís.
            $media = $this->downloadInboundMedia($account, $type, $media, $wamid, $from);
        }

        // Missatge de text o multimèdia amb caption.
        if ($type === 'button') {
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
            $text = __('metawhatsapp::metawhatsapp.media_attachment_unavailable');
        } elseif ($media && $media['ok']) {
            $text = __('metawhatsapp::metawhatsapp.media_preview_no_caption', ['type' => $type]);
        }

        if ($text === '' && !$media) {
            return;
        }

        // BSUID (Business-Scoped User ID): amb els usernames de WhatsApp,
        // contacts[].user_id és l'identificador estable i 'from' pot deixar
        // de ser un telèfon usable.
        $contact     = $this->selectContact($contacts, $from);
        $userId      = $this->extractContactUserId($account, $contact);
        $profileName = $this->extractProfileName($contact);

        // Telèfon usable: E.164 sense '+' (format de Meta). Valors estranys
        // reventarien contact_phone (VARCHAR 20) i embrutarien customer_channel.
        // Un 'from' idèntic al user_id és el BSUID, encara que sigui numèric.
        $phone      = null;
        $fromDigits = ltrim($from, '+');
        if ($fromDigits !== $userId && preg_match('/^\d{6,15}$/', $fromDigits)) {
            // Normalització E.164 amb '+': coherent entre contact_phone,
            // customer_channel i l'outbound.
            $phone = '+' . $fromDigits;
        }

        if (!$phone && !$userId) {
            Log::warning('[MetaWhatsApp] Sender without valid phone or user_id, discarded', [
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
            if (!WhatsAppMessage::where('wamid', $wamid)->exists()) {
                try {
                    WhatsAppMessage::create([
                        'wamid'           => $wamid,
                        'account_id'      => $account->id,
                        'contact_user_id' => $userId,
                        'direction'       => WhatsAppMessage::DIRECTION_INBOUND,
                        'status'          => WhatsAppMessage::STATUS_RECEIVED,
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

        // Idempotència: el mateix wamid ja processat és un no-op.
        if (WhatsAppMessage::where('wamid', $wamid)->exists()) {
            return;
        }

        try {
            DB::transaction(function () use ($account, $wamid, $phone, $userId, $profileName, $text, $media) {
                $customer = $this->resolveCustomer($account, $phone, $userId, $profileName);

                // Patró de xat del core (#4902): es reutilitza la darrera conversa
                // encara que estigui tancada (es reobre), tret que l'opció de
                // bústia 'chat_start_new' digui de començar-ne una de nova.
                $conversation = Conversation::where('mailbox_id', $account->mailbox_id)
                    ->where('customer_id', $customer->id)
                    ->where('status', '!=', Conversation::STATUS_SPAM)
                    ->orderBy('created_at', 'desc')
                    ->first();

                if ($conversation && $conversation->chatShouldStartNew($account->mailbox)) {
                    $conversation = null;
                }

                $isNew = !$conversation;
                $body  = nl2br(WhatsAppTextFormatter::format(
                    htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
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
                    $displayPhone = $phone ?: ($profileName ?: $userId);
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
                $prev_status          = $conversation->status;
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
                ]);

                $this->firePostEvents($account, $conversation, $thread, $customer, $isNew, $prev_status);
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
     * Tria el contacte de contacts[] que correspon al remitent: preferència
     * pel que té wa_id/user_id igual a 'from'; si no, el primer del bloc.
     */
    protected function selectContact(array $contacts, string $from): ?array
    {
        $selected = null;
        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }
            if (($contact['wa_id'] ?? null) === $from || ($contact['user_id'] ?? null) === $from) {
                return $contact;
            }
            $selected = $selected ?? $contact;
        }

        return $selected;
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
        $label   = trim($name . ($name !== '' && $address !== '' ? ' — ' : '') . $address);
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
        if (!is_string($userId) || $userId === '') {
            return null;
        }

        // Sanejament: cap a VARCHAR(100); només ASCII imprimible sense espais.
        if (!preg_match('/^[\x21-\x7E]{1,100}$/', $userId)) {
            Log::warning('[MetaWhatsApp] contacts[].user_id has unexpected format, ignored', [
                'account_id' => $account->id,
            ]);
            return null;
        }

        return $userId;
    }

    /**
     * Nom visible del contacte (contacts[].profile.name), si n'hi ha.
     */
    protected function extractProfileName(?array $contact): ?string
    {
        $name = is_array($contact) ? ($contact['profile']['name'] ?? null) : null;
        if (!is_string($name)) {
            return null;
        }
        $name = trim($name);

        return $name === '' ? null : $name;
    }

    /**
     * Resol el client del missatge. Phone-first: si hi ha telèfon usable, el
     * comportament és exactament l'actual; el BSUID només resol quan no n'hi ha.
     */
    protected function resolveCustomer(WhatsAppAccount $account, ?string $phone, ?string $userId, ?string $profileName): Customer
    {
        if ($phone) {
            $customer = $this->findOrCreateCustomer($phone);
            if ($userId) {
                $this->learnBsuid($account, $customer, $userId);
            }

            return $customer;
        }

        return $this->findOrCreateCustomerByBsuid($userId, $profileName);
    }

    /**
     * Client per BSUID: el troba pel canal o crea un placeholder sense telèfon.
     */
    protected function findOrCreateCustomerByBsuid(string $userId, ?string $profileName): Customer
    {
        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $userId);
        if ($customer) {
            return $customer;
        }

        $customer = new Customer();
        $customer->first_name = mb_substr($profileName ?: $userId, 0, 255);
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

    protected function findOrCreateCustomer(string $phone): Customer
    {
        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, $phone);
        if ($customer) {
            return $customer;
        }

        $customer = new Customer();
        $customer->first_name = $phone;
        $customer->setPhones([
            ['value' => $phone, 'type' => Customer::PHONE_TYPE_MOBILE],
        ]);
        $customer->save();
        $customer->addChannel(WhatsAppAccount::CHANNEL, $phone);

        return $customer;
    }

    protected function firePostEvents($account, $conversation, $thread, $customer, bool $isNew, $prev_status)
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

        if ($prev_status && $prev_status != $conversation->status) {
            $user = $this->resolveSystemUser($account, $conversation);

            if ($user) {
                \Eventy::action('conversation.status_changed', $conversation, $user, false, $prev_status);
            }
        }
    }

    /**
     * conversation.status_changed exigeix sempre un User no nul (el core i
     * mòduls com Workflows hi accedeixen directament amb $user->id i
     * exploten si és null, cf. issue #7). Fem servir l'agent assignat i,
     * si no n'hi ha, el primer usuari amb accés a la bústia.
     */
    protected function resolveSystemUser(WhatsAppAccount $account, $conversation)
    {
        if ($conversation->user_id && $conversation->user) {
            return $conversation->user;
        }

        return $account->mailbox->usersHavingAccess()->first();
    }

    protected function processStatus(WhatsAppAccount $account, array $status)
    {
        $wamid     = $status['id'] ?? null;
        $newStatus = $status['status'] ?? null;
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

        $map = [
            'sent'      => WhatsAppMessage::STATUS_SENT,
            'delivered' => WhatsAppMessage::STATUS_DELIVERED,
            'read'      => WhatsAppMessage::STATUS_READ,
            'failed'    => WhatsAppMessage::STATUS_FAILED,
        ];
        if (!isset($map[$newStatus])) {
            return;
        }

        $record->status = $map[$newStatus];
        if ($newStatus === 'failed') {
            $record->error_code = (string) ($status['errors'][0]['code'] ?? '');
        }

        if (in_array($newStatus, ['delivered', 'read'], true)) {
            $statusAt = isset($status['timestamp'])
                ? \Carbon\Carbon::createFromTimestamp((int) $status['timestamp'])
                : now();

            // Meta no sempre envia 'delivered' abans de 'read'; si arriba
            // 'read' sense que ho haguem vist, deduïm delivered_at del
            // mateix esdeveniment en lloc de deixar-lo buit.
            if (!$record->delivered_at) {
                $record->delivered_at = $statusAt;
            }
            if ($newStatus === 'read' && !$record->read_at) {
                $record->read_at = $statusAt;
            }
        }

        $record->save();

        // Indicador de lectura natiu (issue #3): el 'read' de Meta marca el
        // thread outbound com a obert, igual que el píxel de tracking dels
        // emails (OpenController). Només la primera lectura.
        if ($newStatus === 'read'
            && $record->direction === WhatsAppMessage::DIRECTION_OUTBOUND
            && $record->thread_id
        ) {
            $thread = Thread::find($record->thread_id);
            if ($thread && !$thread->opened_at) {
                $thread->opened_at = now();
                $thread->save();
            }
        }

        // Reconciliació d'esdeveniments outbound (inspirat en kapsowhatsapp):
        // una fallida ASÍNCRONA (el 'sent' inicial va anar bé, Meta rebutja
        // el missatge després) no tenia cap senyal visible per a l'agent —
        // només un canvi de status silenciós a la BD. Nota interna amb el
        // codi d'error, un cop per wamid.
        if ($newStatus === 'failed') {
            $this->recordAsyncDeliveryFailureNote($account, $record, $status);
        }
    }

    protected function recordAsyncDeliveryFailureNote(WhatsAppAccount $account, WhatsAppMessage $record, array $status): void
    {
        // Només té sentit per als missatges que nosaltres hem enviat: el
        // 'failed' de Meta reporta l'entrega d'un enviament outbound, mai
        // res relacionat amb el missatge inbound del client.
        if ($record->direction !== WhatsAppMessage::DIRECTION_OUTBOUND || !$record->conversation_id) {
            return;
        }

        // Idempotència per fila de missatge (issue #19). Abans es deduïa
        // buscant el wamid dins el body dels threads; ara el text visible
        // porta un extracte del missatge, no el wamid, i marcar-ho aquí és
        // a més per missatge, de manera que en una tanda d'enviaments cada
        // wamid fallit conserva la seva nota.
        if ($record->failure_noted_at) {
            return;
        }

        $conversation = Conversation::find($record->conversation_id);
        if (!$conversation) {
            return;
        }

        $errorCode  = (string) ($status['errors'][0]['code'] ?? '');
        $errorTitle = trim((string) ($status['errors'][0]['title'] ?? ''));
        $label      = $errorTitle !== '' ? ($errorCode . ' — ' . $errorTitle) : $errorCode;

        // Extracte del missatge que no s'ha lliurat, en lloc del wamid cru
        // (issue #19). Si no se'n pot treure text (multimèdia sense caption,
        // thread esborrat), es manté el wamid com a identificador.
        $excerpt    = $this->excerptForOutboundRecord($record);
        $identifier = $excerpt !== null ? '"' . $excerpt . '"' : $record->wamid;

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->user_id         = optional($this->resolveSystemUser($account, $conversation))->id;
        $thread->type            = Thread::TYPE_NOTE;
        $thread->status          = $conversation->status;
        $thread->state           = Thread::STATE_PUBLISHED;
        $thread->body            = '[WhatsApp delivery failed] ' . $identifier . ' '
            . __('metawhatsapp::metawhatsapp.async_delivery_failed', ['error' => $label ?: '—']);
        $thread->source_via      = Thread::PERSON_USER;
        $thread->source_type     = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id     = $conversation->customer_id;
        $thread->save();

        $record->failure_noted_at = now();
        $record->save();

        $this->reopenConversationAfterFailure($conversation);
    }

    /**
     * Reobre la conversa en detectar un lliurament fallit (issue #19): la
     * nota arriba de forma asíncrona i sovint l'agent ja ha marxat a una
     * altra conversa, així que sense això la fallida passa desapercebuda.
     *
     * S'usa setStatus() del core, que ja recol·loca la conversa a la carpeta
     * correcta; escriure ->status directament deixaria els comptadors de la
     * barra lateral desquadrats. No es toca l'agent assignat: reactivar-la
     * ja la torna a posar a la vista, i reassignar-la seria decidir per
     * l'equip.
     */
    protected function reopenConversationAfterFailure(Conversation $conversation): void
    {
        // Una conversa marcada com a spam o esborrada no s'ha de ressuscitar
        // per una fallida de lliurament, i si ja és activa no hi ha res a fer.
        if (in_array((int) $conversation->status, [
            Conversation::STATUS_ACTIVE,
            Conversation::STATUS_SPAM,
        ], true)) {
            return;
        }

        if ((int) $conversation->state === Conversation::STATE_DELETED) {
            return;
        }

        $conversation->setStatus(Conversation::STATUS_ACTIVE);
        $conversation->save();
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
    protected function downloadInboundMedia(WhatsAppAccount $account, string $type, array $mediaObject, string $wamid, string $from): array
    {
        $mediaId = $mediaObject['id'] ?? null;
        $caption = trim($mediaObject['caption'] ?? '');

        if (!$mediaId) {
            Log::error('[MetaWhatsApp] Media message without media id, attachment not downloaded', [
                'account_id' => $account->id,
                'wamid'      => $wamid,
                'from'       => $from,
                'type'       => $type,
            ]);
            return ['ok' => false, 'caption' => $caption, 'type' => $type];
        }

        $result = $this->apiClient($account)->downloadMedia($mediaId);
        if (!$result['ok']) {
            Log::error('[MetaWhatsApp] Failed to download inbound media, attachment not stored', [
                'account_id' => $account->id,
                'wamid'      => $wamid,
                'from'       => $from,
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

    protected function syntheticFilename(string $type, string $mimeType): string
    {
        $ext = explode('/', $mimeType)[1] ?? 'bin';
        $ext = preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'bin';

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
