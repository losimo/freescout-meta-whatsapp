<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Support\CoreCompat;

/**
 * The reply must not leave by two doors at once.
 *
 * FreeScout 1.8.251 (core issue #5799) added an "Also send reply to customer
 * by email" checkbox to the reply form of every chat conversation whose
 * customer has an e-mail address, ticked by default. Core reads it in
 * ConversationsController (`:1245` in 1.8.252) into
 * Thread::META_CHAT_SEND_EMAIL, six lines before firing
 * `thread.before_save_from_request`, and SendReplyToCustomer (`:88-93`)
 * dispatches this module's WhatsApp send and then, if that meta is present,
 * carries on and e-mails the same reply.
 *
 * So on a WhatsApp mailbox read outside chat mode, with a customer who also
 * has an e-mail on file, every answer went out twice and the agent had to
 * untick a box each time to stop it. Reported by MarkoD in issue #57.
 *
 * A stylesheet cannot fix this: a checkbox hidden by CSS is still submitted,
 * so hiding it alone would only have taken away the agent's ability to
 * untick it. The stop is on the server, and the stylesheet is the second
 * layer so that nobody is shown a control that does nothing.
 */
class CoreChatEmailCopyTest extends TestCase
{
    use DatabaseTransactions;

    /** A chat conversation on the given mailbox whose customer has an e-mail. */
    protected function chatConversationWithEmailCustomer(int $mailboxId): Thread
    {
        $customer = new Customer();
        $customer->first_name = 'Has e-mail';
        $customer->save();

        $conversation = new Conversation();
        $conversation->type           = Conversation::TYPE_CHAT;
        $conversation->state          = Conversation::STATE_PUBLISHED;
        $conversation->subject        = 'PHPUnit';
        $conversation->mailbox_id     = $mailboxId;
        $conversation->customer_id    = $customer->id;
        $conversation->customer_email = 'customer@example.com';
        $conversation->status         = Conversation::STATUS_ACTIVE;
        $conversation->source_via     = Conversation::PERSON_CUSTOMER;
        $conversation->source_type    = Conversation::SOURCE_TYPE_API;
        $conversation->preview        = 'x';
        $conversation->save();

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->user_id            = 1;
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->status             = $conversation->status;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = '<p>an answer</p>';
        $thread->source_via         = Thread::PERSON_USER;
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id        = $customer->id;
        $thread->created_by_user_id = 1;
        $thread->save();

        $thread->load('conversation');
        $thread->setMeta(Thread::META_CHAT_SEND_EMAIL, 1);

        return $thread;
    }

    protected function plainMailbox(): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->name  = 'Plain PHPUnit mailbox';
        $mailbox->email = 'plain-'.uniqid().'@example.com';
        $mailbox->save();

