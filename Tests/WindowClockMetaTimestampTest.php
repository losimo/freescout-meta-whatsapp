<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * T10, our finding 1. Both window clocks counted from when this module's
 * queue worker wrote the row, not from the time Meta says the customer
 * wrote the message. Meta sends it in the same payload, in
 * messages[].timestamp, and the module never read it.
 *
 * Any delay between Meta and the row makes the two diverge: a stopped
 * queue, a job burning retries, or Meta redelivering hours after an
 * endpoint came back. The error always goes the same way, and it is the
 * worse one: the module believes the window is more open than it is.
 *
 * The expensive part is not the failed send. It is that the template guard
 * refuses the template, telling the agent the customer's window is open
 * again and to send an ordinary reply. The agent obeys, and the reply dies
 * with a 131047 while the customer receives nothing.
 */
class WindowClockMetaTimestampTest extends TestCase
{
    use DatabaseTransactions;

    protected function runJob(WhatsAppAccount $account, array $payload)
    {
        (new ProcessInboundWebhook($account->id, $payload))->handle();
    }

    /** An inbound payload whose message carries a time of our choosing. */
    protected function payloadAt(WhatsAppAccount $account, string $wamid, $when): array
    {
        $payload = $this->inboundPayload($account, $wamid, '34611222333', 'Hola');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['timestamp'] = (string) $when->timestamp;

        return $payload;
    }

    public function test_the_clock_counts_from_the_time_meta_says_and_not_from_when_we_wrote_the_row()
    {
        $account = $this->createTestAccount();

        $this->runJob($account, $this->payloadAt($account, 'wamid.t10a', now()->subHours(23)));

        $msg       = WhatsAppMessage::where('wamid', 'wamid.t10a')->first();
        $remaining = WhatsAppMessage::realWindowRemainingMinutes($msg->conversation_id);

        $this->assertNotNull($remaining);
        $this->assertGreaterThan(50, $remaining, 'the clock is counting from the row and not from Meta');
        $this->assertLessThan(70, $remaining);
    }

    public function test_a_webhook_that_arrives_late_does_not_report_an_open_window()
    {
        $account = $this->createTestAccount();

        $this->runJob($account, $this->payloadAt($account, 'wamid.t10b', now()->subHours(25)));

        $msg = WhatsAppMessage::where('wamid', 'wamid.t10b')->first();
        $this->assertTrue(
            WhatsAppMessage::windowExpired($msg->conversation_id, $account),
            'a message Meta received 25 hours ago cannot leave an open window'
        );
    }

    /**
     * The door, because this is where the defect costs money: the guard that
     * refuses a template is the one telling the agent to write freely
     * instead.
     */
    public function test_the_template_is_not_refused_for_a_window_meta_has_already_closed()
    {
        $account            = $this->createTestAccount();
        $account->templates = [[
            'id' => 'recover_conversation', 'language' => 'es_ES',
            'display_name' => 'recover_conversation', 'recovery_text' => null,
        ]];
        $account->save();

        $this->runJob($account, $this->payloadAt($account, 'wamid.t10c', now()->subHours(25)));
        $conversation = Conversation::find(
            WhatsAppMessage::where('wamid', 'wamid.t10c')->first()->conversation_id
        );

        Queue::fake();

        $response = $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/conversation/' . $conversation->id . '/send-template'));

        $response->assertStatus(302);
        $response->assertSessionMissing('errors');

        $this->assertNotNull(
            Thread::where('conversation_id', $conversation->id)
                ->where('body', '[WhatsApp template] recover_conversation')
                ->first(),
            'the template was refused for a window that Meta had already closed'
        );
    }

    public function test_a_row_with_no_time_from_meta_still_counts_from_when_it_arrived()
    {
        $account = $this->createTestAccount();

        $this->runJob($account, $this->payloadAt($account, 'wamid.t10d', now()));
        $msg = WhatsAppMessage::where('wamid', 'wamid.t10d')->first();

        // Every row written before this change looks like this one.
        WhatsAppMessage::where('id', $msg->id)->update([
            'meta_sent_at' => null,
            'created_at'   => now()->subHours(23),
        ]);

        $remaining = WhatsAppMessage::realWindowRemainingMinutes($msg->conversation_id);
        $this->assertGreaterThan(50, $remaining, 'the fallback to created_at is what avoids a data migration');
        $this->assertLessThan(70, $remaining);
    }

    /**
     * The value comes from Meta's payload, and a time in the future would
     * hold the window open for as long as it claims. That is the one
     * direction this fix must never make easier.
     */
    public function test_a_time_in_the_future_is_not_trusted()
    {
        $account = $this->createTestAccount();

        $this->runJob($account, $this->payloadAt($account, 'wamid.t10e', now()->addDays(2)));

        $msg = WhatsAppMessage::where('wamid', 'wamid.t10e')->first();
        $this->assertNull($msg->meta_sent_at, 'a timestamp from the future is not stored');

        $remaining = WhatsAppMessage::realWindowRemainingMinutes($msg->conversation_id);
        $this->assertLessThanOrEqual(1440, $remaining, 'the window cannot be longer than 24 hours');
    }

