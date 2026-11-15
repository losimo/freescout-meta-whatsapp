<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Issue #44. These tests go through the HTTP door on purpose. The refusal
 * lives in middleware pushed onto the `web` group, so a test that called the
 * middleware class directly would prove the class works while saying nothing
 * about whether it is ever reached, which is the failure mode this module has
 * already paid for twice.
 */
class NewConversationRefusalTest extends TestCase
{
    use DatabaseTransactions;

    /** A mailbox with no WhatsApp account, which must keep working as before. */
    private function plainMailbox(): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->name       = 'PHPUnit plain';
        $mailbox->email      = 'plain-' . uniqid() . '@example.com';
        $mailbox->out_method = Mailbox::OUT_METHOD_PHP_MAIL;
        $mailbox->in_server  = '';
        $mailbox->out_server = '';
        $mailbox->save();

        return $mailbox;
    }

    /** A published chat conversation with one thread, the shape Clone copies. */
    private function conversationWithThread($account): array
    {
        $customer = new Customer();
        $customer->first_name = 'Clone source';
        $customer->save();

        $conversation                 = new Conversation();
        $conversation->type           = Conversation::TYPE_CHAT;
        $conversation->state          = Conversation::STATE_PUBLISHED;
        $conversation->subject        = 'WhatsApp +34611000111';
        $conversation->mailbox_id     = $account->mailbox_id;
        $conversation->customer_id    = $customer->id;
        $conversation->customer_email = '';
        $conversation->status         = Conversation::STATUS_ACTIVE;
        $conversation->source_via     = Conversation::PERSON_CUSTOMER;
        $conversation->source_type    = Conversation::SOURCE_TYPE_API;
        $conversation->preview        = 'x';
        $conversation->save();

        $thread                  = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type            = Thread::TYPE_CUSTOMER;
        $thread->status          = $conversation->status;
        $thread->state           = Thread::STATE_PUBLISHED;
        $thread->body            = 'the message an agent might clone';
        $thread->source_via      = Thread::PERSON_CUSTOMER;
        $thread->source_type     = Thread::SOURCE_TYPE_API;
        $thread->customer_id     = $customer->id;
        $thread->save();

        return [$conversation, $thread, $customer];
    }

    /**
     * Built from the core's own route rather than from a path string, and
     * with a token this test plants in the session first.
     *
     * FreeScout 1.8.251 added a {token} segment to this route and checks it
     * against csrf_token(). A hardcoded URL started returning 404, which
     * made the test pass nothing rather than fail loudly: the guard was
     * fine, the test had stopped reaching it. route() follows whatever
     * shape the core gives the route, which is the half we do not own.
     */
    const CLONE_TOKEN = 'clone-token-for-tests';

    private function cloneUrl(int $mailboxId, int $threadId): string
    {
        return route('conversations.clone_conversation', [
            'mailbox_id'     => $mailboxId,
            'from_thread_id' => $threadId,
            'token'          => self::CLONE_TOKEN,
        ]);
    }

    /**
     * The second door, found by review after the first was closed. FreeScout's
     * Clone action is a GET that builds the conversation itself rather than
     * showing a form (ConversationsController::cloneConversation), and it
     * copies the original's type. On a WhatsApp mailbox that produces a second
     * CHAT conversation for the same customer, which is both the duplicate
     * issue #44 reports and a row the inbound reuse query cannot tell apart
     * from the real one, since filtering by type does not help when the
     * duplicate is a chat conversation too.
     *
     * The assertion that matters is the count, not the redirect: a guard that
     * redirected after the row was written would pass a status check and still
     * leave the damage behind.
     */
    public function test_cloning_a_conversation_is_refused_in_a_whatsapp_mailbox()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread, $customer] = $this->conversationWithThread($account);

        $before = Conversation::where('customer_id', $customer->id)->count();

        $response = $this->actingAs($this->makeAdminUser())
            ->withSession(['_token' => self::CLONE_TOKEN])
            ->get($this->cloneUrl($account->mailbox_id, $thread->id));

        $response->assertStatus(302);
        $response->assertRedirect(route('mailboxes.view', ['id' => $account->mailbox_id]));
        $this->assertEquals(
            $before,
            Conversation::where('customer_id', $customer->id)->count(),
            'cloning wrote a duplicate conversation for this customer'
        );
    }

    /**
     * Clone has to keep working everywhere else; it is a core feature. This
     * is also what proves the test above still reaches the route at all: if
     * the URL were wrong, this one would fail too.
     */
    public function test_cloning_is_left_alone_in_a_mailbox_without_whatsapp()
    {
        $account = $this->createTestAccount();
        [$conversation, $thread, $customer] = $this->conversationWithThread($account);

        // Move the whole thing to a mailbox with no WhatsApp account.
        $plain = $this->plainMailbox();
        $conversation->mailbox_id = $plain->id;
        $conversation->save();

        $before = Conversation::where('customer_id', $customer->id)->count();

        $this->actingAs($this->makeAdminUser())
            ->withSession(['_token' => self::CLONE_TOKEN])
            ->get($this->cloneUrl($plain->id, $thread->id));

        $this->assertGreaterThan(
            $before,
            Conversation::where('customer_id', $customer->id)->count(),
            'the guard blocked cloning in a mailbox that has no WhatsApp account'
        );
    }

    public function test_the_new_conversation_form_is_refused_in_a_whatsapp_mailbox()
    {
        $account = $this->createTestAccount();

        $response = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/mailbox/' . $account->mailbox_id . '/new-ticket'));

        $response->assertStatus(302);
        $response->assertRedirect(route('mailboxes.view', ['id' => $account->mailbox_id]));
    }

    /**
     * The refusal has to say why. A redirect with no explanation would leave
     * the agent exactly where this issue started: something happened, nothing
     * said what.
     */
    public function test_the_refusal_explains_itself()
    {
        $account = $this->createTestAccount();

        $this->actingAs($this->makeAdminUser())
            ->get($this->url('/mailbox/' . $account->mailbox_id . '/new-ticket'));

        $this->assertEquals(
            __('metawhatsapp::metawhatsapp.new_conversation_refused'),
            session('flash_error_floating')
        );
    }

    /**
     * The inverse, and the reason it matters: a middleware pushed onto the
     * whole `web` group that refused every mailbox would pass the test above
     * while breaking the rest of FreeScout for everyone.
     *
     * The assertion is that the guard does not intercept, not that the page
     * renders. Rendering the core's conversations/create.blade.php against a
     * bare mailbox raises a PHP 8.2 deprecation inside the core view
     * (htmlspecialchars() receiving null), which has nothing to do with this
     * module: the stack trace puts this middleware in the pipeline frame that
     * called $next($request), so the pass-through already happened. Asserting
     * a 200 here would be asserting the core view's health on a newer PHP
     * than the module's floor, which is not this test's business.
     */
    public function test_a_mailbox_without_whatsapp_is_left_alone()
    {
        $mailbox = $this->plainMailbox();

        $response = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/mailbox/' . $mailbox->id . '/new-ticket'));

        $this->assertNotEquals(
            route('mailboxes.view', ['id' => $mailbox->id]),
            $response->headers->get('Location'),
            'the guard bounced a mailbox that has no WhatsApp account'
        );
        $this->assertNull(
            session('flash_error_floating'),
            'the guard explained a refusal that should never have happened'
        );
    }

    /**
     * Every other route has to pass through untouched. The guard keys on one
     * route name, and this is what proves it is not keying on something
     * broader like the presence of a mailbox_id parameter.
     */
    public function test_the_mailbox_itself_still_opens_in_a_whatsapp_mailbox()
    {
        $account = $this->createTestAccount();

        $this->actingAs($this->makeAdminUser())
            ->get($this->url('/mailbox/' . $account->mailbox_id))
            ->assertStatus(200);
    }

    /**
     * The other half of issue #44: the button itself. Refusing the route
     * stops the damage, but leaving a button that bounces whoever presses it
     * is a worse sidebar than one without the button.
     *
     * Asserted against the rendered page rather than the CSS-building method,
     * because the method could be perfect and never injected, which is the
     * shape of mistake that cost this module a whole release once.
     */
    public function test_the_sidebar_hides_the_new_conversation_button_in_a_whatsapp_mailbox()
    {
        $account = $this->createTestAccount();

        $response = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/mailbox/' . $account->mailbox_id));

        $html = $response->getContent();
        $this->assertStringContainsString('.sidebar-buttons a[href*="/new-ticket"]', $html);
        // And nothing broader than that one link: `mailbox.sidebar.buttons`
        // is a public hook, so another module's button can be standing in the
        // same strip and must survive.
        $this->assertStringNotContainsString('.sidebar-buttons:not(', $html);
    }

    /**
     * The rule must not travel to mailboxes that are not WhatsApp, or the
     * module would be removing a core button from the rest of the install.
     */
    public function test_the_sidebar_rule_is_absent_from_a_mailbox_without_whatsapp()
    {
        $mailbox = $this->plainMailbox();

        $html = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/mailbox/' . $mailbox->id))
            ->getContent();

        $this->assertStringNotContainsString('.sidebar-buttons a[href*="/new-ticket"]', $html);
    }

    /**
     * The CSS has to reach the <head>. Injected at the bottom of the body it
     * would still work, but the button would be visible for a moment first,
     * and that flash is a bug this module already fixed once (issue #17).
     */
    public function test_the_sidebar_rule_arrives_before_the_body()
    {
        $account = $this->createTestAccount();

        $html = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/mailbox/' . $account->mailbox_id))
            ->getContent();

        $rulePosition = strpos($html, '.sidebar-buttons a[href*="/new-ticket"]');
        $bodyPosition = stripos($html, '<body');

        $this->assertNotFalse($rulePosition);
        $this->assertNotFalse($bodyPosition);
        $this->assertLessThan(
            $bodyPosition,
            $rulePosition,
            'the rule is injected after the body opens, so the button flashes before it hides'
        );
    }
}
