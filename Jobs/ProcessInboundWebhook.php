<?php

namespace Modules\MetaWhatsApp\Jobs;

use App\Conversation;
use App\Customer;
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
            Log::warning('[MetaWhatsApp] ProcessInboundWebhook: compte inexistent, inactiu o sense bústia', [
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
                    Log::warning('[MetaWhatsApp] Change amb phone_number_id que no coincideix amb el compte, descartat', [
                        'account_id' => $account->id,
                    ]);
                    continue;
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $this->processMessage($account, $message);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->processStatus($account, $status);
                }
            }
        }
    }

    protected function processMessage(WhatsAppAccount $account, array $message)
    {
        $wamid = $message['id'] ?? null;
        $from  = $message['from'] ?? null;
        if (!$wamid || !$from) {
            Log::warning('[MetaWhatsApp] Missatge sense wamid o remitent, descartat', [
                'account_id' => $account->id,
            ]);
            return;
        }

        // MVP: només text pla. Meta no reenviarà el payload després del nostre 200.
        if (($message['type'] ?? '') !== 'text') {
            Log::info('[MetaWhatsApp] Tipus de missatge no suportat, descartat', [
                'account_id' => $account->id,
                'type'       => $message['type'] ?? '(desconegut)',
            ]);
            return;
        }

        $text = trim($message['text']['body'] ?? '');
        if ($text === '') {
            return;
        }

        // El remitent ha de ser un número E.164 sense '+' (format de Meta).
        // Valors estranys reventarien contact_phone (VARCHAR 20) i embrutarien
        // customer_channel.
        if (!preg_match('/^\d{6,15}$/', ltrim($from, '+'))) {
            Log::warning('[MetaWhatsApp] Remitent amb format invàlid, descartat', [
                'account_id' => $account->id,
            ]);
            return;
        }

        // Normalització E.164 amb '+': coherent entre contact_phone,
        // customer_channel i l'outbound de la Fase 3.
        $phone = '+' . ltrim($from, '+');

        // Idempotència: el mateix wamid ja processat és un no-op.
        if (WhatsAppMessage::where('wamid', $wamid)->exists()) {
            return;
        }

        try {
            DB::transaction(function () use ($account, $wamid, $phone, $text) {
                $customer = $this->findOrCreateCustomer($phone);

                $conversation = Conversation::where('mailbox_id', $account->mailbox_id)
                    ->where('customer_id', $customer->id)
                    ->whereNotIn('status', [Conversation::STATUS_CLOSED, Conversation::STATUS_SPAM])
                    ->orderBy('created_at', 'desc')
                    ->first();

                $isNew = !$conversation;
                $body  = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));

                if ($isNew) {
                    $conversation = new Conversation();
                    $conversation->type                   = Conversation::TYPE_CHAT;
                    $conversation->state                  = Conversation::STATE_PUBLISHED;
                    $conversation->subject                = __('metawhatsapp::metawhatsapp.conversation_subject', ['phone' => $phone]);
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
                $thread->from                   = $phone;
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
            \Eventy::action('conversation.status_changed', $conversation, null, false, $prev_status);
        }
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
            Log::debug('[MetaWhatsApp] Status per a wamid desconegut', ['account_id' => $account->id]);
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
    }

    public function failed(\Throwable $e)
    {
        Log::error('[MetaWhatsApp] ProcessInboundWebhook failed permanently', [
            'account_id' => $this->accountId,
            'error'      => $e->getMessage(),
        ]);
    }
}
