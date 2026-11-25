<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Conversation;
use App\Customer;
use App\CustomerChannel;
use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * Issue #60. Meta announces an identity change with a system message, and the
 * module threw the announcement away: `system` was not on the allowlist of
 * message types, so the event was discarded and written to the log as an
 * error.
 *
 * The cost is the duplicate of #59 arriving from the other direction. The next
 * message comes from an identifier we have never seen, so it creates a new
 * customer and a new conversation while the history stays behind. Worse, the
 * old conversation keeps the old number as its reply recipient, so an agent
 * answering there sends to a number that is now dead or somebody else's.
 */
class IdentityChangeTest extends TestCase
{
    use DatabaseTransactions;

    protected function runJob(WhatsAppAccount $account, array $payload)
    {
        (new ProcessInboundWebhook($account->id, $payload))->handle();
    }

    /**
     * A system message as Meta documents it: no contacts array, and the
     * identifier they were known by in `from`.
     */
    protected function systemPayload(WhatsAppAccount $account, string $wamid, ?string $from, array $system): array
    {
        $message = [
            'id'        => $wamid,
            'timestamp' => (string) time(),
            'type'      => 'system',
            'system'    => $system,
        ];
        if ($from !== null) {
            $message['from'] = $from;
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
                        'messages' => [$message],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];
    }

    protected function numberChange(WhatsAppAccount $account, string $wamid, string $old, string $new): array
    {
        return $this->systemPayload($account, $wamid, $old, [
            'body'  => 'User Pau Ferrer changed from ' . $old . ' to ' . $new,
            'wa_id' => $new,
            'type'  => 'user_changed_number',
        ]);
    }

    public function test_a_number_change_moves_the_channel_link_to_the_new_number()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.id1', '34611222333', 'Hola'));
        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');

        $this->runJob($account, $this->numberChange($account, 'wamid.sys1', '34611222333', '34655444555'));

