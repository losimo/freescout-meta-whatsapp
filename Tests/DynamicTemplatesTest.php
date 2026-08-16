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

/**
 * Picker dinàmic de plantilles (issue #2, punt 2 complet): fetch en viu del
 * catàleg APPROVED de Meta + variables, kapsowhatsapp-style. Complementa el
 * mecanisme estàtic ja cobert a TemplateRecoveryTest.php.
 */
class DynamicTemplatesTest extends TestCase
{
    use DatabaseTransactions;

    protected function runJob(WhatsAppAccount $account, array $payload)
    {
        (new ProcessInboundWebhook($account->id, $payload))->handle();
    }

    protected function makeAdminUser(): User
    {
        return factory(User::class)->create(['role' => User::ROLE_ADMIN]);
    }

    protected function jobProperty($job, string $name)
    {
        $prop = (new \ReflectionObject($job))->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($job);
    }

    // ------------------------------------------------------------------
    // WhatsAppApiClient::listTemplates()
    // ------------------------------------------------------------------

    public function test_list_templates_filtra_nomes_approved_i_compta_variables()
    {
        $account = $this->createTestAccount();
        $client  = new class($account) extends WhatsAppApiClient {
            protected function curlGet(string $url, array $headers): array
            {
                return [
                    'ok' => true, 'body' => json_encode(['data' => [
                        ['name' => 'pending_one', 'language' => 'en', 'status' => 'PENDING', 'category' => 'UTILITY', 'components' => []],
                        ['name' => 'rejected_one', 'language' => 'en', 'status' => 'REJECTED', 'category' => 'UTILITY', 'components' => []],
                        ['name' => 'recover_conversation', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [
                            ['type' => 'BODY', 'text' => 'Hi {{1}}, you have a new message about {{2}}.'],
                        ]],
                        ['name' => 'no_variables', 'language' => 'ca', 'status' => 'APPROVED', 'category' => 'MARKETING', 'components' => [
                            ['type' => 'BODY', 'text' => 'Tens un missatge nou.'],
                        ]],
                    ]]),
                    'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false,
                ];
            }
        };

        $result = $client->listTemplates();

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['templates']);
        $this->assertEquals('recover_conversation', $result['templates'][0]['name']);
        $this->assertEquals(2, $result['templates'][0]['variable_count']);
        $this->assertEquals('no_variables', $result['templates'][1]['name']);
        $this->assertEquals(0, $result['templates'][1]['variable_count']);
    }

    public function test_list_templates_amb_error_de_meta_retorna_ok_false_i_llista_buida()
    {
        $account = $this->createTestAccount();
        $client  = new class($account) extends WhatsAppApiClient {
            protected function curlGet(string $url, array $headers): array
            {
                return [
                    'ok' => false, 'body' => null,
                    'http_status' => 401, 'error_code' => '190', 'error_message' => 'Token caducat', 'transient' => false,
                ];
            }
        };

        $result = $client->listTemplates();

        $this->assertFalse($result['ok']);
        $this->assertEquals([], $result['templates']);
        $this->assertEquals('Token caducat', $result['error_message']);
    }

    // ------------------------------------------------------------------
    // WhatsAppApiClient::sendTemplate() amb variables
    // ------------------------------------------------------------------

    public function test_send_template_sense_variables_no_afegeix_components()
    {
        $account = $this->createTestAccount();
        $capture = new \stdClass();
        $client  = new class($account, $capture) extends WhatsAppApiClient {
            private $capture;
            public function __construct($account, $capture) { parent::__construct($account); $this->capture = $capture; }
            protected function postMessagePayload(array $payload): array
            {
                $this->capture->payload = $payload;
                return ['ok' => true, 'wamid' => 'wamid.x', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false];
            }
        };

        $client->sendTemplate('+34611222333', 'recover_conversation', 'es_ES');

        $this->assertArrayNotHasKey('components', $capture->payload['template']);
    }

    public function test_send_template_amb_variables_afegeix_components_de_body()
    {
        $account = $this->createTestAccount();
        $capture = new \stdClass();
        $client  = new class($account, $capture) extends WhatsAppApiClient {
            private $capture;
            public function __construct($account, $capture) { parent::__construct($account); $this->capture = $capture; }
            protected function postMessagePayload(array $payload): array
            {
                $this->capture->payload = $payload;
                return ['ok' => true, 'wamid' => 'wamid.x', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false];
            }
        };

        $client->sendTemplate('+34611222333', 'recover_conversation', 'en', ['Anna', 'the invoice']);

        $components = $capture->payload['template']['components'];
        $this->assertEquals('body', $components[0]['type']);
        $this->assertEquals('Anna', $components[0]['parameters'][0]['text']);
        $this->assertEquals('the invoice', $components[0]['parameters'][1]['text']);
    }

    // ------------------------------------------------------------------
    // Job SendWhatsAppTemplate en mode dinàmic
    // ------------------------------------------------------------------

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

    public function test_job_en_mode_dinamic_no_valida_contra_les_plantilles_estatiques_del_compte()
    {
        // Compte amb una plantilla estàtica diferent de la que s'envia:
        // en mode dinàmic (variables !== null) no s'ha de validar contra
        // account->findTemplate(), a diferència del mode estàtic.
        $account = $this->createTestAccount();
        $account->templates = [
            ['id' => 'recover_conversation', 'language' => 'es_ES', 'display_name' => 'Recover'],
        ];
        $account->save();
        $thread = $this->makeConversationWithThread($account, Thread::TYPE_MESSAGE, Thread::STATE_PUBLISHED);

        $capture = new \stdClass();
        $this->app->bind(WhatsAppApiClient::class, function ($app, $params) use ($capture) {
            return new class($params['account'], $capture) extends WhatsAppApiClient {
                private $capture;
                public function __construct($account, $capture) { parent::__construct($account); $this->capture = $capture; }
                protected function postMessagePayload(array $payload): array
                {
                    $this->capture->payload = $payload;
                    return ['ok' => true, 'wamid' => 'wamid.dyn1', 'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false];
                }
            };
        });

        (new SendWhatsAppTemplate($account->id, $thread->id, '+34611222333', 'marketing_promo', 'en', ['Anna']))->handle();

        $this->assertEquals('marketing_promo', $capture->payload['template']['name']);
        $this->assertEquals('en', $capture->payload['template']['language']['code']);
        $this->assertEquals('Anna', $capture->payload['template']['components'][0]['parameters'][0]['text']);

        $msg = WhatsAppMessage::where('thread_id', $thread->id)->first();
        $this->assertEquals(WhatsAppMessage::STATUS_SENT, $msg->status);
    }

    // ------------------------------------------------------------------
    // Controlador
    // ------------------------------------------------------------------

    public function test_get_browse_templates_renderitza_la_llista_en_viu()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.dyn2', '34611222333', 'hola'));
        $msg          = WhatsAppMessage::where('wamid', 'wamid.dyn2')->first();
        $conversation = Conversation::find($msg->conversation_id);

        $this->app->bind(WhatsAppApiClient::class, function ($app, $params) {
            return new class($params['account']) extends WhatsAppApiClient {
                protected function curlGet(string $url, array $headers): array
                {
                    return [
                        'ok' => true, 'body' => json_encode(['data' => [
                            ['name' => 'recover_conversation', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [
                                ['type' => 'BODY', 'text' => 'Hi {{1}}!'],
                            ]],
                        ]]),
                        'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false,
                    ];
                }
            };
        });

        $admin = $this->makeAdminUser();
        $response = $this->actingAs($admin)
            ->get($this->url('/meta-whatsapp/conversation/' . $conversation->id . '/templates'));

        $response->assertStatus(200);
        $response->assertViewHas('result', function ($result) {
            return $result['ok'] === true && count($result['templates']) === 1;
        });
    }

    public function test_post_send_dynamic_template_encua_job_amb_variables_i_thread_auditoria()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.dyn3', '34611222333', 'hola'));
        $msg          = WhatsAppMessage::where('wamid', 'wamid.dyn3')->first();
        $conversation = Conversation::find($msg->conversation_id);
        WhatsAppMessage::where('id', $msg->id)->update(['created_at' => now()->subDay()]);

        Queue::fake();
        $admin = $this->makeAdminUser();

        $response = $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/conversation/' . $conversation->id . '/send-dynamic-template'), [
                'template_name'     => 'marketing_promo',
                'template_language' => 'en',
                'variables'         => ['Anna', 'the invoice'],
            ]);

        $response->assertStatus(302);

        $thread = Thread::where('conversation_id', $conversation->id)
            ->where('body', '[WhatsApp template] marketing_promo (Anna, the invoice)')
            ->first();
        $this->assertNotNull($thread);

        Queue::assertPushed(SendWhatsAppTemplate::class, function ($job) use ($account, $thread) {
            return $this->jobProperty($job, 'accountId') === $account->id
                && $this->jobProperty($job, 'threadId') === $thread->id
                && $this->jobProperty($job, 'templateId') === 'marketing_promo'
                && $this->jobProperty($job, 'templateLanguage') === 'en'
                && $this->jobProperty($job, 'variables') === ['Anna', 'the invoice'];
        });
    }

    public function test_post_send_dynamic_template_sense_nom_retorna_error()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.dyn4', '34611222333', 'hola'));
        $msg          = WhatsAppMessage::where('wamid', 'wamid.dyn4')->first();
        $conversation = Conversation::find($msg->conversation_id);
        WhatsAppMessage::where('id', $msg->id)->update(['created_at' => now()->subDay()]);

        Queue::fake();
        $admin = $this->makeAdminUser();

        $response = $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/conversation/' . $conversation->id . '/send-dynamic-template'), [
                'template_language' => 'en',
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors();
        Queue::assertNotPushed(SendWhatsAppTemplate::class);
    }

    public function test_post_send_dynamic_template_amb_finestra_oberta_retorna_error()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.dyn5', '34611222333', 'hola'));
        $msg          = WhatsAppMessage::where('wamid', 'wamid.dyn5')->first();
        $conversation = Conversation::find($msg->conversation_id);
        // Finestra encara oberta (inbound recent).

        Queue::fake();
        $admin = $this->makeAdminUser();

        $response = $this->actingAs($admin)
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/conversation/' . $conversation->id . '/send-dynamic-template'), [
                'template_name'     => 'marketing_promo',
                'template_language' => 'en',
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors();
        Queue::assertNotPushed(SendWhatsAppTemplate::class);
    }
}
