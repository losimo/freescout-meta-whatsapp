<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Support\PersonalDataErasure;

/**
 * Right of erasure: what the module holds about one person, and taking
 * it out. See docs/superpowers/specs/2026-11-06-metawhatsapp-dret-de-supressio-design.md
 */
class PersonalDataErasureTest extends TestCase
{
    use DatabaseTransactions;

    protected function runJob(WhatsAppAccount $account, array $payload)
    {
        (new ProcessInboundWebhook($account->id, $payload))->handle();
    }

    private function row($account, array $overrides = []): WhatsAppMessage
    {
        $message = new WhatsAppMessage();
        $message->account_id      = $account->id;
        $message->wamid           = 'wamid.erasure-' . uniqid();
        $message->direction       = WhatsAppMessage::DIRECTION_INBOUND;
        $message->status          = WhatsAppMessage::STATUS_RECEIVED;
        foreach ($overrides as $field => $value) {
            $message->$field = $value;
        }
        $message->save();

        return $message;
    }

    /**
     * The chain that one round of expansion misses. Outbound rows never
     * carry a BSUID, and Meta regenerates the BSUID when someone
     * re-registers, so a person's rows form a chain longer than two hops:
     * (P1,B1) -> (P1,B2) -> (null,B2). Searching by B1 must reach all of
     * them, or the receipt claims a completeness it does not have.
     *
     * The hyphen in the BSUID is deliberate, not a readable label:
     * extractContactUserId() accepts any printable ASCII except space, so a
     * stored BSUID may contain one. inputVariants() used to strip hyphens
     * from the raw input before ever searching, which made a hyphenated
     * BSUID unfindable on this exact search. This test is the regression
     * guard for that bug as well as for the fixed-point expansion.
     */
    public function test_expansion_reaches_a_three_hop_identity_chain()
    {
        $account = $this->createTestAccount();
        $a = $this->row($account, ['contact_phone' => '+34611000001', 'contact_user_id' => 'BSUID-OLD']);
        $b = $this->row($account, ['contact_phone' => '+34611000001', 'contact_user_id' => 'BSUID-NEW']);
        $c = $this->row($account, ['contact_phone' => null, 'contact_user_id' => 'BSUID-NEW']);

        PersonalDataErasure::erase('BSUID-OLD');

        foreach ([$a, $b, $c] as $row) {
            $fresh = $row->fresh();
            $this->assertNull($fresh->contact_phone, 'a phone survived the erasure');
            $this->assertNull($fresh->contact_user_id, 'a BSUID survived the erasure');
        }
    }

    public function test_erasure_keeps_everything_that_is_not_identity()
    {
        $account = $this->createTestAccount();
        $row = $this->row($account, [
            'contact_phone' => '+34611000002',
            'status'        => WhatsAppMessage::STATUS_READ,
        ]);
        $row->delivered_at = now()->subHour();
        $row->read_at      = now();
        $row->save();
        $wamid = $row->wamid;

        PersonalDataErasure::erase('+34611000002');

        $fresh = $row->fresh();
        $this->assertEquals($wamid, $fresh->wamid);
        $this->assertEquals(WhatsAppMessage::STATUS_READ, $fresh->status);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertNotNull($fresh->read_at);
    }

