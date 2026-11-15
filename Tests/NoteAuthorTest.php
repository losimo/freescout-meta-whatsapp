<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\Thread;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Providers\MetaWhatsAppServiceProvider;
use Modules\MetaWhatsApp\Support\DebugLog;
use Modules\MetaWhatsApp\Support\NoteAuthor;

/**
 * Issue #43. Who the module's automatic notes are attributed to.
 *
 * v2.1.0 made those notes carry the agent they concern so the core would give
 * them a face. FreeScout then renders the author as "you" to whoever is
 * reading, so the agent whose reply had failed was shown a note about
 * themselves as if they had written it. The reporter asked for a fixed user
 * chosen by the administrator, and for the old attribution back when that
 * user is gone.
 *
 * The save is exercised through the HTTP door: the form is the only way an
 * administrator reaches the controller, and a test that calls the method
 * directly says nothing about whether the field arrives.
 */
class NoteAuthorTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $name, int $status = User::STATUS_ACTIVE): User
    {
        $user = new User();
        $user->first_name = $name;
        $user->last_name  = 'Note';
        $user->email      = strtolower($name) . '-' . uniqid() . '@example.com';
        $user->password   = bcrypt('secret');
        $user->role       = User::ROLE_USER;
        $user->status     = $status;
        $user->save();

        return $user;
    }

    /** POST the diagnostics form the way the screen does. */
    private function saveDiagnostics(array $fields)
    {
        return $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/diagnostics'), array_merge([
                'debug_window'    => '',
                'debug_retention' => 7,
            ], $fields));
    }

    /** A reply on the account's mailbox whose customer has no phone at all. */
    private function makeRefusedReply(WhatsAppAccount $account, User $agent): array
    {
        $customer = new Customer();
        $customer->first_name = 'No phone';
        $customer->save();

        $conversation = new Conversation();
        $conversation->type           = Conversation::TYPE_CHAT;
        $conversation->state          = Conversation::STATE_PUBLISHED;
        $conversation->subject        = 'PHPUnit';
        $conversation->mailbox_id     = $account->mailbox_id;
        $conversation->customer_id    = $customer->id;
        $conversation->customer_email = '';
        $conversation->status         = Conversation::STATUS_ACTIVE;
        $conversation->source_via     = Conversation::PERSON_CUSTOMER;
        $conversation->source_type    = Conversation::SOURCE_TYPE_API;
        $conversation->preview        = 'x';
        $conversation->save();

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->user_id            = $agent->id;
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->status             = $conversation->status;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = '<p>reply that cannot go anywhere</p>';
        $thread->source_via         = Thread::PERSON_USER;
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id        = $customer->id;
        $thread->created_by_user_id = $agent->id;
        $thread->save();

        return [$conversation, $thread];
    }

    private function dispatchReplies($conversation, array $replies): void
    {
        $provider = new MetaWhatsAppServiceProvider($this->app);
        $method   = new \ReflectionMethod($provider, 'handleOutboundReplies');
        $method->setAccessible(true);
        $method->invoke($provider, $conversation, $replies);
    }

    private function refusalNote($conversation): ?Thread
    {
        return Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->first();
    }

    // ---------------------------------------------------------------- saving

    public function test_an_admin_can_choose_the_user_through_the_settings_form()
    {
        $chosen = $this->makeUser('Chosen');

        $this->saveDiagnostics(['note_author' => (string) $chosen->id])->assertStatus(302);

        $this->assertEquals($chosen->id, NoteAuthor::configuredUserId());
    }

    public function test_the_empty_choice_puts_it_back_to_the_agent()
    {
        NoteAuthor::setUserId($this->makeUser('Chosen')->id);

        $this->saveDiagnostics(['note_author' => ''])->assertStatus(302);

        $this->assertNull(NoteAuthor::configuredUserId());
    }

    /**
     * A rejected save must leave the whole form alone, not only this field:
     * the retention is stored by the same request and an administrator who
     * mistyped one value must not find the other half applied.
     */
    public function test_an_id_that_names_nobody_is_refused_and_nothing_at_all_is_saved()
    {
        $chosen = $this->makeUser('Chosen');
        NoteAuthor::setUserId($chosen->id);
        DebugLog::setRetentionDays(7);

        $this->saveDiagnostics([
            'note_author'     => '99999999',
            'debug_retention' => 30,
        ])->assertStatus(302);

        $this->assertEquals($chosen->id, NoteAuthor::configuredUserId());
        $this->assertEquals(7, DebugLog::retentionDays(), 'a refused save still wrote the retention');
    }

    /**
     * FreeScout deletes a user by marking the row, so a deleted user is still
     * findable and an `exists` check alone would accept one. Storing it would
     * save a setting that can never apply.
     */
    public function test_a_deleted_user_cannot_be_chosen()
    {
        $gone = $this->makeUser('Gone', User::STATUS_DELETED);

        $this->saveDiagnostics(['note_author' => (string) $gone->id])->assertStatus(302);

        $this->assertNull(NoteAuthor::configuredUserId());
        // Checked on the stored value too, and not only on what the module
        // then does with it: reading it back filters a deleted user out
        // anyway, so this assertion is the only one that notices if the save
        // accepted the id in the first place.
        $this->assertEmpty(
            \Option::get(NoteAuthor::OPTION_USER, '', true, false),
            'the save stored a deleted user, which could never apply'
        );
    }

    public function test_the_dropdown_offers_live_users_and_not_deleted_ones()
    {
        $live = $this->makeUser('Liveone');
        $gone = $this->makeUser('Goneone', User::STATUS_DELETED);

        $rendered = \View::make('metawhatsapp::partials/diagnostics', [
            'debug' => ['enabled' => false, 'forced_env' => false, 'always' => false, 'expires_at' => null, 'retention' => 7],
        ])->render();

        $this->assertStringContainsString('name="note_author"', $rendered);
        $this->assertStringContainsString(e(__('metawhatsapp::metawhatsapp.note_author_agent')), $rendered);
        $this->assertStringContainsString($live->getFullName(), $rendered);
        $this->assertStringNotContainsString(
            $gone->getFullName(),
            $rendered,
            'the dropdown offered a deleted user, which the save would then refuse'
        );
    }

    // ------------------------------------------- the refusal note (#28, #29)

    public function test_with_no_user_chosen_a_refusal_note_still_carries_the_agent()
    {
        $agent   = $this->makeUser('Refused');
        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeRefusedReply($account, $agent);

        $this->dispatchReplies($conversation, [$thread]);

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note);
        $this->assertEquals($agent->id, $note->created_by_user_id);
    }

    public function test_a_refusal_note_carries_the_chosen_user_instead_of_the_agent()
    {
        $agent   = $this->makeUser('Refused');
        $system  = $this->makeUser('Systemuser');
        NoteAuthor::setUserId($system->id);

        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeRefusedReply($account, $agent);

        $this->dispatchReplies($conversation, [$thread]);

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note);
        $this->assertEquals(
            $system->id,
            $note->created_by_user_id,
            'the note still named the agent it is about, so FreeScout keeps rendering it as "you" to them'
        );
    }

    /**
     * The degradation the reporter asked for by name: the placeholder user is
     * deleted months later, and the notes go back to what they did before the
     * setting existed rather than pointing at a user who is gone.
     */
    public function test_a_refusal_note_goes_back_to_the_agent_when_the_chosen_user_was_deleted()
    {
        $agent  = $this->makeUser('Refused');
        $system = $this->makeUser('Systemuser');
        NoteAuthor::setUserId($system->id);

        $system->status = User::STATUS_DELETED;
        $system->save();

        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeRefusedReply($account, $agent);

        $this->dispatchReplies($conversation, [$thread]);

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note, 'the note must survive a chosen user who is gone');
        $this->assertEquals($agent->id, $note->created_by_user_id);
    }

    // -------------------------------------------- the delivery failure note

    /**
     * The second note writer lives in the webhook job, which is the one that
     * runs inside a long-lived queue worker. Both have to read the same
     * setting or the same conversation would show two different authors.
     */
    public function test_a_delivery_failure_note_carries_the_chosen_user()
    {
        $agent  = $this->makeUser('Failedauthor');
        $system = $this->makeUser('Systemuser');
        NoteAuthor::setUserId($system->id);

        $account = $this->createTestAccount();
        (new ProcessInboundWebhook(
            $account->id,
            $this->inboundPayload($account, 'wamid.na-in1', '34611222333', 'hola')
        ))->handle();

        $conversation = Conversation::find(
            WhatsAppMessage::where('wamid', 'wamid.na-in1')->first()->conversation_id
        );

        $outbound = new Thread();
        $outbound->conversation_id    = $conversation->id;
        $outbound->type               = Thread::TYPE_MESSAGE;
        $outbound->status             = $conversation->status;
        $outbound->state              = Thread::STATE_PUBLISHED;
        $outbound->body               = 'A reply that Meta will reject';
        $outbound->source_via         = Thread::PERSON_USER;
        $outbound->source_type        = Thread::SOURCE_TYPE_WEB;
        $outbound->customer_id        = $conversation->customer_id;
        $outbound->created_by_user_id = $agent->id;
        $outbound->save();

        WhatsAppMessage::create([
            'wamid'           => 'wamid.na-out1',
            'account_id'      => $account->id,
            'conversation_id' => $conversation->id,
            'thread_id'       => $outbound->id,
            'contact_phone'   => '+34611222333',
            'direction'       => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status'          => WhatsAppMessage::STATUS_SENT,
        ]);

        $statusPayload = $this->inboundPayload($account, 'x', 'x', 'x');
        $statusPayload['entry'][0]['changes'][0]['value'] = [
            'messaging_product' => 'whatsapp',
            'metadata'          => ['phone_number_id' => $account->phone_number_id],
            'statuses'          => [[
                'id'     => 'wamid.na-out1',
                'status' => 'failed',
                'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
            ]],
        ];
        (new ProcessInboundWebhook($account->id, $statusPayload))->handle();

        $note = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->where('body', 'like', '[WhatsApp delivery failed]%')
            ->first();

        $this->assertNotNull($note);
        $this->assertEquals($system->id, $note->created_by_user_id);
    }

    /**
     * The attribution is the only thing the setting changes. A note that
     * stopped being written, or a note tied to somebody else, would be a
     * worse outcome than the wording the reporter complained about.
     */
    public function test_choosing_a_user_does_not_change_what_the_note_is_tied_to()
    {
        $agent  = $this->makeUser('Refused');
        $system = $this->makeUser('Systemuser');
        NoteAuthor::setUserId($system->id);

        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeRefusedReply($account, $agent);

        $this->dispatchReplies($conversation, [$thread]);

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note);
        $this->assertEquals(
            $agent->id,
            $note->user_id,
            'user_id says who the note is tied to, and reassigning it would decide an assignment for the team'
        );
    }
}