    /**
     * And the other end, which costs money the other way round.
     *
     * The value used to be read with a bare `(int)` cast, so anything that
     * casts to a small number, a `"1"` as much as a `true`, stored 1970 and
     * read as a window that closed half a century ago: the module then
     * offers a paid template while Meta's own 24 hours are running, and the
     * free reply the agent should have written is the one it refuses.
     *
     * The floor is seven days because that is how long Meta retries a
     * delivery our server refused (`webhooks_overview`, corpus of
     * 2026-11-22), so nothing genuine arrives older than that.
     */
    public function test_a_time_from_long_before_any_delivery_is_not_trusted()
    {
        $account = $this->createTestAccount();

        $payload = $this->inboundPayload($account, 'wamid.t10f', '34611222333', 'Hola');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['timestamp'] = '1';
        $this->runJob($account, $payload);

        $msg = WhatsAppMessage::where('wamid', 'wamid.t10f')->first();
        $this->assertNull($msg->meta_sent_at, 'a timestamp from 1970 was stored as when the customer wrote');
        $this->assertFalse(
            WhatsAppMessage::windowExpired($msg->conversation_id, $account),
            'the window was reported closed on a message that had just arrived, so a paid template is offered in place of a free reply'
        );
    }

    /**
     * A genuine timestamp is used however old it is, and the reason is which
     * way each mistake fails.
     *
     * The floor used to be seven days, because that is how long Meta retries
     * a delivery our server refused, so nothing genuine arrives older than
     * that **from Meta**. The queue it arrives into is ours, though: a job
     * that exhausted its retries sits in `failed_jobs` until somebody runs
     * `queue:retry`, and a worker can be stopped over a long break. Then the
     * real timestamp is older than seven days, the floor threw it away,
     * `lastInboundAt()` fell back to `created_at`, which is the moment the
     * row was finally written, and the window read as **open** on a
     * conversation Meta has long closed. The agent writes a free-form reply
     * and Meta refuses it with 131047.
     *
     * Trusting an old timestamp can only close the window, which is the
     * truth here and the safe direction: the agent is offered the template
     * picker. Throwing it away opens a window that is not there, and that
     * failure is silent until somebody reads the note. So the floor now
     * rejects only what cannot be a real time for a WhatsApp message at all,
     * and an old one is believed.
     */
    public function test_a_genuine_timestamp_from_a_backlogged_queue_is_still_used()
    {
        $account = $this->createTestAccount();

        $sentAt  = now()->subDays(9);
        $payload = $this->inboundPayload($account, 'wamid.t10h', '34611222333', 'Hola');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['timestamp'] = (string) $sentAt->timestamp;
        $this->runJob($account, $payload);

        $msg = WhatsAppMessage::where('wamid', 'wamid.t10h')->first();
        $this->assertNotNull($msg, 'the message was not stored at all');
        $this->assertNotNull(
            $msg->meta_sent_at,
            'a real timestamp was thrown away because our own queue was behind, so the window clock'
                . ' now reads from created_at and believes the window is open'
        );
        $this->assertEquals(
            $sentAt->toDateTimeString(),
            $msg->meta_sent_at->toDateTimeString(),
            'the stored time is not the one Meta sent'
        );
        $this->assertTrue(
            WhatsAppMessage::windowExpired($msg->conversation_id, $account),
            'the window was reported open on a message Meta sent nine days ago, so the agent writes'
                . ' a free-form reply and Meta refuses it with 131047'
        );
    }

    /** A value that is not a number at all, in any of its shapes. */
    public function test_a_timestamp_that_is_not_a_number_is_not_trusted()
    {
        $account = $this->createTestAccount();

        foreach ([['x' => 1], true, 'not-a-time', '-120', '1.5', ''] as $i => $garbage) {
            $payload = $this->inboundPayload($account, 'wamid.t10g' . $i, '34611222333', 'Hola');
            $payload['entry'][0]['changes'][0]['value']['messages'][0]['timestamp'] = $garbage;
            $this->runJob($account, $payload);

            $msg = WhatsAppMessage::where('wamid', 'wamid.t10g' . $i)->first();
            $this->assertNotNull($msg, 'the message itself was lost over its timestamp');
            $this->assertNull(
                $msg->meta_sent_at,
                'a timestamp of ' . var_export($garbage, true) . ' was read as a time'
            );
            $this->assertFalse(
                WhatsAppMessage::windowExpired($msg->conversation_id, $account),
                'the window was reported closed on a message that had just arrived'
            );
        }
    }

    /** And a real time still goes in, which is what all of this is for. */
    public function test_a_time_inside_the_range_is_stored()
    {
        $account = $this->createTestAccount();

        $this->runJob($account, $this->payloadAt($account, 'wamid.t10h', now()->subDays(6)));

        $this->assertNotNull(
            WhatsAppMessage::where('wamid', 'wamid.t10h')->first()->meta_sent_at,
            'a redelivery inside the seven days Meta retries for was refused'
        );
    }
}