    /**
     * The module's own channel rows go, by identifier and not by customer:
     * learnBsuid() deliberately leaves the same BSUID on one customer and
     * the phone on another without merging them, so resolving "the"
     * customer would leave the other one's row alive.
     */
    public function test_erasure_removes_our_channel_rows_across_customers_and_leaves_others()
    {
        $account = $this->createTestAccount();
        $this->row($account, ['contact_phone' => '+34611000003', 'contact_user_id' => 'BSUID-SPLIT']);

        $withPhone = new \App\Customer();
        $withPhone->first_name = 'Phone side';
        $withPhone->save();
        $withPhone->addChannel(WhatsAppAccount::CHANNEL, '+34611000003');
        $withPhone->addChannel(99, 'someone@example.com');

        $withBsuid = new \App\Customer();
        $withBsuid->first_name = 'BSUID side';
        $withBsuid->save();
        $withBsuid->addChannel(WhatsAppAccount::CHANNEL_BSUID, 'BSUID-SPLIT');

        PersonalDataErasure::erase('+34611000003');

        $this->assertFalse(
            \App\CustomerChannel::where('channel', WhatsAppAccount::CHANNEL)
                ->where('channel_id', '+34611000003')->exists(),
            'the phone channel row survived'
        );
        $this->assertFalse(
            \App\CustomerChannel::where('channel', WhatsAppAccount::CHANNEL_BSUID)
                ->where('channel_id', 'BSUID-SPLIT')->exists(),
            'the BSUID channel row on the other customer survived'
        );
        $this->assertTrue(
            \App\CustomerChannel::where('customer_id', $withPhone->id)
                ->where('channel', 99)->exists(),
            'a channel row that is not ours was removed'
        );
    }

    /**
     * Keeping the wamid is the whole reason the row survives: without it
     * a Meta redelivery would recreate the person who was just erased.
     */
    public function test_idempotency_survives_the_erasure()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.erasure-idem', '34611000004', 'hola'));
        $conversationsBefore = \App\Conversation::count();
        $customersBefore     = \App\Customer::count();

        PersonalDataErasure::erase('+34611000004');

        $this->runJob($account, $this->inboundPayload($account, 'wamid.erasure-idem', '34611000004', 'hola'));