        return $mailbox;
    }

    /**
     * Fired the way the core fires it, through the global hook rather than by
     * calling the method: if the listener is never registered the test has to
     * fail, which is the mode that let two earlier defects through.
     */
    public function test_the_core_email_copy_is_dropped_on_a_whatsapp_mailbox()
    {
        $account = $this->createTestAccount();
        $thread  = $this->chatConversationWithEmailCustomer($account->mailbox_id);

        \Eventy::action('thread.before_save_from_request', $thread, request());

        $this->assertEmpty(
            $thread->getMeta(Thread::META_CHAT_SEND_EMAIL),
            'the thread still carries the core flag, so the reply also goes out by e-mail'
        );
    }

    /**
     * The channel can be told to allow it, and then the module has to get
     * out of the way completely. The defect was the checkbox arriving
     * ticked, not the checkbox existing: the core's own default is to
     * offer it, so a module that refuses it for everyone is overruling
     * the core. Asked for by MarkoD on #57 after the first fix.
     */
    public function test_a_channel_that_allows_the_copy_keeps_the_flag()
    {
        $account = $this->createTestAccount();
        $account->allow_core_email_copy = true;
        $account->save();

        $thread = $this->chatConversationWithEmailCustomer($account->mailbox_id);

        \Eventy::action('thread.before_save_from_request', $thread, request());

        $this->assertEquals(
            1,
            $thread->getMeta(Thread::META_CHAT_SEND_EMAIL),
            'the channel allows the e-mail copy and the module took the flag off anyway'
        );
    }

    /**
     * Through the form, because the switch is only worth anything if the
     * screen can set it. A sibling switch on this same form shipped a 500
     * on every save once, for a method that does not exist on this
     * Laravel, and no test covered the route at all.
     */
    protected function saveChannel(\Modules\MetaWhatsApp\Models\WhatsAppAccount $account, array $extra = [])
    {
        return $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->put($this->url('/meta-whatsapp/settings/' . $account->id), array_merge([
                'name'                       => $account->name,
                'phone_number'               => $account->phone_number,
                'phone_number_id'            => $account->phone_number_id,
                'waba_id'                    => $account->waba_id ?: 'waba-test',
                'verify_token'               => str_repeat('a', 64),
                'template_threshold_minutes' => 1435,
            ], $extra));
    }

    public function test_the_switch_can_be_turned_on_from_the_form()
    {
        $account = $this->createTestAccount();

        $this->saveChannel($account, ['allow_core_email_copy' => '1'])->assertStatus(302);

        $this->assertTrue((bool) $account->fresh()->allow_core_email_copy);
    }

    /** An unticked box does not travel in the request, so it has to be decided. */
    public function test_leaving_the_box_unticked_switches_it_off_again()
    {
        $account = $this->createTestAccount();
        $account->allow_core_email_copy = true;
        $account->save();

        $this->saveChannel($account)->assertStatus(302);

        $this->assertFalse((bool) $account->fresh()->allow_core_email_copy);
    }

    /** Off is the default, and a fresh channel must not have to say so. */
    public function test_the_copy_is_not_allowed_on_a_new_channel()
    {
        $account = $this->createTestAccount();

        $this->assertFalse(
            (bool) $account->fresh()->allow_core_email_copy,
            'a new channel allows the e-mail copy, so the accident is open by default'
        );
    }

    /**
     * The inverse, and the reason it matters: this hook fires on every reply
     * in the install, so a listener keyed on anything broader than "this
     * conversation belongs to a WhatsApp mailbox" would quietly cancel a
     * feature the core added for everybody else.
     */
    public function test_a_conversation_outside_a_whatsapp_mailbox_keeps_it()
    {
        $this->createTestAccount();
        $thread = $this->chatConversationWithEmailCustomer($this->plainMailbox()->id);

        \Eventy::action('thread.before_save_from_request', $thread, request());

        $this->assertEquals(
            1,
            $thread->getMeta(Thread::META_CHAT_SEND_EMAIL),
            'the flag was taken off a conversation this module has nothing to do with'
        );
    }

    /**
     * The tripwire. The guard above names a core constant, and if the core
     * renames it the guard stops doing anything with no sign at all: the
     * suite stays green and the e-mails start going out again.
     *
     * Asserted against the coupling rather than against a version number. If
     * the reply form the running core renders still offers the checkbox,
     * then the constant this module unsets has to exist. On a core old
     * enough to have neither, both halves are absent and there is nothing to
     * check.
     */
    public function test_the_core_still_names_the_flag_this_module_unsets()
    {
        $view = resource_path('views/conversations/view.blade.php');
        $this->assertFileExists($view, 'the core reply view is not where it was');

        if (strpos(file_get_contents($view), 'chat_send_email') === false) {
            $this->assertFalse(
                defined(Thread::class.'::META_CHAT_SEND_EMAIL'),
                'the core dropped the checkbox but kept the flag, so the guard needs rereading'
            );

            return;
        }

        $this->assertTrue(
            defined(Thread::class.'::META_CHAT_SEND_EMAIL'),
            'the core still renders the e-mail copy checkbox but renamed the flag this module unsets'
        );
    }

    // -----------------------------------------------------------------
    // The switch against conditions outside this module (#57, MarkoD)
    // -----------------------------------------------------------------

    protected function coreParameterKey(): string
    {
        return substr(CoreCompat::CORE_CHAT_EMAIL_PARAMETER, strlen('app.'));
    }

    /** The installation's copy of the core parameter, null included. */
    protected function coreParameterValue($value): void
    {
        $app = config('app');
        $app[$this->coreParameterKey()] = $value;
        config(['app' => $app]);
    }

    /**
     * And the state the development container is really in, which is why it
     * cannot be left implicit: the day this container moves to a core that
     * has the parameter, a test that relied on today's absence would quietly
     * start checking something else instead. It needs its own method because
     * the config repository's `has()` answers true for a key that exists
     * holding null, so absence cannot be expressed by setting a value.
     */
    protected function coreParameterAbsent(): void
    {
        $app = config('app');
        unset($app[$this->coreParameterKey()]);
        config(['app' => $app]);
    }

    protected function channelForm(WhatsAppAccount $account)
    {
        return $this->actingAs($this->makeAdminUser())
            ->get($this->url('/meta-whatsapp/settings/' . $account->id . '/edit'));
    }

    /** As the page carries it: Blade escapes these, apostrophes included. */
    protected function screenText(string $key): string
    {
        return e(__('metawhatsapp::metawhatsapp.' . $key));
    }

    /**
     * With the core's gate shut there is no checkbox and no meta, so the
     * switch promises something the installation has taken away. Said on the
     * screen rather than acted on: there is nothing for the module to do
     * differently.
     */
    public function test_the_form_warns_when_the_installation_switched_the_copy_off()
    {
        $account = $this->createTestAccount();
        $this->coreParameterValue(false);

        $response = $this->channelForm($account);

        // The status assertion carries the next one: an error page does not
        // mention the parameter either.
        $response->assertStatus(200);
        $this->assertStringContainsString(
            $this->screenText('email_copy_core_disabled'),
            $response->getContent(),
            'the switch is offered as if it worked on an installation whose core has the feature off'
        );
    }

    /** The core's own default, where the switch means exactly what it says. */
    public function test_the_form_says_nothing_when_the_parameter_is_on()
    {
        $account = $this->createTestAccount();
        $this->coreParameterValue(true);

        $response = $this->channelForm($account);

        $response->assertStatus(200);
        $this->assertStringNotContainsString(
            $this->screenText('email_copy_core_disabled'),
            $response->getContent(),
            'an installation that offers the feature is told its core has it switched off'
        );
    }

    /**
     * And the version that matters most, because it is what people are
     * running today: on 1.8.251 and 1.8.252 the parameter does not exist, the
     * feature is live, and this switch is the only thing standing between an
     * unaware agent and the double send. A warning there would be false.
     */
    public function test_the_form_says_nothing_when_the_core_has_no_such_parameter()
    {
        $account = $this->createTestAccount();
        $this->coreParameterAbsent();

        $response = $this->channelForm($account);

        $response->assertStatus(200);
        $this->assertStringNotContainsString(
            $this->screenText('email_copy_core_disabled'),
            $response->getContent(),
            'a core with no such parameter is reported as having switched the feature off'
        );
    }

    /**
     * The third state, written down because the guard treats it like `false`
     * and nothing else records that choice: a key that exists holding null.
     * It counts as off because the core's own gate is a plain truth test on
     * the same value, so null closes the checkbox there too.
     */
    public function test_a_parameter_holding_null_counts_as_off()
    {
        $account = $this->createTestAccount();
        $this->coreParameterValue(null);

        $response = $this->channelForm($account);

        $response->assertStatus(200);
        $this->assertStringContainsString(
            $this->screenText('email_copy_core_disabled'),
            $response->getContent(),
            'a parameter holding null is read as the feature being available'
        );
    }

    /**
     * Shown whichever way the switch is set, because the whole point is to
     * tell an administrator that turning it on would achieve nothing.
     */
    public function test_the_warning_does_not_wait_for_the_switch_to_be_on()
    {
        $account = $this->createTestAccount();
        $account->allow_core_email_copy = true;
        $account->save();
        $this->coreParameterValue(false);

        $response = $this->channelForm($account);

        $response->assertStatus(200);
        $this->assertStringContainsString(
            $this->screenText('email_copy_core_disabled'),
            $response->getContent(),
            'the warning is withheld from a channel that has the copy allowed, which is the one that needs it'
        );
    }

    /**
     * And where it is said, which is the whole of what this change is for.
     * Without this, the paragraph could move to the top of the form or into
     * the usage block and every other test here would stay green while
     * nobody reading the switch saw it.
     */
    public function test_the_warning_is_said_next_to_the_switch()
    {
        $account = $this->createTestAccount();
        $this->coreParameterValue(false);

        $response = $this->channelForm($account);
        $response->assertStatus(200);
        $html = $response->getContent();

        $field   = strpos($html, 'name="allow_core_email_copy"');
        $warning = strpos($html, $this->screenText('email_copy_core_disabled'));
        $help    = strpos($html, $this->screenText('email_copy_enable_help'));

        $this->assertNotFalse($field, 'the switch is no longer on the channel form');
        $this->assertNotFalse($warning, 'the warning is not on the form at all');
        $this->assertNotFalse($help, 'the help text of the switch is not where it was');
        $this->assertTrue(
            $field < $warning && $warning < $help,
            'the warning is no longer between the switch and its help text, so it reads as being about something else'
        );
    }

    /**
     * And that it reads as a warning. This looks like styling and is not: the
     * first attempt wrote `class="help-block text-warning"`, and in the core
     * stylesheet `.help-block` sets a colour (`public/css/bootstrap.css`
     * line 2931) after `.text-warning` does (line 1365), same specificity,
     * so the later rule won and the warning came out in exactly the same grey
     * as the help text underneath it. Nothing failed; it just stopped looking
     * like a warning.
     */
    public function test_the_warning_is_not_dressed_as_ordinary_help_text()
    {
        $account = $this->createTestAccount();
        $this->coreParameterValue(false);

        $html    = $this->channelForm($account)->getContent();
        $warning = strpos($html, $this->screenText('email_copy_core_disabled'));
        $this->assertNotFalse($warning, 'the warning is not on the form at all');

        $opening = substr($html, max(0, $warning - 200), min(200, $warning));
        $this->assertStringContainsString('text-warning', $opening, 'the warning is not marked as one');
        $this->assertStringNotContainsString(
            'help-block',
            $opening,
            'the warning carries help-block, whose colour wins over text-warning, so it renders as plain help text'
        );
    }

    /**
     * The field stays editable, and this is the test for a decision rather
     * than for a mechanism. A disabled checkbox is not submitted, and
     * `update()` reads this one as `(bool) $request->input(...)` on every
     * save, so disabling it would turn an administrator's stored choice off
     * the next time they saved the channel for an unrelated reason, with
     * nothing on the screen to tell them.
     */
    public function test_the_warning_leaves_the_switch_usable()
    {
        $account = $this->createTestAccount();
        $this->coreParameterValue(false);

        $response = $this->channelForm($account);
        $response->assertStatus(200);

        preg_match('/<input[^>]*name="allow_core_email_copy"[^>]*>/', $response->getContent(), $field);
        $this->assertNotEmpty($field, 'the switch is no longer on the channel form at all');
        $this->assertStringNotContainsString(
            'disabled',
            $field[0],
            'the switch is disabled, so the next save of this channel would silently clear it'
        );
    }

    /**
     * The other condition outside this module, and the one that is genuinely
     * per-channel. From the release after 1.8.252 the core also gates that
     * checkbox on `$mailbox->isOutActive()`, which is false for a mailbox
     * whose outgoing method is SMTP with no server filled in. Same defect as
     * the parameter above: a switch that cannot do anything.
     */
    public function test_a_mailbox_that_cannot_send_mail_is_said_so()
    {
        $account = $this->createTestAccount();
        $mailbox = $account->mailbox;
        $mailbox->out_method = Mailbox::OUT_METHOD_SMTP;
        $mailbox->out_server = '';
        $mailbox->save();

        $response = $this->channelForm($account);

        $response->assertStatus(200);
        $this->assertStringContainsString(
            $this->screenText('email_copy_mailbox_cannot_send'),
            $response->getContent(),
            'a channel whose mailbox cannot send mail is offered the switch as if it worked'
        );
    }

    /**
     * And silent on a channel left as this module made it, which is the
     * common case: those mailboxes send through PHP mail, and that counts as
     * active with nothing configured.
     */
    public function test_a_mailbox_as_the_module_made_it_is_not_warned_about()
    {
        $account = $this->createTestAccount();

        $response = $this->channelForm($account);

        $response->assertStatus(200);
        $this->assertStringNotContainsString(
            $this->screenText('email_copy_mailbox_cannot_send'),
            $response->getContent(),
            'a mailbox that sends through PHP mail is reported as unable to send'
        );
    }
}
