<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;

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
}