        $this->assertNull(
            Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333'),
            'the old number must stop resolving to anybody'
        );
        $moved = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34655444555');
        $this->assertNotNull($moved, 'the new number resolves to nobody');
        $this->assertEquals($customer->id, $moved->id);
    }

    /**
     * The reply recipient is the conversation's last inbound number, so moving
     * the channel link alone would leave this conversation answering to a
     * number its owner has given up.
     */
    public function test_a_number_change_moves_this_persons_messages_so_replies_go_to_the_new_number()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.id2', '34611222333', 'Hola'));
        $conversationId = WhatsAppMessage::where('wamid', 'wamid.id2')->first()->conversation_id;

        $this->runJob($account, $this->numberChange($account, 'wamid.sys2', '34611222333', '34655444555'));

        $this->assertEquals(
            '+34655444555',
            WhatsAppMessage::where('wamid', 'wamid.id2')->first()->contact_phone
        );

        // And the next message, which arrives from the new number, continues
        // the same conversation instead of opening a second one.
        $this->runJob($account, $this->inboundPayload($account, 'wamid.id3', '34655444555', 'Segon'));
        $this->assertEquals(
            $conversationId,
            WhatsAppMessage::where('wamid', 'wamid.id3')->first()->conversation_id
        );
    }

    public function test_a_number_change_leaves_a_note_that_does_not_print_either_number()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.id4', '34611222333', 'Hola'));
        $conversationId = WhatsAppMessage::where('wamid', 'wamid.id4')->first()->conversation_id;

        $this->runJob($account, $this->numberChange($account, 'wamid.sys4', '34611222333', '34655444555'));

        $note = Thread::where('conversation_id', $conversationId)
            ->where('type', Thread::TYPE_NOTE)
            ->first();

        $this->assertNotNull($note, 'the agent has no way of knowing the number changed');
        $this->assertStringNotContainsString('611222333', $note->body, 'the old number must not be written into a note body');
        $this->assertStringNotContainsString('655444555', $note->body, 'the new number is on the channel, not in a note');
        // Issue #55: this note concerns no agent, and an authorless note is
        // what takes the core's print view down. See NoteAuthorTest.
        $this->assertNotNull(
            $note->created_by_user_id,
            'the note has no author, so printing this conversation fails in the core'
        );
    }

    /**
     * Two people cannot be merged by a webhook. If the new number already
     * belongs to somebody else, the module leaves both records alone.
     */
    public function test_a_number_change_to_a_number_someone_else_holds_changes_nothing()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.id5', '34611222333', 'Hola'));
        $this->runJob($account, $this->inboundPayload($account, 'wamid.id6', '34655444555', 'Jo soc un altre'));

        $first  = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $second = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34655444555');

        $this->runJob($account, $this->numberChange($account, 'wamid.sys5', '34611222333', '34655444555'));

        $this->assertEquals(
            $first->id,
            Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333')->id,
            'the old link was moved onto a number that was not free'
        );
        $this->assertEquals(
            $second->id,
            Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34655444555')->id
        );
    }

    public function test_a_business_scoped_id_change_moves_the_link_and_the_rows()
    {
        $account = $this->createTestAccount();
        $oldBsuid = 'US.13491208655302741918';
        $newBsuid = 'US.99991208655302741918';

        $this->runJob($account, $this->inboundPayload($account, 'wamid.id7', $oldBsuid, 'Hola', [[
            'profile' => ['name' => 'Pau Ferrer'],
            'user_id' => $oldBsuid,
        ]]));
        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $oldBsuid);
        $this->assertNotNull($customer);

        $this->runJob($account, $this->systemPayload($account, 'wamid.sys7', null, [
            'body'             => 'User Pau Ferrer changed their WhatsApp ID',
            'type'             => 'user_changed_user_id',
            'user_id'          => $newBsuid,
            'previous_user_id' => $oldBsuid,
        ]));

        $this->assertNull(Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $oldBsuid));
        $moved = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $newBsuid);
        $this->assertNotNull($moved);
        $this->assertEquals($customer->id, $moved->id);
        $this->assertEquals(
            $newBsuid,
            WhatsAppMessage::where('wamid', 'wamid.id7')->first()->contact_user_id
        );
    }

    /**
     * An event Meta sends on purpose is not a failure, and the log is where an
     * administrator looks for failures.
     */
    public function test_a_system_message_is_not_logged_as_a_failure()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.id8', '34611222333', 'Hola'));

        \Log::spy();
        $this->runJob($account, $this->numberChange($account, 'wamid.sys8', '34611222333', '34655444555'));

        \Log::shouldNotHaveReceived('error');
    }

    /**
     * The window clock reads inbound rows. A system message is not a message
     * from the customer, so storing one would hand them a fresh 24 hours they
     * never earned.
     */
    public function test_a_system_message_leaves_no_row_of_its_own()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.id9', '34611222333', 'Hola'));

        $this->runJob($account, $this->numberChange($account, 'wamid.sys9', '34611222333', '34655444555'));

        $this->assertNull(WhatsAppMessage::where('wamid', 'wamid.sys9')->first());
    }

    /**
     * Since #59 one customer can hold two WhatsApp numbers, each with its own
     * conversation. The note belongs in the one that moved: written into the
     * other number's thread it would be false, and the thread that actually
     * changed would get no word at all.
     */
    public function test_the_note_lands_on_the_conversation_that_held_the_old_number()
    {
        $account  = $this->createTestAccount();
        $existing = new Customer();
        $existing->first_name = 'Two';
        $existing->last_name  = 'Numbers';
        $existing->setPhones([
            ['value' => '+34611222333', 'type' => Customer::PHONE_TYPE_MOBILE],
            ['value' => '+34655444555', 'type' => Customer::PHONE_TYPE_MOBILE],
        ]);
        $existing->save();

        // The older conversation is the one whose number will change.
        $this->runJob($account, $this->inboundPayload($account, 'wamid.id12', '34611222333', 'Primer'));
        $oldNumbersConversation = WhatsAppMessage::where('wamid', 'wamid.id12')->first()->conversation_id;

        $this->runJob($account, $this->inboundPayload($account, 'wamid.id13', '34655444555', 'Segon'));
        $otherConversation = WhatsAppMessage::where('wamid', 'wamid.id13')->first()->conversation_id;
        $this->assertNotEquals($oldNumbersConversation, $otherConversation);

        // Set explicitly, because both rows are created inside the same second
        // and the tie is MySQL's to break: without this the defect this test
        // describes is a coin toss, and the test passed against the broken
        // code once already.
        $other             = Conversation::find($otherConversation);
        $other->created_at = Conversation::find($oldNumbersConversation)->created_at->copy()->addMinute();
        $other->save();

        $this->runJob($account, $this->numberChange($account, 'wamid.sys13', '34611222333', '34700999888'));

        $this->assertEquals(
            1,
            Thread::where('conversation_id', $oldNumbersConversation)->where('type', Thread::TYPE_NOTE)->count(),
            'the conversation whose number changed got no note'
        );
        $this->assertEquals(
            0,
            Thread::where('conversation_id', $otherConversation)->where('type', Thread::TYPE_NOTE)->count(),
            'the note was written into the thread of the number that did not change'
        );
    }

    /**
     * Meta's own words, in the business-scoped user IDs reference as of
     * 2026-11-22: "A phone number change also regenerates the BSUID, so the
     * previous values are now reported on both system message types."
     */
    public function test_a_number_change_moves_the_business_scoped_id_too()
    {
        $account  = $this->createTestAccount();
        $oldBsuid = 'US.13491208655302741918';
        $newBsuid = 'US.77791208655302741918';

        $this->runJob($account, $this->inboundPayload($account, 'wamid.id14', '34611222333', 'Hola', [[
            'wa_id'   => '34611222333',
            'user_id' => $oldBsuid,
        ]]));
        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $this->assertNotNull(Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $oldBsuid));

        $this->runJob($account, $this->systemPayload($account, 'wamid.sys14', '34611222333', [
            'body'             => 'User changed from 34611222333 to 34700999888',
            'wa_id'            => '34700999888',
            'type'             => 'user_changed_number',
            'user_id'          => $newBsuid,
            'previous_user_id' => $oldBsuid,
        ]));

        $this->assertNull(
            Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $oldBsuid),
            'the old business-scoped id will never arrive again and must not stay linked'
        );
        $moved = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $newBsuid);
        $this->assertNotNull($moved);
        $this->assertEquals($customer->id, $moved->id);
        $this->assertEquals(
            $newBsuid,
            WhatsAppMessage::where('wamid', 'wamid.id14')->first()->contact_user_id
        );
    }

    /**
     * customer_channel.channel_id is varchar(64) in the core and Meta's ids
     * run longer. The module guards for it when learning one, and has to
     * guard here too: strict MySQL would fail the job and a lenient one would
     * truncate in silence, leaving a link no message can ever match.
     */
    public function test_a_business_scoped_id_too_long_for_the_column_changes_nothing()
    {
        $account  = $this->createTestAccount();
        $oldBsuid = 'US.13491208655302741918';
        $newBsuid = 'US.' . str_repeat('9', 70);

        $this->runJob($account, $this->inboundPayload($account, 'wamid.id15', $oldBsuid, 'Hola', [[
            'profile' => ['name' => 'Pau Ferrer'],
            'user_id' => $oldBsuid,
        ]]));

        $this->runJob($account, $this->systemPayload($account, 'wamid.sys15', null, [
            'body'             => 'User changed their WhatsApp ID',
            'type'             => 'user_changed_user_id',
            'user_id'          => $newBsuid,
            'previous_user_id' => $oldBsuid,
        ]));

        $this->assertNotNull(
            Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $oldBsuid),
            'nothing should have moved, so the old link is still the good one'
        );
        $this->assertEquals(
            $oldBsuid,
            WhatsAppMessage::where('wamid', 'wamid.id15')->first()->contact_user_id
        );
    }

    /**
     * The business-scoped ids in a system message go through the same
     * validation as every other place one arrives from.
     *
     * They did not: the phone side was normalised and these two were written
     * to `customer_channel` exactly as they came. A value with a space in it
     * went into the row, and then `sanitisedUserId()` refused that same
     * value on the next message, so the contact arrived with no identifier
     * at all and the message was dropped. Issue #60 exists to stop a message
     * being lost to an identity change, so letting it in through this door
     * undid the issue it was closing.
     */
    public function test_a_malformed_business_scoped_id_is_refused_rather_than_written()
    {
        $account  = $this->createTestAccount();
        $oldBsuid = 'US.13491208655302741918';
        $newBsuid = 'US.9999 1208655302741918'; // A space, which Meta's format has no room for.

        $this->runJob($account, $this->inboundPayload($account, 'wamid.id19', $oldBsuid, 'Hola', [[
            'user_id' => $oldBsuid,
        ]]));

        $this->runJob($account, $this->systemPayload($account, 'wamid.sys19', null, [
            'body'             => 'User changed their WhatsApp ID',
            'type'             => 'user_changed_user_id',
            'user_id'          => $newBsuid,
            'previous_user_id' => $oldBsuid,
        ]));

        $this->assertNull(
            Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $newBsuid),
            'an identifier the module would refuse on the next message was written to the channel row'
        );
        $this->assertNotNull(
            Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $oldBsuid),
            'the only link that still resolves was given away for one that does not'
        );
        $this->assertEquals(
            $oldBsuid,
            WhatsAppMessage::where('wamid', 'wamid.id19')->first()->contact_user_id
        );
    }

    /** The same door, and the same validation, on the phone-change message. */
    public function test_a_malformed_business_scoped_id_on_a_number_change_is_refused_too()
    {
        $account  = $this->createTestAccount();
        $oldBsuid = 'US.13491208655302741918';

        $this->runJob($account, $this->inboundPayload($account, 'wamid.id20', '34611222333', 'Hola', [[
            'wa_id'   => '34611222333',
            'user_id' => $oldBsuid,
        ]]));

        $payload = $this->systemPayload($account, 'wamid.sys20', '34611222333', [
            'body'             => 'User changed from 34611222333 to 34655444555',
            'wa_id'            => '34655444555',
            'type'             => 'user_changed_number',
            'previous_user_id' => $oldBsuid,
            'user_id'          => "US.1234\x01",
        ]);
        $this->runJob($account, $payload);

        $this->assertNotNull(
            Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $oldBsuid),
            'the business-scoped link was moved onto a value the module refuses to read'
        );
        // The phone half of the same message is unaffected: one malformed
        // field does not cost the person the move that was good.
        $this->assertNotNull(
            Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34655444555'),
            'the phone move was lost because of the business-scoped id next to it'
        );
    }

    /**
     * customer_channel is instance-wide, so after the move every mailbox
     * resolves the new number to this person. Rows left behind on another
     * WhatsApp channel would keep that mailbox replying to the abandoned
     * number.
     */
    public function test_the_rows_of_another_whatsapp_channel_move_as_well()
    {
        $first  = $this->createTestAccount();
        $second = $this->createTestAccount(['waba_id' => 'test-waba-2']);

        $this->runJob($first, $this->inboundPayload($first, 'wamid.id16', '34611222333', 'Al primer canal'));
        $this->runJob($second, $this->inboundPayload($second, 'wamid.id17', '34611222333', 'I al segon'));

        $this->runJob($first, $this->numberChange($first, 'wamid.sys16', '34611222333', '34700999888'));

        $this->assertEquals(
            '+34700999888',
            WhatsAppMessage::where('wamid', 'wamid.id17')->first()->contact_phone,
            'the other channel would keep answering to the abandoned number'
        );
    }

    /**
     * A half-applied move cannot be recovered by a retry: the channel row
     * would already point at the new identifier, so the retry finds nothing to
     * move and says so as if all were well.
     */
    public function test_a_failure_half_way_leaves_nothing_moved()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.id18', '34611222333', 'Hola'));

        $payload = $this->numberChange($account, 'wamid.sys18', '34611222333', '34700999888');
        $job     = new class($account->id, $payload) extends ProcessInboundWebhook {
            protected function noteNumberChange(array $conversationIds)
            {
                throw new \RuntimeException('as if the note failed to save');
            }
        };

        try {
            $job->handle();
            $this->fail('the exception should have come back out');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNotNull(
            Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333'),
            'the channel row moved while the rest of the move was rolled back'
        );
        $this->assertEquals(
            '+34611222333',
            WhatsAppMessage::where('wamid', 'wamid.id18')->first()->contact_phone
        );
    }

    public function test_a_number_change_for_somebody_we_have_never_seen_does_nothing()
    {
        $account = $this->createTestAccount();
        $before  = WhatsAppMessage::count();

        $this->runJob($account, $this->numberChange($account, 'wamid.sys10', '34699888777', '34655444555'));

        $this->assertEquals($before, WhatsAppMessage::count());
        $this->assertNull(Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34655444555'));
    }

    /**
     * The customer record's phone list follows the number that changed.
     *
     * This asserted the opposite until the whole-branch review of the 3.0.0
     * branch, under the rule that a webhook does not edit an
     * administrator's data: the old number stayed on the record. The rule
     * was right and the conclusion was wrong, and the case below says why.
     */
    public function test_a_number_change_replaces_the_old_number_on_the_record()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.id11', '34611222333', 'Hola'));
        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');

        $this->runJob($account, $this->numberChange($account, 'wamid.sys11', '34611222333', '34655444555'));

        $phones = array_column(Customer::find($customer->id)->getPhones(), 'value');
        $this->assertContains('+34655444555', $phones, 'the number the person moved to is not on the record');
        $this->assertNotContains(
            '+34611222333',
            $phones,
            'the number its owner gave up is still on the record, where an agent reads it and calls nobody'
        );
    }

    /**
     * The damage that rule did, and the reason the test above changed sides.
     *
     * An operator reassigns a released number after a few months. With the
     * old number still on the record, `Customer::byPhone()` goes on finding
     * this person, so the new owner's first message is filed in a stranger's
     * record, under a stranger's name, in a conversation with a stranger's
     * history. It is the promise of issue #60 read from the other side: a
     * reply must not reach a number its owner has left, and a message from
     * that number must not reach its previous owner's file.
     */
    public function test_a_reassigned_number_does_not_land_on_its_previous_owner()
    {
        $account = $this->createTestAccount();
        $this->runJob($account, $this->inboundPayload($account, 'wamid.id16', '34611222333', 'Hola'));
        $first = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');

        $this->runJob($account, $this->numberChange($account, 'wamid.sys16', '34611222333', '34655444555'));

        // Months later, somebody else is given the number and writes in.
        $this->runJob($account, $this->inboundPayload($account, 'wamid.id17', '34611222333', 'Bona tarda'));

        $newOwner = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $this->assertNotNull($newOwner, 'the new owner of the number was not filed anywhere');
        $this->assertNotEquals(
            $first->id,
            $newOwner->id,
            'the message from the reassigned number was filed under its previous owner'
        );
    }

    /** Every other number on the record is somebody's work, and stays. */
    public function test_a_number_change_leaves_the_records_other_numbers_alone()
    {
        $account  = $this->createTestAccount();
        $customer = new Customer();
        $customer->first_name = 'Two numbers';
        $customer->setPhones([
            ['value' => '+34930000000', 'type' => Customer::PHONE_TYPE_WORK],
            ['value' => '+34611222333', 'type' => Customer::PHONE_TYPE_MOBILE],
        ]);
        $customer->save();
        $customer->addChannel(WhatsAppAccount::CHANNEL, '+34611222333');

        $this->runJob($account, $this->numberChange($account, 'wamid.sys17', '34611222333', '34655444555'));

        $phones = array_column(Customer::find($customer->id)->getPhones(), 'value');
        $this->assertContains('+34930000000', $phones, 'a number this change says nothing about was rewritten');
        $this->assertContains('+34655444555', $phones);
        $this->assertNotContains('+34611222333', $phones);
    }

    /** And a record with no phone at all gets the new one. */
    public function test_a_record_with_no_phone_at_all_gets_the_new_number()
    {
        $account = $this->createTestAccount();

        $empty = new Customer();
        $empty->first_name = 'No phone';
        $empty->save();
        $empty->addChannel(WhatsAppAccount::CHANNEL, '+34700111222');

        $this->runJob($account, $this->numberChange($account, 'wamid.sys12', '34700111222', '34700999888'));

        $phones = array_column(Customer::find($empty->id)->getPhones(), 'value');
        $this->assertContains('+34700999888', $phones);
    }

    /**
     * The number written with the ITU international prefix is the one
     * candidate the match forgives, and nothing fed it until the mutation
     * pass of the 3.0.0 review: `searchCandidates()` could be replaced with
     * `if (false)` and the whole suite stayed green. It is one of the five
     * things promised to MarkoD on issue #59, so it gets a case of its own
     * at both ends, the way in and the way out.
     */
    public function test_a_number_stored_with_the_international_prefix_is_the_same_number()
    {
        $account  = $this->createTestAccount();
        $customer = new Customer();
        $customer->first_name = 'Prefix';
        $customer->setPhones([
            ['value' => '0034611222333', 'type' => Customer::PHONE_TYPE_MOBILE],
        ]);
        $customer->save();

        // The way in: an inbound message finds the record rather than making
        // a second one, which is what issue #59 reported.
        $this->runJob($account, $this->inboundPayload($account, 'wamid.id18', '34611222333', 'Hola'));
        $matched = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $this->assertNotNull($matched, 'the inbound number was not attached to anybody');
        $this->assertEquals(
            $customer->id,
            $matched->id,
            'a number stored with the 00 prefix was treated as a different person'
        );

        // And the way out: the same spelling is the one that has to leave the
        // record when the number changes.
        $this->runJob($account, $this->numberChange($account, 'wamid.sys18', '34611222333', '34655444555'));
        $phones = array_column(Customer::find($customer->id)->getPhones(), 'value');
        $this->assertNotContains(
            '0034611222333',
            $phones,
            'the number its owner gave up survived because it was written with the 00 prefix'
        );
    }
}
