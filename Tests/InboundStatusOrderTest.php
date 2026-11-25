<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Support\ServiceUsage;

/**
 * T11. Delivery statuses were applied in the order they arrived, while the
 * timestamps next to them were already guarded against going backwards.
 *
 * Out of order is normal rather than exceptional: Meta does not promise an
 * order, redelivers webhooks, and a queue worker can pick two deliveries up in
 * either sequence. `read` followed by `delivered` left the row reading
 * `delivered`, which is only cosmetic.
 *
 * The case the ticket does not mention is the one that costs money.
 * ServiceUsage::sentThisMonth() counts everything that is not `failed` as a
 * billable service message, and the health panel finds the last failure by the
 * same column. So a late `sent` or `delivered` landing on a `failed` row took
 * the breakage off the panel and turned it into a message counted as paid for.
 * `failed` has to be terminal, and that is not in the ticket either.
 */
class InboundStatusOrderTest extends TestCase
{
    use DatabaseTransactions;

    protected function statusPayload(WhatsAppAccount $account, string $wamid, string $status, array $errors = null, int $at = null): array
    {
        $entry = [
            'id'        => $wamid,
            'status'    => $status,
            // A time of its own when the caller needs one. Every case here
            // used to send time() for both statuses, and the one case about
            // times said in its own comment that the second arrived two
            // hours after the first: the value written was identical, so the
            // rule it meant to pin could be deleted with the test still
            // green. Found by the mutation pass of the 3.0.0 review.
            'timestamp' => (string) ($at ?: time()),
        ];
        if ($errors !== null) {
            $entry['errors'] = $errors;
        }

        return [
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'id'      => $account->waba_id,
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata'          => [
                            'display_phone_number' => ltrim($account->phone_number, '+'),
                            'phone_number_id'      => $account->phone_number_id,
                        ],
                        'statuses' => [$entry],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];
    }

    /**
     * Written half an hour ago, because a delivery status is about a message
     * that already existed and one dated before its own row is refused.
     */
    protected function outboundRow(WhatsAppAccount $account, string $wamid): WhatsAppMessage
    {
        $record = WhatsAppMessage::create([
            'wamid'           => $wamid,
            'account_id'      => $account->id,
            'contact_phone'   => '+34611222333',
            'direction'       => WhatsAppMessage::DIRECTION_OUTBOUND,
            'status'          => WhatsAppMessage::STATUS_SENT,
            'category'        => WhatsAppMessage::CATEGORY_SERVICE,
        ]);

        WhatsAppMessage::where('id', $record->id)->update(['created_at' => now()->subMinutes(30)]);

        return $record->fresh();
    }

    protected function send(WhatsAppAccount $account, string $wamid, string $status, array $errors = null, int $at = null)
    {
        (new ProcessInboundWebhook($account->id, $this->statusPayload($account, $wamid, $status, $errors, $at)))->handle();
    }

    public function test_a_late_delivered_does_not_undo_a_read()
    {
        $account = $this->createTestAccount();
        $this->outboundRow($account, 'wamid.ord1');

        $this->send($account, 'wamid.ord1', 'read');
        $this->send($account, 'wamid.ord1', 'delivered');

        $this->assertEquals(
            WhatsAppMessage::STATUS_READ,
            WhatsAppMessage::where('wamid', 'wamid.ord1')->first()->status
        );
    }

    /**
     * The one that costs money, and the reason `failed` is not simply the top
     * of the ranking but outside it.
     */
    public function test_a_late_delivered_does_not_undo_a_failure()
    {
        $account = $this->createTestAccount();
        $this->outboundRow($account, 'wamid.ord2');

        $this->send($account, 'wamid.ord2', 'failed', [[
            'code' => 131026, 'title' => 'Message undeliverable',
        ]]);
        $this->send($account, 'wamid.ord2', 'delivered');

        $record = WhatsAppMessage::where('wamid', 'wamid.ord2')->first();
        $this->assertEquals(
            WhatsAppMessage::STATUS_FAILED,
            $record->status,
            'a status arriving after a failure was believed over the failure'
        );
        $this->assertEquals(
            0,
            ServiceUsage::sentThisMonth($account),
            'the failed message is being counted as a billable service message'
        );
    }

    /**
     * The other direction is honoured, because here Meta is telling us
     * something went wrong and believing it costs nobody anything: the
     * failure shows on the health panel and the message stops being counted.
     */
    public function test_a_failure_after_a_delivery_is_believed()
    {
        $account = $this->createTestAccount();
        $this->outboundRow($account, 'wamid.ord3');

        $this->send($account, 'wamid.ord3', 'delivered');
        $this->send($account, 'wamid.ord3', 'failed', [[
            'code' => 131026, 'title' => 'Message undeliverable',
        ]]);

        $this->assertEquals(
            WhatsAppMessage::STATUS_FAILED,
            WhatsAppMessage::where('wamid', 'wamid.ord3')->first()->status
        );
    }

    /**
     * Was test_a_refused_status_still_fills_a_timestamp_we_were_missing, and
     * an outside review showed it proved nothing: a `read` already fills
     * `delivered_at`, so the late `delivered` it sent had nothing left to
     * supply, and the test stayed green with the whole refused-status branch
     * returning early.
     *
     * The premise was mine and it was wrong. `read` fills both timestamps and
     * `sent` fills neither, so no order of statuses can leave one for a
     * refused status to fill. What is worth pinning is the opposite property,
     * which is reachable: a late status must not **rewrite** a time already
     * recorded.
     */
    public function test_a_late_status_does_not_rewrite_a_time_already_recorded()
    {
        $account = $this->createTestAccount();
        $this->outboundRow($account, 'wamid.ord4');

        $readAt = now()->subMinutes(20)->timestamp;
        $this->send($account, 'wamid.ord4', 'read', null, $readAt);

        $first = WhatsAppMessage::where('wamid', 'wamid.ord4')->first();
        $this->assertEquals($readAt, $first->read_at->timestamp);
        $this->assertEquals($readAt, $first->delivered_at->timestamp, 'a read fills both times');

        // A different time, which is what makes this case worth anything:
        // with both statuses sending the same one, the value rewritten was
        // identical to the value already there.
        $this->send($account, 'wamid.ord4', 'delivered', null, now()->subMinutes(2)->timestamp);

        $this->assertEquals(
            $readAt,
            WhatsAppMessage::where('wamid', 'wamid.ord4')->first()->delivered_at->timestamp,
            'a late status rewrote a time that was already recorded'
        );
    }

    /**
     * Nothing at all is written onto a failed row, not even a timestamp: once
     * we have decided not to believe a later status, believing half of it
     * would leave a row that reads "failed, and delivered at this time".
     */
    public function test_nothing_is_written_onto_a_failed_row()
    {
        $account = $this->createTestAccount();
        $this->outboundRow($account, 'wamid.ord5');

        $this->send($account, 'wamid.ord5', 'failed', [[
            'code' => 131026, 'title' => 'Message undeliverable',
        ]]);
        $this->send($account, 'wamid.ord5', 'read');

        $record = WhatsAppMessage::where('wamid', 'wamid.ord5')->first();
        $this->assertEquals(WhatsAppMessage::STATUS_FAILED, $record->status);
        $this->assertNull($record->read_at, 'a read time was recorded on a message that never arrived');
    }

    /** The ordinary forward path still works, which is what this could break. */
    public function test_the_forward_path_still_moves()
    {
        $account = $this->createTestAccount();
        $this->outboundRow($account, 'wamid.ord6');

        $this->send($account, 'wamid.ord6', 'delivered');
        $this->assertEquals(
            WhatsAppMessage::STATUS_DELIVERED,
            WhatsAppMessage::where('wamid', 'wamid.ord6')->first()->status
        );

        $this->send($account, 'wamid.ord6', 'read');
        $this->assertEquals(
            WhatsAppMessage::STATUS_READ,
            WhatsAppMessage::where('wamid', 'wamid.ord6')->first()->status
        );
    }
}
