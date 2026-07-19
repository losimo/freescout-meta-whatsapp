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
        $from  = $message['from'] ?? null;
        if (!$wamid || !$from) {
            Log::warning('[MetaWhatsApp] Message without wamid or sender, discarded', [
                'account_id' => $account->id,
            ]);
            return;
        }

        // MVP: només text pla. Meta no reenviarà el payload després del nostre 200.
        if (($message['type'] ?? '') !== 'text') {
            Log::error('[MetaWhatsApp] Unsupported message type, discarded', [
                'account_id' => $account->id,
                'type'       => $message['type'] ?? '(unknown)',
                'from'       => $from,
            ]);
            return;
        }

        $text = trim($message['text']['body'] ?? '');
        if ($text === '') {
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
            DB::transaction(function () use ($account, $wamid, $phone, $userId, $profileName, $text) {
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
                $body  = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));

                if ($isNew) {
                    $conversation = new Conversation();
                    $conversation->type                   = Conversation::TYPE_CHAT;
                    $conversation->state                  = Conversation::STATE_PUBLISHED;
                    $conversation->subject                = __('metawhatsapp::metawhatsapp.conversation_subject', ['phone' => $phone ?: ($profileName ?: $userId)]);
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
    }

    public function failed(\Throwable $e)
    {
        Log::error('[MetaWhatsApp] ProcessInboundWebhook failed permanently', [
            'account_id' => $this->accountId,
            'error'      => $e->getMessage(),
        ]);
    }
}
