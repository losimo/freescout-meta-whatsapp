<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Jobs\SendWhatsAppMessage;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * Guards del job outbound: cap d'aquests casos ha d'arribar a fer HTTP.
 * Els camins HTTP (2xx, 131047, 190, 5xx) estan verificats amb mock en
 * runtime (acta Fase 3); aquí es cobreix el que ha de curtcircuitar abans.
 */
class SendWhatsAppMessageTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeConversationWithThread(WhatsAppAccount $account, int $threadType, int $threadState): Thread
    {
        $customer = new Customer();
        $customer->first_name = '+34611222333';
        $customer->save();

        $conversation = new Conversation();
        $conversation->type        = Conversation::TYPE_CHAT;
        $conversation->state       = Conversation::STATE_PUBLISHED;
        $conversation->subject     = 'PHPUnit';
        $conversation->mailbox_id  = $account->mailbox_id;
        $conversation->customer_id = $customer->id;
        $conversation->customer_email = '';
        $conversation->status      = Conversation::STATUS_ACTIVE;
        $conversation->source_via  = Conversation::PERSON_CUSTOMER;
        $conversation->source_type = Conversation::SOURCE_TYPE_API;
        $conversation->preview     = 'x';
        $conversation->save();

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->user_id         = 1;
        $thread->type            = $threadType;
        $thread->status          = $conversation->status;
        $thread->state           = $threadState;
        $thread->body            = '<p>resposta de prova</p>';
        $thread->source_via      = Thread::PERSON_USER;
        $thread->source_type     = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id     = $customer->id;
        $thread->created_by_user_id = 1;
        $thread->save();

        return $thread;
    }

    public function test_compte_inactiu_no_envia_ni_crea_fila()
    {
        $account = $this->createTestAccount(['is_active' => false]);
        $thread  = $this->makeConversationWithThread($account, Thread::TYPE_MESSAGE, Thread::STATE_PUBLISHED);

        (new SendWhatsAppMessage($account->id, $thread->id, '+34611222333'))->handle();

        $this->assertEquals(0, WhatsAppMessage::where('thread_id', $thread->id)->count());
    }

    public function test_thread_en_draft_no_envia()
    {
        $account = $this->createTestAccount();
        $thread  = $this->makeConversationWithThread($account, Thread::TYPE_MESSAGE, Thread::STATE_DRAFT);

        (new SendWhatsAppMessage($account->id, $thread->id, '+34611222333'))->handle();

        $this->assertEquals(0, WhatsAppMessage::where('thread_id', $thread->id)->count());
    }

    public function test_nota_interna_no_envia()
    {
        $account = $this->createTestAccount();
        $thread  = $this->makeConversationWithThread($account, Thread::TYPE_NOTE, Thread::STATE_PUBLISHED);

        (new SendWhatsAppMessage($account->id, $thread->id, '+34611222333'))->handle();

        $this->assertEquals(0, WhatsAppMessage::where('thread_id', $thread->id)->count());
    }

    public function test_idempotencia_outbound_curtcircuita_abans_del_http()
    {
        $account = $this->createTestAccount();
        $thread  = $this->makeConversationWithThread($account, Thread::TYPE_MESSAGE, Thread::STATE_PUBLISHED);

        WhatsAppMessage::create([
            'wamid'           => 'wamid.ja-enviat',
            'account_id'      => $account->id,
            'conversation_id' => $thread->conversation_id,
            'thread_id'       => $thread->id,
            'contact_phone'   => '+34611222333',
            'direction'       => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status'          => WhatsAppMessage::STATUS_SENT,
        ]);

        // Si arribés a l'HTTP contra graph.facebook.com petaria o trigaria;
        // el check d'idempotència ha de retornar immediatament.
        (new SendWhatsAppMessage($account->id, $thread->id, '+34611222333'))->handle();

        $this->assertEquals(1, WhatsAppMessage::where('thread_id', $thread->id)->count());
    }
}
