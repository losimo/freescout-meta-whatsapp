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
 * Issue #68 took the second half back. Falling back to the agent restored
 * the exact reading this screen exists to remove, and it needed nobody to
 * change a setting: a deleted placeholder, or a fresh installation that
 * never opened the screen, was enough. The chain is now the configured user,
 * then the installation's first administrator by id, then the agent only
 * where there is no administrator left at all.
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

    /**
     * One known administrator and nobody else, so a test's expectation is a
     * fixture rather than a second copy of the query under test.
     *
     * The rows that were there are marked deleted and not removed, which is
     * what FreeScout's own delete does (app/User.php:1475, which leaves the
     * role alone), and the surrounding transaction puts them back.
     */
    private function onlyAdministrator(): User
    {
        $this->deleteEveryAdministrator();

        $admin = $this->makeAdminUser();
        $admin->status = User::STATUS_ACTIVE;
        $admin->save();

        return $admin;
    }

    private function deleteEveryAdministrator(): void
    {
        User::where('role', User::ROLE_ADMIN)
            ->where('status', '!=', User::STATUS_DELETED)
            ->update(['status' => User::STATUS_DELETED]);
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

    public function test_the_empty_choice_clears_the_setting()
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
        $this->assertStringContainsString(e(__('metawhatsapp::metawhatsapp.note_author_default')), $rendered);
        $this->assertStringContainsString($live->getFullName(), $rendered);
        $this->assertStringNotContainsString(
            $gone->getFullName(),
            $rendered,
            'the dropdown offered a deleted user, which the save would then refuse'
        );
    }

    // ------------------------------------------- the refusal note (#28, #29)

    /**
     * Issue #68. With nothing chosen the note used to carry the agent it is
     * about, so the agent whose reply had just failed read the module's note
     * as their own sentence. That is the #43 reading, and it was the default
     * on every installation that never opened this screen.
     */
    public function test_with_no_user_chosen_a_refusal_note_carries_the_first_administrator()
    {
        $admin   = $this->onlyAdministrator();
        $agent   = $this->makeUser('Refused');
        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeRefusedReply($account, $agent);

        $this->dispatchReplies($conversation, [$thread]);

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note);
        $this->assertEquals(
            $admin->id,
            $note->created_by_user_id,
            'the note named the agent it is about, so the core renders it to them as "you" (#43)'
        );
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
     * Issue #68. The placeholder user is deleted months later. The note used
     * to go back to the agent it is about, which restored the "you" reading
     * the setting exists to remove, with nobody changing a setting to cause
     * it. It now lands where an installation that never configured an author
     * lands, so the two cases read the same on screen.
     */
    public function test_a_refusal_note_falls_back_to_the_first_administrator_when_the_chosen_user_was_deleted()
    {
        $admin  = $this->onlyAdministrator();
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
        $this->assertEquals($admin->id, $note->created_by_user_id);
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
        $agent    = $this->makeUser('Refused');
        $system   = $this->makeUser('Systemuser');
        $assignee = $this->makeUser('Assignee');
        NoteAuthor::setUserId($system->id);

        $account = $this->createTestAccount();
        [$conversation, $thread] = $this->makeRefusedReply($account, $agent);
        $conversation->user_id = $assignee->id;
        $conversation->save();

        $this->dispatchReplies($conversation, [$thread]);

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note);
        $this->assertEquals(
            $assignee->id,
            $note->user_id,
            'choosing a note author changed what the note is tied to'
        );
        $this->assertEquals($system->id, $note->created_by_user_id);
    }

    /**
     * Reported in #43 after the setting shipped. `threads.user_id` is not
     * the author and not something this module gets to choose: the core
     * mirrors the conversation's assignee onto every thread it writes
     * (Conversation::createUserThread does `$thread->user_id = $this->user_id`),
     * and FreeScout prints that name on the right of the note.
     *
     * Our notes carried an agent in one writer and whichever user happened
     * to have access to the mailbox in the other, so the same name appeared
     * on every note no matter whose message had failed. On an unassigned
     * conversation the right value is null, which is what this asserts,
     * since a wrong non-null value is exactly what was shipped.
     */
    public function test_a_refusal_note_mirrors_the_conversations_assignee_and_not_an_agent()
    {
        $account = $this->createTestAccount();
        $agent   = $this->makeUser('Agent whose reply failed');
        [$conversation, $thread] = $this->makeRefusedReply($account, $agent);

        $this->assertNull($conversation->user_id, 'fixture precondition: nobody assigned');

        $this->dispatchReplies($conversation, [$thread]);

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note);
        $this->assertNull(
            $note->user_id,
            'the note claims an assignee the conversation does not have'
        );
    }

    /** And when somebody IS assigned, that is the name the note carries. */
    public function test_a_refusal_note_carries_the_assignee_when_there_is_one()
    {
        $account = $this->createTestAccount();
        $agent   = $this->makeUser('Agent whose reply failed');
        [$conversation, $thread] = $this->makeRefusedReply($account, $agent);

        $assignee = $this->makeUser('The assignee');
        $conversation->user_id = $assignee->id;
        $conversation->save();

        $this->dispatchReplies($conversation, [$thread]);

        $note = $this->refusalNote($conversation);
        $this->assertNotNull($note);
        $this->assertEquals($assignee->id, $note->user_id);
        $this->assertNotEquals($agent->id, $note->user_id, 'the note carried the agent, not the assignee');
    }

    // ------------------------------------- the author of nobody's note (#55)

    /**
     * Issue #55. The core's print view reads the author's name without
     * checking for null (resources/views/conversations/partials/thread.blade.php:89,
     * inside @if (\Helper::isPrint())), so a note with no author at all
     * takes the whole page down with a call on null.
     *
     * Some of this module's notes concern no agent whatsoever: the one that
     * says a customer matched several records, and the one that says Meta
     * reported a new number. Both reach forNote(null), which used to answer
     * null, which is the authorless note the reporter printed.
     *
     * The replacement is the core's own expression for "the installation
     * itself" and not user id 1: nothing creates that id, an administrator
     * can be demoted, and a deleted user keeps their row.
     */
    public function test_a_note_that_concerns_no_agent_still_gets_an_author()
    {
        $this->assertNull(NoteAuthor::configuredUserId(), 'the setting leaked from another test');
        $admin = $this->onlyAdministrator();

        $author = NoteAuthor::forNote(null);

        $this->assertNotNull($author, 'a note with no author breaks the core print view');
        $this->assertEquals($admin->id, $author);
    }

    /** The administrator's choice still wins over the installation's admin. */
    public function test_the_chosen_user_still_wins_for_a_note_that_concerns_no_agent()
    {
        $system = $this->makeUser('Systemuser');
        NoteAuthor::setUserId($system->id);

        $this->assertEquals($system->id, NoteAuthor::forNote(null));
    }

    // -------------------------------------- which administrator, exactly (#68)

    /**
     * Issue #68. "The first administrator" has to answer the same user on
     * every read, and the core's own expression does not: on 1.8.252
     * User::getSuperAdmin() is nonDeleted()->where('role', ROLE_ADMIN)
     * ->first() with no ORDER BY (app/User.php:1077), so which one it returns
     * is up to the storage engine. MarkoD had it ordered upstream
     * (freescout-help-desk/freescout@aa8827d), but that ships in the release
     * after 1.8.252 while this module supports FreeScout from 1.8.234, so the
     * order is the module's own until the floor passes it.
     *
     * Two administrators created in a known order, so the lower id is a
     * fixture and not a repetition of the query under test.
     */
    public function test_the_installation_author_is_the_lowest_id_administrator()
    {
        $this->deleteEveryAdministrator();

        $lower  = $this->makeAdminUser();
        $higher = $this->makeAdminUser();
        $this->assertLessThan($higher->id, $lower->id, 'the fixture stopped creating ids in order');

        $this->assertEquals($lower->id, NoteAuthor::forNote(null));

        $lower->status = User::STATUS_DELETED;
        $lower->save();

        $this->assertEquals(
            $higher->id,
            NoteAuthor::forNote(null),
            'a deleted administrator kept signing the notes'
        );
    }

    /**
     * Issue #68. Zero non-deleted administrators is reachable through the
     * interface alone, which is why the agent stays at the end of the chain
     * rather than coming out of it:
     *
     * 1. two administrators, A and B, and A deletes B. deleteUser() marks the
     *    status and leaves the role alone (app/User.php:1475).
     * 2. A opens their own profile, where the role select is rendered for any
     *    administrator, themselves included (users/profile.blade.php:37-43).
     * 3. A switches to agent. The guard counts
     *    User::where('role', ROLE_ADMIN)->count() with no status filter
     *    (UsersController.php:224), so the deleted B makes it two and the
     *    change is allowed.
     *
     * What the last resort protects is #55: with no author at all, the core's
     * print view takes the whole conversation down.
     */
    public function test_with_no_administrator_left_the_note_carries_the_agent_it_is_about()
    {
        $this->deleteEveryAdministrator();
        $agent = $this->makeUser('Lastresort');

        $this->assertEquals($agent->id, NoteAuthor::forNote($agent->id));
    }

    /**
     * And with nobody to name at all the author stays null, because this
     * module does not invent a user on an installation that has none. The
     * note is still written: it is the only visible trace of what happened.
     */
    public function test_with_no_administrator_and_no_agent_the_author_stays_null()
    {
        $this->deleteEveryAdministrator();

        $this->assertNull(NoteAuthor::forNote(null));
    }
}
