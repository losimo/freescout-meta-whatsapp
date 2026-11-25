<?php

namespace Modules\MetaWhatsApp\Tests;

use App\ActivityLog;
use App\Conversation;
use App\Customer;
use App\CustomerChannel;
use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Support\IdentityChangeAudit;

/**
 * Issue #60, after the reporter's note of 2026-11-24: there may be no
 * conversation to write the note in, and they need these changes recorded
 * somewhere they can audit.
 *
 * The note was the only trace, and three cases leave it unwritten: no module
 * message row carries a conversation id, every candidate is filtered out as
 * deleted or spam, or the move is refused before a note would be reached. The
 * record now goes to FreeScout's own activity log, which survives all three.
 *
 * With no numbers in it. The module's erasure screen does not reach the
 * activity log, so a number written there would outlive an erasure request
 * made afterwards.
 */
class IdentityChangeAuditTest extends TestCase
{
    use DatabaseTransactions;

    protected function runJob(WhatsAppAccount $account, array $payload)
    {
        (new ProcessInboundWebhook($account->id, $payload))->handle();
    }

    protected function numberChange(WhatsAppAccount $account, string $wamid, string $old, string $new): array
    {
        $message = [
            'from'      => $old,
            'id'        => $wamid,
            'timestamp' => (string) time(),
            'type'      => 'system',
            'system'    => [
                'body'  => 'User changed their number',
                'wa_id' => $new,
                'type'  => 'user_changed_number',
            ],
        ];

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
                        'messages' => [$message],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];
    }

    protected function auditRows()
    {
        return ActivityLog::where('log_name', IdentityChangeAudit::LOG_NAME)
            ->orderBy('id')
            ->get();
    }

    /**
     * The case the reporter named: the conversation is gone, so the note has
     * nowhere to go and the change used to leave nothing an administrator
     * could find.
     */
    public function test_a_change_with_no_conversation_left_is_still_recorded()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.aud1', '34611222333', 'Hola'));

        $record = WhatsAppMessage::where('wamid', 'wamid.aud1')->first();
        $customerId = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333')->id;

        // The conversation is deleted, as it would be after a clean-up.
        $conversation = Conversation::find($record->conversation_id);
        $conversation->state = Conversation::STATE_DELETED;
        $conversation->save();
        $threadsBefore = Thread::where('conversation_id', $conversation->id)->count();

        $this->runJob($account, $this->numberChange($account, 'wamid.sys-aud1', '34611222333', '34655444555'));

        $rows = $this->auditRows();
        $this->assertCount(1, $rows, 'the change left no auditable record');

        $row = $rows->first();
        $this->assertEquals($customerId, (int) $row->causer_id, 'the record does not name the customer');
        $this->assertEquals('App\Customer', $row->causer_type);
        $this->assertEquals('phone number', $row->properties['identifier']);
        $this->assertEquals(IdentityChangeAudit::OUTCOME_APPLIED, $row->properties['outcome']);

        $this->assertEquals(
            $threadsBefore,
            Thread::where('conversation_id', $conversation->id)->count(),
            'a note was written into a deleted conversation'
        );
    }

    /** Neither number may appear anywhere in the row. */
    public function test_the_record_carries_no_phone_number()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.aud2', '34611222333', 'Hola'));
        $this->runJob($account, $this->numberChange($account, 'wamid.sys-aud2', '34611222333', '34655444555'));

        $row  = $this->auditRows()->first();
        $this->assertNotNull($row);
        $dump = json_encode([$row->description, $row->properties]);

        $this->assertStringNotContainsString('611222333', $dump, 'the old number is in the audit row');
        $this->assertStringNotContainsString('655444555', $dump, 'the new number is in the audit row');
    }

    /**
     * The refusal that needs a person rather than a log file: two customers
     * are never merged by a webhook, so somebody has to do it by hand, and
     * until this they had no way of knowing.
     */
    public function test_a_refusal_because_the_number_is_somebody_elses_is_recorded()
    {
        $account = $this->createTestAccount();

        $this->runJob($account, $this->inboundPayload($account, 'wamid.aud3a', '34611222333', 'Sóc el primer'));
        $this->runJob($account, $this->inboundPayload($account, 'wamid.aud3b', '34655444555', 'Sóc el segon'));
        $first = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');

        $this->runJob($account, $this->numberChange($account, 'wamid.sys-aud3', '34611222333', '34655444555'));

        $rows = $this->auditRows();
        $this->assertCount(1, $rows, 'the refusal left no auditable record');
        $this->assertEquals($first->id, (int) $rows->first()->causer_id);
        $this->assertEquals(
            IdentityChangeAudit::OUTCOME_ANOTHER_CUSTOMER,
            $rows->first()->properties['outcome']
        );

        // And the refusal itself still holds.
        $this->assertNotNull(
            Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333'),
            'the old link was removed despite the refusal'
        );
    }

    /**
     * An identifier the core's column cannot hold leaves the contact pointing
     * at an id that will never arrive again, which is the one an
     * administrator has to repair by hand.
     */
    public function test_a_refusal_because_the_identifier_is_too_long_is_recorded()
    {
        $account = $this->createTestAccount();
        $bsuid   = 'US.' . str_repeat('1', 40);
        $tooLong = 'US.' . str_repeat('2', 70);

        $payload = $this->inboundPayload($account, 'wamid.aud4', '34611222333', 'Hola', [[
            'profile' => ['name' => 'Pau'],
            'wa_id'   => '34611222333',
            'user_id' => $bsuid,
        ]]);
        $this->runJob($account, $payload);
        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $bsuid);
        $this->assertNotNull($customer, 'the business-scoped id was not learned, so this test proves nothing');

        $message = [
            'from'      => '34611222333',
            'id'        => 'wamid.sys-aud4',
            'timestamp' => (string) time(),
            'type'      => 'system',
            'system'    => [
                'type'             => 'user_changed_user_id',
                'previous_user_id' => $bsuid,
                'user_id'          => $tooLong,
            ],
        ];
        $this->runJob($account, [
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
                        'messages' => [$message],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ]);

        $rows = $this->auditRows();
        $this->assertCount(1, $rows);
        $this->assertEquals('business-scoped user id', $rows->first()->properties['identifier']);
        $this->assertEquals(
            IdentityChangeAudit::OUTCOME_TOO_LONG,
            $rows->first()->properties['outcome']
        );
        $this->assertEquals($customer->id, (int) $rows->first()->causer_id);
    }

    /**
     * An announcement about somebody who is not a contact of ours is not an
     * audit event: there is no person to name and nothing happened.
     */
    public function test_an_announcement_about_a_stranger_writes_no_record()
    {
        $account = $this->createTestAccount();

        $this->runJob($account, $this->numberChange($account, 'wamid.sys-aud5', '34699888777', '34655444555'));

        $this->assertCount(0, $this->auditRows());
    }

    /** The note is still written where there is a conversation to write it in. */
    public function test_the_conversation_note_is_still_written()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.aud6', '34611222333', 'Hola'));
        $conversationId = WhatsAppMessage::where('wamid', 'wamid.aud6')->first()->conversation_id;
        $notesBefore = Thread::where('conversation_id', $conversationId)
            ->where('type', Thread::TYPE_NOTE)->count();

        $this->runJob($account, $this->numberChange($account, 'wamid.sys-aud6', '34611222333', '34655444555'));

        $this->assertEquals(
            $notesBefore + 1,
            Thread::where('conversation_id', $conversationId)->where('type', Thread::TYPE_NOTE)->count()
        );
        $this->assertCount(1, $this->auditRows(), 'the audit row is written as well as the note, not instead of it');
    }

    /**
     * The payload Meta actually documents for a number change, which carries
     * the business-scoped id pair as well, because a number change
     * regenerates the id. Found in the second review round: moveIdentity()
     * runs twice for that announcement, so it leaves **two** rows, one per
     * identifier.
     *
     * The helper above omits that pair, which is why every assertCount(1) in
     * this file passed and nothing caught it. That is the hand-written fixture
     * problem in one line: the tests agree with what we believe Meta sends.
     */
    public function test_a_number_change_records_one_row_per_identifier_that_moved()
    {
        $account = $this->createTestAccount();
        $bsuid   = 'US.1349120865530274';
        $newId   = 'US.9876543210987654';

        $this->runJob($account, $this->inboundPayload($account, 'wamid.aud7', '34611222333', 'Hola', [[
            'profile' => ['name' => 'Pau'],
            'wa_id'   => '34611222333',
            'user_id' => $bsuid,
        ]]));
        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $this->assertNotNull(Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $bsuid));

        $message = [
            'from'      => '34611222333',
            'id'        => 'wamid.sys-aud7',
            'timestamp' => (string) time(),
            'type'      => 'system',
            'system'    => [
                'body'             => 'User changed their number',
                'wa_id'            => '34655444555',
                'type'             => 'user_changed_number',
                'previous_user_id' => $bsuid,
                'user_id'          => $newId,
            ],
        ];
        $this->runJob($account, [
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
                        'messages' => [$message],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ]);

        $rows = $this->auditRows();
        $this->assertCount(2, $rows, 'one row per identifier that moved');

        $identifiers = $rows->pluck('properties')->map(function ($properties) {
            return $properties['identifier'];
        })->all();
        $this->assertEquals(['phone number', 'business-scoped user id'], $identifiers);

        foreach ($rows as $row) {
            $this->assertEquals($customer->id, (int) $row->causer_id);
            $this->assertEquals(IdentityChangeAudit::OUTCOME_APPLIED, $row->properties['outcome']);
        }
    }

    /**
     * The identity change reads the same payload as everything else, so a
     * malformed field in it must not take the delivery down either: this one
     * moves channel rows between customers, which is the costliest thing in
     * the file to get wrong.
     */
    public function test_a_malformed_system_field_does_not_take_the_delivery_down()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.aud8', '34611222333', 'Hola'));

        $broken = [
            'from'      => '34611222333',
            'id'        => 'wamid.sys-aud8',
            'timestamp' => (string) time(),
            'type'      => 'system',
            'system'    => [
                'type'             => 'user_changed_user_id',
                'previous_user_id' => ['US.1'],
                'user_id'          => ['US.2'],
            ],
        ];
        $healthy = [
            'from'      => '34611222333',
            'id'        => 'wamid.after-sys',
            'timestamp' => (string) time(),
            'type'      => 'text',
            'text'      => ['body' => 'Encara sóc aquí'],
        ];

        $this->runJob($account, [
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
                        'messages' => [$broken, $healthy],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ]);

        $this->assertNotNull(
            WhatsAppMessage::where('wamid', 'wamid.after-sys')->first(),
            'a malformed system field lost the message that followed it'
        );
        $this->assertCount(0, $this->auditRows(), 'nothing moved, so there is nothing to audit');
    }

    /** The log name has to be offered in the alerts picker, or nobody finds it. */
    public function test_the_log_name_is_offered_to_the_administrator()
    {
        $this->assertContains(
            IdentityChangeAudit::LOG_NAME,
            ActivityLog::getAvailableLogs(),
            'the module log is not in the list an administrator can pick from'
        );
    }
}