        $this->assertEquals($conversationsBefore, \App\Conversation::count());
        $this->assertEquals($customersBefore, \App\Customer::count());
    }

    /**
     * Stored phones are E.164 with '+'. People paste without it, and a
     * numeric BSUID is indistinguishable from a phone, so the input is
     * compared against both columns and both spellings.
     */
    public function test_search_finds_a_phone_pasted_without_the_plus_sign()
    {
        $account = $this->createTestAccount();
        $this->row($account, ['contact_phone' => '+34611000005']);

        $summary = PersonalDataErasure::summary('34611000005');

        $this->assertNotNull($summary);
        $this->assertEquals(1, $summary['messages']);
        $this->assertContains('+34611000005', $summary['identifiers']);
    }

    public function test_search_finds_a_phone_pasted_with_spaces_and_hyphens()
    {
        $account = $this->createTestAccount();
        $this->row($account, ['contact_phone' => '+34611000013']);

        $summary = PersonalDataErasure::summary('+34 611-000-013');

        $this->assertNotNull($summary);
        $this->assertEquals(1, $summary['messages']);
    }

    /**
     * Found by review. An administrator answering a request pastes the number
     * the way the requester wrote it, and people write phone numbers with
     * parentheses, dots, or a 00 international prefix. Stored numbers are
     * always E.164 with digits only, so none of those matched and the screen
     * answered "this module holds nothing about this person".
     *
     * On a screen whose entire purpose is to prove what we hold, a false
     * nothing is the worst output there is: it ends the search, and the
     * administrator reports back in good faith that there was nothing to
     * erase.
     *
     * @dataProvider phoneSpellings
     */
    public function test_search_finds_a_phone_however_the_requester_wrote_it(string $pasted)
    {
        $account = $this->createTestAccount();
        $this->row($account, ['contact_phone' => '+34611000021']);

        $summary = PersonalDataErasure::summary($pasted);

        $this->assertNotNull($summary, 'nothing found for ' . $pasted);
        $this->assertEquals(1, $summary['messages']);
        $this->assertContains('+34611000021', $summary['identifiers']);
    }

    public static function phoneSpellings(): array
    {
        return [
            'parentheses'          => ['+34 (611) 000 021'],
            'dots'                 => ['+34.611.000.021'],
            'double zero prefix'   => ['0034611000021'],
            'double zero spaced'   => ['00 34 611 000 021'],
            'slashes'              => ['+34/611/000/021'],
            'already canonical'    => ['+34611000021'],
        ];
    }

    public function test_search_finds_a_numeric_bsuid()
    {
        $account = $this->createTestAccount();
        $this->row($account, ['contact_phone' => null, 'contact_user_id' => '998877665544']);

        $summary = PersonalDataErasure::summary('998877665544');

        $this->assertNotNull($summary);
        $this->assertEquals(1, $summary['messages']);
    }

    public function test_search_returns_null_when_we_hold_nothing()
    {
        $this->assertNull(PersonalDataErasure::summary('+34699999999'));
    }

    public function test_summary_counts_rows_whose_conversation_is_gone()
    {
        $account = $this->createTestAccount();
        $this->row($account, ['contact_phone' => '+34611000006', 'conversation_id' => null]);
        $this->row($account, ['contact_phone' => '+34611000006', 'conversation_id' => 999777555]);

        $summary = PersonalDataErasure::summary('+34611000006');

        $this->assertEquals(2, $summary['messages']);
        $this->assertEquals(2, $summary['orphaned']);
    }

    public function test_the_log_line_never_names_the_person()
    {
        $account = $this->createTestAccount();
        $this->row($account, ['contact_phone' => '+34611000007', 'contact_user_id' => 'BSUID-LOGGED']);

        \Log::shouldReceive('info')->once()->withArgs(function ($message, $context = []) {
            $serialised = $message . ' ' . json_encode($context);
            $this->assertStringNotContainsString('+34611000007', $serialised);
            $this->assertStringNotContainsString('BSUID-LOGGED', $serialised);
            return true;
        });

        PersonalDataErasure::erase('+34611000007');
    }

    /**
     * The door, not just the class: with the identity gone, the recovery
     * banner must fall back to its existing "no phone on file" notice
     * instead of offering to send a paid template.
     *
     * Check first whether the existing BSUID-only coverage already walks
     * this path; if it does, fold this in as a variant of that test
     * rather than adding a new one.
     */
    public function test_the_recovery_banner_falls_back_to_the_no_phone_notice()
    {
        $account = $this->createTestAccount();
        // The view only reaches the "no phone on file" branch when a
        // template is configured but there is no phone to send it to; with
        // no template at all it shows "not configured" instead, regardless
        // of phone. See Views/partials/window_banner.blade.php.
        $account->templates = [[
            'id' => 'recover_conversation', 'language' => 'es_ES',
            'display_name' => 'recover_conversation', 'recovery_text' => null,
        ]];
        $account->save();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.erasure-banner', '34611000011', 'hola'));
        $inbound = WhatsAppMessage::where('wamid', 'wamid.erasure-banner')->first();
        $conversation = \App\Conversation::find($inbound->conversation_id);

        // Window state (and so the recovery banner) is driven by the inbound
        // message's created_at, not by wall-clock test runtime: back it off
        // past the account's threshold, same as the rest of the suite does
        // (see TemplateRecoveryTest), so the banner actually has a reason to
        // render instead of staying empty because the window is still open.
        WhatsAppMessage::where('id', $inbound->id)->update(['created_at' => now()->subDay()]);

        PersonalDataErasure::erase('+34611000011');

        $rendered = \Modules\MetaWhatsApp\Support\WindowState::renderBanner($conversation, $account->fresh());

        $this->assertStringContainsString(
            __('metawhatsapp::metawhatsapp.template_no_phone_notice'),
            $rendered
        );
    }

    /**
     * An agent replying to a surviving conversation must not reach the
     * erased person. This is the hook path, not the queued job: without
     * removing the customer_channel rows, handleOutboundReplies()'s
     * fallback finds the phone and dispatches a send.
     */
    public function test_an_agent_reply_after_the_erasure_dispatches_nothing()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.erasure-reply', '34611000012', 'hola'));
        $inbound = WhatsAppMessage::where('wamid', 'wamid.erasure-reply')->first();
        $conversation = \App\Conversation::find($inbound->conversation_id);

        $thread = new \App\Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type            = \App\Thread::TYPE_MESSAGE;
        $thread->status          = $conversation->status;
        $thread->state           = \App\Thread::STATE_PUBLISHED;
        $thread->body            = 'reply written after the erasure';
        $thread->source_via      = \App\Thread::PERSON_USER;
        $thread->source_type     = \App\Thread::SOURCE_TYPE_WEB;
        $thread->customer_id     = $conversation->customer_id;
        $thread->save();

        PersonalDataErasure::erase('+34611000012');

        \Queue::fake();
        \Eventy::action('chat_conversation.send_reply', $conversation, collect([$thread]), $conversation->customer);

        \Queue::assertNothingPushed();
    }

    /**
     * An agent replying to a surviving conversation must not resend to an
     * erased person. Without removing the customer_channel rows, the
     * fallback in handleOutboundReplies() finds the phone and writes it
     * back into our table as a fresh outbound row.
     */
    public function test_a_queued_job_stops_after_the_erasure()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.erasure-job', '34611000009', 'hola'));
        $inbound = WhatsAppMessage::where('wamid', 'wamid.erasure-job')->first();
        $conversation = \App\Conversation::find($inbound->conversation_id);

        $thread = new \App\Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type            = \App\Thread::TYPE_MESSAGE;
        $thread->status          = $conversation->status;
        $thread->state           = \App\Thread::STATE_PUBLISHED;
        $thread->body            = 'reply queued before the erasure';
        $thread->source_via      = \App\Thread::PERSON_USER;
        $thread->source_type     = \App\Thread::SOURCE_TYPE_WEB;
        $thread->customer_id     = $conversation->customer_id;
        $thread->save();

        // The job is built with the phone, exactly as the real path does.
        $job = new \Modules\MetaWhatsApp\Jobs\SendWhatsAppMessage(
            $account->id, $thread->id, '+34611000009'
        );

        PersonalDataErasure::erase('+34611000009');

        $outboundBefore = WhatsAppMessage::where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)->count();
        $job->handle();

        $this->assertEquals(
            $outboundBefore,
            WhatsAppMessage::where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)->count(),
            'a queued job wrote the erased phone back into the table'
        );
    }

    public function test_we_stop_holding_the_phone_after_an_erasure_and_held_it_before()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.erasure-route', '34611000010', 'hola'));

        $this->assertTrue(
            \Modules\MetaWhatsApp\Support\OutboundGuard::stillHoldsPhone('+34611000010')
        );

        PersonalDataErasure::erase('+34611000010');

        $this->assertFalse(
            \Modules\MetaWhatsApp\Support\OutboundGuard::stillHoldsPhone('+34611000010')
        );
    }

    /**
     * The case the conversation-based guard could not see, and the reason
     * the question had to move from the thread to the number.
     *
     * A job is queued carrying person A's phone. Before it runs, the
     * conversation is moved onto a different customer who has a WhatsApp
     * channel row of their own, which is what the core's "Change customer"
     * and Customer::mergeWith() both do. Then A is erased. The old guard
     * asked whether the CONVERSATION still had a route, found the new
     * customer's row, and let the job deliver to A anyway.
     */
    public function test_a_route_belonging_to_someone_else_does_not_let_the_job_through()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.erasure-moved', '34611000011', 'hola'));
        $inbound = WhatsAppMessage::where('wamid', 'wamid.erasure-moved')->first();

        // Somebody else entirely, with their own WhatsApp channel row.
        $other = new \App\Customer();
        $other->first_name = 'Somebody else';
        $other->save();
        $other->addChannel(WhatsAppAccount::CHANNEL, '+34611000012');

        // The conversation is moved onto them, as a merge or a customer
        // change would do, and then the first person is erased.
        $conversation = \App\Conversation::find($inbound->conversation_id);
        $conversation->customer_id = $other->id;
        $conversation->save();

        PersonalDataErasure::erase('+34611000011');

        $this->assertFalse(
            \Modules\MetaWhatsApp\Support\OutboundGuard::stillHoldsPhone('+34611000011'),
            'the erased number is still treated as reachable because somebody else has a route'
        );
        // And the other person is untouched: the guard must not have become
        // a blanket refusal.
        $this->assertTrue(
            \Modules\MetaWhatsApp\Support\OutboundGuard::stillHoldsPhone('+34611000012')
        );
    }

    /**
     * A retry of a message that already went out must stop on
     * idempotency, not on the erasure guard: writing "nothing was sent"
     * about a message the customer received would be a false statement in
     * their conversation.
     */
    public function test_a_retry_of_an_already_sent_message_does_not_claim_it_was_refused()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.erasure-retry', '34611000014', 'hola'));
        $inbound = WhatsAppMessage::where('wamid', 'wamid.erasure-retry')->first();
        $conversation = \App\Conversation::find($inbound->conversation_id);

        $thread = new \App\Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type            = \App\Thread::TYPE_MESSAGE;
        $thread->status          = $conversation->status;
        $thread->state           = \App\Thread::STATE_PUBLISHED;
        $thread->body            = 'reply that already went out';
        $thread->source_via      = \App\Thread::PERSON_USER;
        $thread->source_type     = \App\Thread::SOURCE_TYPE_WEB;
        $thread->customer_id     = $conversation->customer_id;
        $thread->save();

        // What a successful send leaves behind, before the worker's ack is
        // lost and the job re-runs.
        WhatsAppMessage::create([
            'wamid'           => 'wamid.erasure-retry-sent',
            'account_id'      => $account->id,
            'conversation_id' => $thread->conversation_id,
            'thread_id'       => $thread->id,
            'contact_phone'   => '+34611000014',
            'direction'       => WhatsAppMessage::DIRECTION_OUTBOUND,
            'category'        => WhatsAppMessage::CATEGORY_SERVICE,
            'status'          => WhatsAppMessage::STATUS_SENT,
        ]);

        $job = new \Modules\MetaWhatsApp\Jobs\SendWhatsAppMessage(
            $account->id, $thread->id, '+34611000014'
        );

        PersonalDataErasure::erase('+34611000014');

        // The worker re-runs the job as if the ack never arrived.
        $job->handle();

        $this->assertFalse(
            \App\Thread::where('conversation_id', $thread->conversation_id)
                ->where('type', \App\Thread::TYPE_NOTE)
                ->where('body', 'like', \Modules\MetaWhatsApp\Support\OutboundGuard::NOTE_MARKER . '%')
                ->exists(),
            'a retry of an already-sent message wrote a false "not sent" note'
        );
    }

    /**
     * The inactive-account branch returns before hasContactRoute() is ever
     * reached, straight into recordFailure(). Without its own check, an
     * erasure landing in the same window as an account going inactive (a
     * synchronous 190 deactivates it automatically, and this module has
     * its own UX for exactly that) would still write the erased phone
     * back into a failed-thread-N row, falsifying a receipt already
     * handed over.
     */
    public function test_an_inactive_account_does_not_record_the_erased_phone()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.erasure-inactive', '34611000015', 'hola'));
        $inbound = WhatsAppMessage::where('wamid', 'wamid.erasure-inactive')->first();
        $conversation = \App\Conversation::find($inbound->conversation_id);

        $thread = new \App\Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type            = \App\Thread::TYPE_MESSAGE;
        $thread->status          = $conversation->status;
        $thread->state           = \App\Thread::STATE_PUBLISHED;
        $thread->body            = 'reply queued, then the account went inactive';
        $thread->source_via      = \App\Thread::PERSON_USER;
        $thread->source_type     = \App\Thread::SOURCE_TYPE_WEB;
        $thread->customer_id     = $conversation->customer_id;
        $thread->save();

        // A synchronous 190 deactivates the account automatically; this is
        // the module's own path for that, not a test-only shortcut.
        $account->is_active = false;
        $account->save();

        $job = new \Modules\MetaWhatsApp\Jobs\SendWhatsAppMessage(
            $account->id, $thread->id, '+34611000015'
        );

        PersonalDataErasure::erase('+34611000015');

        $job->handle();

        $this->assertFalse(
            WhatsAppMessage::where('wamid', 'failed-thread-' . $thread->id)->exists(),
            'the inactive-account branch wrote a failed-thread row carrying the erased phone'
        );
        $this->assertFalse(
            WhatsAppMessage::where('contact_phone', '+34611000015')->exists(),
            'the erased phone was written back into the table'
        );
    }

    /**
     * failed() is the callback the worker invokes after the final retry
     * has exhausted $tries, not a path handle() ever walks again. It does
     * its own idempotency check and called recordFailure() directly, with
     * no erasure check at all: a job that kept failing on a persistent
     * 5xx or network error, with an erasure landing around the last
     * attempt, wrote the erased phone back into a failed-thread-N row the
     * same way the inactive-account branch did.
     */
    public function test_a_permanently_failed_job_does_not_record_the_erased_phone()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.erasure-failed', '34611000016', 'hola'));
        $inbound = WhatsAppMessage::where('wamid', 'wamid.erasure-failed')->first();
        $conversation = \App\Conversation::find($inbound->conversation_id);

        $thread = new \App\Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type            = \App\Thread::TYPE_MESSAGE;
        $thread->status          = $conversation->status;
        $thread->state           = \App\Thread::STATE_PUBLISHED;
        $thread->body            = 'reply that kept failing on a transient error';
        $thread->source_via      = \App\Thread::PERSON_USER;
        $thread->source_type     = \App\Thread::SOURCE_TYPE_WEB;
        $thread->customer_id     = $conversation->customer_id;
        $thread->save();

        // The account stays active throughout: this is the retries-
        // exhausted path, not the inactive-account branch above.
        $job = new \Modules\MetaWhatsApp\Jobs\SendWhatsAppMessage(
            $account->id, $thread->id, '+34611000016'
        );

        PersonalDataErasure::erase('+34611000016');

        // What the worker calls once $tries is exhausted on a persistent
        // transient error.
        $job->failed(new \RuntimeException('persistent transient error'));

        $this->assertFalse(
            WhatsAppMessage::where('wamid', 'failed-thread-' . $thread->id)->exists(),
            'failed() wrote a failed-thread row carrying the erased phone'
        );
        $this->assertFalse(
            WhatsAppMessage::where('contact_phone', '+34611000016')->exists(),
            'the erased phone was written back into the table'
        );
    }

    /**
     * The trap the design walked away from: customer.deleting fires on a
     * merge, not on "forget this person". Hooking it would wipe the
     * identity of someone who is still a customer. A real merge, not a
     * hand-fired event, so this also covers the rest of the merge flow.
     */
    public function test_a_real_customer_merge_does_not_erase_anything()
    {
        $account = $this->createTestAccount();
        $row = $this->row($account, ['contact_phone' => '+34611000008', 'contact_user_id' => 'BSUID-MERGE']);

        $keep = new \App\Customer();
        $keep->first_name = 'Keep';
        $keep->save();
        $keep->addChannel(WhatsAppAccount::CHANNEL, '+34611000008');

        $absorb = new \App\Customer();
        $absorb->first_name = 'Absorb';
        $absorb->save();

        $keep->mergeWith($absorb);

        $fresh = $row->fresh();
        $this->assertEquals('+34611000008', $fresh->contact_phone, 'a merge erased the phone');
        $this->assertEquals('BSUID-MERGE', $fresh->contact_user_id, 'a merge erased the BSUID');
        $this->assertTrue(
            \App\CustomerChannel::where('channel', WhatsAppAccount::CHANNEL)
                ->where('channel_id', '+34611000008')->exists(),
            'a merge removed the channel row'
        );
    }
}
