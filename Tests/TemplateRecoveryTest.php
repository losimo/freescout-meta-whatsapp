<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\Thread;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Jobs\SendWhatsAppTemplate;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

class TemplateRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function runJob(WhatsAppAccount $account, array $payload)
    {
        (new ProcessInboundWebhook($account->id, $payload))->handle();
    }

    /**
     * Thread d'agent (tipus missatge, publicat) llest per encuar un
     * enviament outbound, sense cap fila WhatsAppMessage prèvia.
     * Còpia del helper equivalent de SendWhatsAppMessageTest.
     */
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
        $thread->body            = '[WhatsApp template] recover_conversation';
        $thread->source_via      = Thread::PERSON_USER;
        $thread->source_type     = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id     = $customer->id;
        $thread->created_by_user_id = 1;
        $thread->save();

        return $thread;
    }

    /**
     * Fa un bind al contenidor de WhatsAppApiClient que substitueix només el
     * transport (postMessagePayload, extret a Task 3) per una captura del
     * payload real construït per sendTemplate()/sendText(). No es mockeja
     * la lògica de negoci del client, només la crida HTTP.
     */
    protected function bindTemplateClientStub(array $response): \stdClass
    {
        $capture = new \stdClass();
        $capture->payload = null;

        $this->app->bind(WhatsAppApiClient::class, function ($app, $params) use ($capture, $response) {
            return new class($params['account'], $capture, $response) extends WhatsAppApiClient {
                private $capture;
                private $response;

                public function __construct($account, $capture, $response)
                {
                    parent::__construct($account);
                    $this->capture  = $capture;
                    $this->response = $response;
                }

                protected function postMessagePayload(array $payload): array
                {
                    $this->capture->payload = $payload;
                    return $this->response;
                }
            };
        });

        return $capture;
    }

    /**
     * Usuari admin de prova per a les proves HTTP del controlador
     * (necessari perquè la policy viewCached() del core doni pas sempre).
     */
    protected function makeAdminUser(): User
    {
        return factory(User::class)->create(['role' => User::ROLE_ADMIN]);
    }

    /**
     * Llegeix una propietat protegida d'un job encuat (Queue::fake() no
     * exposa accessors públics per a accountId/threadId/toPhone).
     */
    protected function jobProperty($job, string $name)
    {
        $prop = (new \ReflectionObject($job))->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($job);
    }

    public function test_el_compte_persisteix_la_configuracio_de_plantilla()
    {
        $account = $this->createTestAccount();
        $account->template_name              = 'recover_conversation';
        $account->template_lang              = 'es_ES';
        $account->template_threshold_minutes = 120;
        $account->save();

        $fresh = WhatsAppAccount::find($account->id);
        $this->assertEquals('recover_conversation', $fresh->template_name);
        $this->assertEquals('es_ES', $fresh->template_lang);
        $this->assertEquals(120, $fresh->template_threshold_minutes);
    }

    public function test_el_llindar_per_defecte_es_1435()
    {
        $account = $this->createTestAccount();
        $this->assertEquals(1435, WhatsAppAccount::find($account->id)->template_threshold_minutes);
    }

    public function test_finestra_expirada_sense_cap_inbound()
    {
        $account = $this->createTestAccount();
        $this->assertTrue(WhatsAppMessage::windowExpired(999999, $account));
    }

    public function test_finestra_oberta_amb_inbound_recent()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.tr1', '34611222333', 'hola'));
        $msg = WhatsAppMessage::where('wamid', 'wamid.tr1')->first();
        $this->assertFalse(WhatsAppMessage::windowExpired($msg->conversation_id, $account));
    }

    public function test_finestra_expirada_respecta_el_llindar_del_compte()
    {
        $account = $this->createTestAccount();
        $account->template_threshold_minutes = 60;
        $account->save();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.tr2', '34611222333', 'hola'));
        $msg = WhatsAppMessage::where('wamid', 'wamid.tr2')->first();
        // Envelleix el missatge 2 hores: supera el llindar de 60 min.
        WhatsAppMessage::where('id', $msg->id)->update(['created_at' => now()->subMinutes(120)]);
        $this->assertTrue(WhatsAppMessage::windowExpired($msg->conversation_id, $account));
        // Amb el llindar per defecte (1435) encara seria oberta.
        $account->template_threshold_minutes = 1435;
        $account->save();
        $this->assertFalse(WhatsAppMessage::windowExpired($msg->conversation_id, $account->fresh()));
    }

    public function test_el_job_de_plantilla_envia_el_payload_template_i_desa_el_wamid()
    {
        $account = $this->createTestAccount();
        $account->template_name = 'recover_conversation';
        $account->template_lang = 'es_ES';
        $account->save();
        $thread = $this->makeConversationWithThread($account, Thread::TYPE_MESSAGE, Thread::STATE_PUBLISHED);

        $capture = $this->bindTemplateClientStub([
            'ok'            => true,
            'wamid'         => 'wamid.tpl1',
            'http_status'   => 200,
            'error_code'    => null,
            'error_message' => null,
            'transient'     => false,
        ]);

        (new SendWhatsAppTemplate($account->id, $thread->id, '+34611222333'))->handle();

        // El payload real construït per sendTemplate(), capturat abans del
        // transport: confirma que el job crida sendTemplate (no sendText).
        $this->assertNotNull($capture->payload);
        $this->assertEquals('template', $capture->payload['type']);
        $this->assertEquals('recover_conversation', $capture->payload['template']['name']);
        $this->assertEquals('es_ES', $capture->payload['template']['language']['code']);
        $this->assertEquals('+34611222333', $capture->payload['to']);

        $msg = WhatsAppMessage::where('thread_id', $thread->id)->first();
        $this->assertNotNull($msg);
        $this->assertEquals('wamid.tpl1', $msg->wamid);
        $this->assertEquals(WhatsAppMessage::STATUS_SENT, $msg->status);
        $this->assertEquals(WhatsAppMessage::DIRECTION_OUTBOUND, $msg->direction);
    }

    public function test_el_job_de_plantilla_sense_config_marca_failed_i_no_crida_meta()
    {
        // Compte sense template_name/template_lang configurats.
        $account = $this->createTestAccount();
        $thread  = $this->makeConversationWithThread($account, Thread::TYPE_MESSAGE, Thread::STATE_PUBLISHED);

        // Stub que faria fallar el test si el job arribés a cridar el
        // transport: la manca de configuració ha d'avortar abans.
        $this->app->bind(WhatsAppApiClient::class, function ($app, $params) {
            return new class($params['account']) extends WhatsAppApiClient {
                protected function postMessagePayload(array $payload): array
                {
                    throw new \RuntimeException('El client no s\'hauria d\'haver cridat sense plantilla configurada.');
                }
            };
        });

        (new SendWhatsAppTemplate($account->id, $thread->id, '+34611222333'))->handle();

        $msg = WhatsAppMessage::where('thread_id', $thread->id)->first();
        $this->assertNotNull($msg);
        $this->assertEquals('failed-thread-' . $thread->id, $msg->wamid);
        $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $msg->status);
        $this->assertEquals(WhatsAppMessage::DIRECTION_OUTBOUND, $msg->direction);
    }

    // ------------------------------------------------------------------
    // Controlador: POST meta-whatsapp/conversation/{id}/send-template
    // ------------------------------------------------------------------

    public function test_post_enviament_de_plantilla_configurat_i_autoritzat_encua_job_i_crea_thread_auditoria()
    {
        $account = $this->createTestAccount();
        $account->template_name = 'recover_conversation';
        $account->template_lang = 'es_ES';
        $account->save();

        $this->runJob($account, $this->inboundPayload($account, 'wamid.ctrl1', '34611222333', 'hola'));
        $msg          = WhatsAppMessage::where('wamid', 'wamid.ctrl1')->first();
        $conversation = Conversation::find($msg->conversation_id);
        // Envelleix l'inbound perquè la finestra es consideri caducada: el
        // re-check de finestra al servidor (item 2) rebutjaria l'enviament
        // amb un inbound recent.
        WhatsAppMessage::where('id', $msg->id)->update(['created_at' => now()->subDay()]);

        Queue::fake();
        $admin = $this->makeAdminUser();

        $response = $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/conversation/' . $conversation->id . '/send-template'));

        $response->assertStatus(302);

        $thread = Thread::where('conversation_id', $conversation->id)
            ->where('body', '[WhatsApp template] recover_conversation')
            ->first();
        $this->assertNotNull($thread, 'Ha de crear el thread d\'auditoria amb el nom de la plantilla.');

        Queue::assertPushed(SendWhatsAppTemplate::class, function ($job) use ($account, $thread) {
            return $this->jobProperty($job, 'accountId') === $account->id
                && $this->jobProperty($job, 'threadId') === $thread->id
                && $this->jobProperty($job, 'toPhone') === '+34611222333';
        });
    }

    public function test_post_sense_plantilla_configurada_retorna_error_i_no_encua()
    {
        // createTestAccount() no fixa template_name/template_lang.
        $account = $this->createTestAccount();

        $this->runJob($account, $this->inboundPayload($account, 'wamid.ctrl2', '34611222333', 'hola'));
        $msg          = WhatsAppMessage::where('wamid', 'wamid.ctrl2')->first();
        $conversation = Conversation::find($msg->conversation_id);

        Queue::fake();
        $admin = $this->makeAdminUser();

        $response = $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/conversation/' . $conversation->id . '/send-template'));

        $response->assertStatus(302);
        $response->assertSessionHasErrors();

        Queue::assertNotPushed(SendWhatsAppTemplate::class);
        $this->assertFalse(
            Thread::where('conversation_id', $conversation->id)
                ->where('body', 'like', '[WhatsApp template]%')
                ->exists()
        );
    }

    public function test_post_conversa_que_no_es_del_modul_retorna_404()
    {
        $account = $this->createTestAccount();
        // Thread/conversa creats sense passar mai per ProcessInboundWebhook:
        // no hi ha cap fila meta_whatsapp_messages, per tant no és "del mòdul".
        $thread = $this->makeConversationWithThread($account, Thread::TYPE_MESSAGE, Thread::STATE_PUBLISHED);

        Queue::fake();
        $admin = $this->makeAdminUser();

        $response = $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/conversation/' . $thread->conversation_id . '/send-template'));

        $response->assertStatus(404);
        Queue::assertNotPushed(SendWhatsAppTemplate::class);
    }

    public function test_post_sense_telefon_resoluble_retorna_error_i_no_encua()
    {
        $account = $this->createTestAccount();
        $account->template_name = 'recover_conversation';
        $account->template_lang = 'es_ES';
        $account->save();

        // Fila BSUID-only: resol el compte (és "del mòdul") però sense
        // contact_phone (només contact_user_id), com un contacte de Messenger/IG.
        $thread = $this->makeConversationWithThread($account, Thread::TYPE_MESSAGE, Thread::STATE_PUBLISHED);
        WhatsAppMessage::create([
            'wamid'           => 'wamid.bsuid1',
            'account_id'      => $account->id,
            'conversation_id' => $thread->conversation_id,
            'contact_user_id' => 'bsuid-999',
            'direction'       => WhatsAppMessage::DIRECTION_INBOUND,
            'status'          => WhatsAppMessage::STATUS_RECEIVED,
        ]);

        Queue::fake();
        $admin = $this->makeAdminUser();

        // El helper makeConversationWithThread ja crea un thread amb body
        // '[WhatsApp template] ...': comparem recomptes, no existència.
        $threadsBefore = Thread::where('conversation_id', $thread->conversation_id)->count();

        $response = $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/conversation/' . $thread->conversation_id . '/send-template'));

        $response->assertStatus(302);
        $response->assertSessionHasErrors();

        Queue::assertNotPushed(SendWhatsAppTemplate::class);
        $this->assertEquals(
            $threadsBefore,
            Thread::where('conversation_id', $thread->conversation_id)->count(),
            'No ha de crear cap thread d\'auditoria nou sense telèfon resoluble.'
        );
    }

    public function test_post_amb_finestra_oberta_retorna_error_i_no_encua()
    {
        // Compte configurat, telèfon resoluble, però l'últim inbound és
        // recent: la finestra és oberta i el POST s'ha de rebutjar amb el
        // missatge template_window_open (re-check de finestra al servidor).
        $account = $this->createTestAccount();
        $account->template_name = 'recover_conversation';
        $account->template_lang = 'es_ES';
        $account->save();

        $this->runJob($account, $this->inboundPayload($account, 'wamid.win1', '34611222333', 'hola'));
        $msg          = WhatsAppMessage::where('wamid', 'wamid.win1')->first();
        $conversation = Conversation::find($msg->conversation_id);

        Queue::fake();
        $admin = $this->makeAdminUser();

        $response = $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/conversation/' . $conversation->id . '/send-template'));

        $response->assertStatus(302);
        $response->assertSessionHasErrors();

        Queue::assertNotPushed(SendWhatsAppTemplate::class);
        $this->assertFalse(
            Thread::where('conversation_id', $conversation->id)
                ->where('body', 'like', '[WhatsApp template]%')
                ->exists(),
            'No ha de crear cap thread d\'auditoria si la finestra torna a estar oberta.'
        );
    }

    public function test_post_idempotent_evita_segon_enviament_en_60_segons()
    {
        // Dos POSTs consecutius amb la finestra caducada: el primer ha de
        // crear el thread d'auditoria i encuar el job; el segon (dins dels
        // 60 segons) s'ha de rebutjar sense duplicar-los.
        $account = $this->createTestAccount();
        $account->template_name = 'recover_conversation';
        $account->template_lang = 'es_ES';
        $account->save();

        $this->runJob($account, $this->inboundPayload($account, 'wamid.idem1', '34611222333', 'hola'));
        $msg          = WhatsAppMessage::where('wamid', 'wamid.idem1')->first();
        $conversation = Conversation::find($msg->conversation_id);
        // Envelleix l'inbound per superar el llindar per defecte (1435 min).
        WhatsAppMessage::where('id', $msg->id)->update(['created_at' => now()->subDay()]);

        Queue::fake();
        $admin = $this->makeAdminUser();

        $first = $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/conversation/' . $conversation->id . '/send-template'));
        $first->assertStatus(302);

        $second = $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/conversation/' . $conversation->id . '/send-template'));
        $second->assertStatus(302);
        $second->assertSessionHasErrors();

        $this->assertEquals(
            1,
            Thread::where('conversation_id', $conversation->id)
                ->where('body', 'like', '[WhatsApp template]%')
                ->count(),
            'Només s\'hauria de crear un thread d\'auditoria en 60 segons.'
        );
        Queue::assertPushed(SendWhatsAppTemplate::class, 1);
    }

    public function test_banner_sense_template_lang_no_mostra_boto_i_mostra_no_configurat()
    {
        // Acord banner-controlador: el guard del controlador exigeix nom I
        // idioma; el banner ha de fer el mateix o mostraria un botó que
        // sempre acabaria en template_not_configured (UI sense sortida).
        $account = $this->createTestAccount();
        $account->template_name = 'recover_conversation';
        $account->template_lang = null;
        $account->save();

        $conversation = new Conversation();
        $conversation->id = 999999;

        $html = \View::make('metawhatsapp::partials/window_banner', [
            'conversation' => $conversation,
            'account'      => $account,
            'phone'        => '+34611222333',
        ])->render();

        $this->assertStringNotContainsString(
            '/send-template',
            $html,
            'Sense template_lang no s\'ha de renderitzar el formulari d\'enviament.'
        );
        $this->assertStringContainsString(
            __('metawhatsapp::metawhatsapp.template_not_configured'),
            $html
        );
    }
}
