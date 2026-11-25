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
use Modules\MetaWhatsApp\Support\CustomerMatcher;

/**
 * Issue #59. A customer who already exists in FreeScout with the same phone
 * number was never found, so every WhatsApp sender arrived as a new customer
 * named after their own number, next to the record an administrator had
 * already imported.
 *
 * The deliberate limits of the matching are tested here too, because they are
 * decisions and not omissions: a number stored without its country code is
 * not matched, since the alternative is attaching a conversation to the wrong
 * person, and that failure never looks like one.
 */
class CustomerMatchingTest extends TestCase
{
    use DatabaseTransactions;

    protected function runJob(WhatsAppAccount $account, array $payload)
    {
        (new ProcessInboundWebhook($account->id, $payload))->handle();
    }

    /**
     * A customer as an import leaves it: a phone number on the record and no
     * WhatsApp channel of any kind.
     */
    protected function importedCustomer(string $storedPhone, string $firstName = 'Imported', string $lastName = 'Person')
    {
        $customer             = new Customer();
        $customer->first_name = $firstName;
        $customer->last_name  = $lastName;
        $customer->setPhones([['value' => $storedPhone, 'type' => Customer::PHONE_TYPE_MOBILE]]);
        $customer->save();

        return $customer;
    }

    protected function payloadNamed(WhatsAppAccount $account, string $wamid, string $from, ?string $profileName)
    {
        $contact = ['wa_id' => $from];
        if ($profileName !== null) {
            $contact['profile'] = ['name' => $profileName];
        }

        return $this->inboundPayload($account, $wamid, $from, 'Hola', [$contact]);
    }

    /**
     * Reverted on the reporter's argument (#59), which was better than mine:
     * many people's profile names are initials or nicknames, so a profile
     * name looks like a real name without being one, and a customer still
     * called `+34...` reads as "not matched to anybody yet", which is
     * information an agent can use at a glance.
     */
    public function test_a_new_customer_is_named_with_the_number_and_not_the_profile_name()
    {
        $account = $this->createTestAccount();

        $this->runJob($account, $this->payloadNamed($account, 'wamid.n59a', '34611222333', 'Pau Ferrer'));

        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $this->assertNotNull($customer);
        $this->assertEquals('+34611222333', $customer->first_name);
        $this->assertEquals('', (string) $customer->last_name);
    }

    public function test_an_existing_customer_with_the_same_number_is_reused_and_gains_the_channel()
    {
        $account  = $this->createTestAccount();
        $existing = $this->importedCustomer('+34611222333');

        $this->runJob($account, $this->payloadNamed($account, 'wamid.n59b', '34611222333', 'Pau Ferrer'));

        $matched = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $this->assertNotNull($matched, 'The channel should have been added to the customer that was found.');
        $this->assertEquals($existing->id, $matched->id, 'A duplicate customer was created instead of reusing the imported one.');

        $this->assertEquals(
            1,
            CustomerChannel::where('channel', WhatsAppAccount::CHANNEL)->where('channel_id', '+34611222333')->count()
        );
    }

    public function test_a_number_stored_with_the_international_prefix_is_reused()
    {
        $account  = $this->createTestAccount();
        $existing = $this->importedCustomer('0034611222333');

        $this->runJob($account, $this->payloadNamed($account, 'wamid.n59c', '34611222333', 'Pau Ferrer'));

        $matched = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $this->assertEquals($existing->id, $matched->id, 'A 00 prefix is ITU notation for the same number.');
    }

    /**
     * Deliberate. '611222333' could be this Spanish mobile or the tail of a
     * number somewhere else, FreeScout stores no dialling codes to resolve it
     * against, and there is no phone library in its vendor directory. The
     * answer is a separate customer, which an agent can merge, rather than a
     * guess nobody can see.
     */
    public function test_a_number_stored_without_the_country_code_is_not_matched()
    {
        $account  = $this->createTestAccount();
        $existing = $this->importedCustomer('611222333');

        $this->runJob($account, $this->payloadNamed($account, 'wamid.n59d', '34611222333', 'Pau Ferrer'));

        $matched = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $this->assertNotNull($matched);
        $this->assertNotEquals($existing->id, $matched->id);
    }

    public function test_when_two_customers_share_the_number_the_oldest_is_used()
    {
        $account = $this->createTestAccount();
        $first   = $this->importedCustomer('+34611222333', 'First', 'Import');
        $second  = $this->importedCustomer('+34611222333', 'Second', 'Import');

        $this->runJob($account, $this->payloadNamed($account, 'wamid.n59e', '34611222333', 'Pau Ferrer'));

        $matched = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $this->assertEquals($first->id, $matched->id);
        $this->assertNotEquals($second->id, $matched->id);
    }

    public function test_when_two_customers_share_the_number_the_conversation_says_which_others_matched()
    {
        $account = $this->createTestAccount();
        $first   = $this->importedCustomer('+34611222333', 'First', 'Import');
        $second  = $this->importedCustomer('+34611222333', 'Second', 'Import');

        $this->runJob($account, $this->payloadNamed($account, 'wamid.n59f', '34611222333', 'Pau Ferrer'));

        $conversation = Conversation::where('mailbox_id', $account->mailbox_id)
            ->where('customer_id', $first->id)
            ->first();
        $this->assertNotNull($conversation);

        $note = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_NOTE)
            ->first();
        $this->assertNotNull($note, 'An ambiguous match has to leave a trace where the agent works.');
        $this->assertStringContainsString('#' . $second->id, $note->body);
        $this->assertStringNotContainsString('611222333', $note->body, 'The note names records, not the number.');
    }

    /**
     * Still true and now true by construction: since the revert the module
     * never writes a profile name anywhere, so there is nothing that could
     * overwrite a name a person typed. Kept as the guard it always was.
     */
    public function test_the_profile_name_never_overwrites_a_name_somebody_typed()
    {
        $account  = $this->createTestAccount();
        $existing = $this->importedCustomer('+34611222333', 'Pau', 'Ferrer (accounts)');

        $this->runJob($account, $this->payloadNamed($account, 'wamid.n59g', '34611222333', 'Pauet'));

        $existing = Customer::find($existing->id);
        $this->assertEquals('Pau', $existing->first_name);
        $this->assertEquals('Ferrer (accounts)', $existing->last_name);
    }

    /**
     * The other half of the revert, and the half that makes it mean
     * something: a customer named after their number **keeps** that name. If
     * the profile name were filled in one message later, the "not matched
     * yet" signal the reporter asked for would last exactly one message.
     */
    public function test_a_customer_named_after_their_number_keeps_that_name()
    {
        $account  = $this->createTestAccount();
        $existing = $this->importedCustomer('+34611222333', '+34611222333', '');

        $this->runJob($account, $this->payloadNamed($account, 'wamid.n59h', '34611222333', 'Pau Ferrer'));

        $existing = Customer::find($existing->id);
        $this->assertEquals('+34611222333', $existing->first_name);
        $this->assertEquals('', (string) $existing->last_name);
    }

    /**
     * The core's ThreadObserver sets the conversation preview from any note
     * it sees, so a note about the ambiguity could end up as what the folder
     * list shows instead of what the customer wrote.
     */
    public function test_the_ambiguity_note_does_not_become_the_conversation_preview()
    {
        $account = $this->createTestAccount();
        $first   = $this->importedCustomer('+34611222333', 'First', 'Import');
        $this->importedCustomer('+34611222333', 'Second', 'Import');

        $payload = $this->inboundPayload($account, 'wamid.n59j', '34611222333', 'Bon dia, tinc un dubte', [[
            'wa_id'   => '34611222333',
            'profile' => ['name' => 'Pau Ferrer'],
        ]]);
        $this->runJob($account, $payload);

        $conversation = Conversation::where('mailbox_id', $account->mailbox_id)
            ->where('customer_id', $first->id)
            ->first();
        $this->assertStringContainsString('Bon dia', $conversation->preview);
        $this->assertStringNotContainsString('customer records', $conversation->preview);
    }

    /**
     * A record with no name is not one the module wrote, so the profile name,
     * which is a string the sender chooses, does not go on it.
     */
    public function test_a_customer_record_with_no_name_is_not_renamed_by_the_sender()
    {
        $account              = $this->createTestAccount();
        $existing             = $this->importedCustomer('+34611222333', '', '');

        $this->runJob($account, $this->payloadNamed($account, 'wamid.n59k', '34611222333', 'Pau Ferrer'));

        $existing = Customer::find($existing->id);
        $this->assertEquals('', (string) $existing->first_name);
        $this->assertEquals('', (string) $existing->last_name);
    }

    /**
     * Reusing a customer that existed before WhatsApp means they may already
     * have a chat conversation of another channel's in this mailbox.
     */
    public function test_a_chat_conversation_of_another_channel_does_not_swallow_the_message()
    {
        $account  = $this->createTestAccount();
        $existing = $this->importedCustomer('+34611222333');

        $other                 = new Conversation();
        $other->type           = Conversation::TYPE_CHAT;
        $other->channel        = 99;
        $other->state          = Conversation::STATE_PUBLISHED;
        $other->subject        = 'Another chat channel';
        $other->mailbox_id     = $account->mailbox_id;
        $other->customer_id    = $existing->id;
        $other->customer_email = '';
        $other->status         = Conversation::STATUS_ACTIVE;
        $other->source_via     = Conversation::PERSON_CUSTOMER;
        $other->source_type    = Conversation::SOURCE_TYPE_API;
        $other->preview        = 'x';
        $other->save();

        $this->runJob($account, $this->payloadNamed($account, 'wamid.n59l', '34611222333', 'Pau Ferrer'));

        $landedIn = \Modules\MetaWhatsApp\Models\WhatsAppMessage::where('wamid', 'wamid.n59l')->first()->conversation_id;
        $this->assertNotEquals($other->id, $landedIn);
        $this->assertEquals(0, Thread::where('conversation_id', $other->id)->count());
    }

    /**
     * Customer::addChannel() would have overwritten the first number with the
     * second, leaving the person reachable under one of their two numbers and
     * swapping which one as they wrote.
     */
    public function test_a_second_number_of_the_same_customer_keeps_both_links_and_opens_its_own_conversation()
    {
        $account  = $this->createTestAccount();
        $existing = $this->importedCustomer('+34611222333', 'Two', 'Numbers');
        $existing->setPhones([
            ['value' => '+34611222333', 'type' => Customer::PHONE_TYPE_MOBILE],
            ['value' => '+34655444555', 'type' => Customer::PHONE_TYPE_MOBILE],
        ]);
        $existing->save();

        $this->runJob($account, $this->payloadNamed($account, 'wamid.n59m', '34611222333', 'Pau Ferrer'));
        $this->runJob($account, $this->payloadNamed($account, 'wamid.n59n', '34655444555', 'Pau Ferrer'));

        $byFirst  = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $bySecond = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34655444555');
        $this->assertNotNull($byFirst, 'the first number stopped resolving to anybody');
        $this->assertNotNull($bySecond);
        $this->assertEquals($existing->id, $byFirst->id);
        $this->assertEquals($existing->id, $bySecond->id);
        $this->assertEquals(
            2,
            CustomerChannel::where('customer_id', $existing->id)
                ->where('channel', WhatsAppAccount::CHANNEL)
                ->count()
        );

        // Each number gets its own conversation, which is what keeps a reply
        // from going to the other one: the recipient is the conversation's own
        // last inbound message.
        $first  = WhatsAppMessage::where('wamid', 'wamid.n59m')->first();
        $second = WhatsAppMessage::where('wamid', 'wamid.n59n')->first();
        $this->assertNotEquals($first->conversation_id, $second->conversation_id);
    }

    /**
     * The other side of the same rule. A contact known only by their
     * business-scoped id has messages with no phone number on them, and when
     * Meta starts sending the phone too, that history has to continue in the
     * same conversation instead of splitting in two.
     */
    public function test_a_conversation_known_only_by_the_business_scoped_id_continues_when_the_phone_appears()
    {
        $account = $this->createTestAccount();
        $bsuid   = 'US.13491208655302741918';

        $this->runJob($account, $this->inboundPayload($account, 'wamid.n59o', $bsuid, 'Hola', [[
            'profile' => ['name' => 'Pau Ferrer'],
            'user_id' => $bsuid,
        ]]));
        $firstConversation = WhatsAppMessage::where('wamid', 'wamid.n59o')->first()->conversation_id;
        $this->assertNotNull($firstConversation);

        $this->runJob($account, $this->inboundPayload($account, 'wamid.n59p', '34611222333', 'Segon', [[
            'wa_id'   => '34611222333',
            'user_id' => $bsuid,
            'profile' => ['name' => 'Pau Ferrer'],
        ]]));
        $secondConversation = WhatsAppMessage::where('wamid', 'wamid.n59p')->first()->conversation_id;

        $this->assertEquals(
            $firstConversation,
            $secondConversation,
            'filing by number must not split the history of one person'
        );
    }

    public function test_a_sender_with_no_profile_name_is_still_filed_under_their_number()
    {
        $account = $this->createTestAccount();

        $this->runJob($account, $this->payloadNamed($account, 'wamid.n59i', '34611222333', null));

        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $this->assertNotNull($customer);
        $this->assertEquals('+34611222333', $customer->first_name);
    }

    /**
     * The ITU prefix on the side it arrives from, pinned at the method and
     * not through the job, because the job cannot reach it: Meta sends E.164,
     * and '00' is a dialling convention rather than part of a number, so an
     * inbound value never carries it. The spelling comes from the other
     * direction, a value somebody typed into a customer record, which is
     * what sameNumber() compares against Meta's.
     *
     * The mutation pass of the 3.0.0 review found this branch replaceable
     * with `if (false)` while the whole suite stayed green, and the matching
     * rule is one of the five things promised to MarkoD on issue #59.
     */
    public function test_the_international_prefix_is_understood_on_the_number_given()
    {
        $this->assertEquals(
            ['34611222333', '0034611222333'],
            CustomerMatcher::searchCandidates('0034611222333'),
            'a number written with the ITU prefix is not looked for without it'
        );

        // And the plain spelling answers with both, which is the half the
        // imported-record case already covers.
        $this->assertEquals(
            ['34611222333', '0034611222333'],
            CustomerMatcher::searchCandidates('+34 611 222 333')
        );
    }

    /**
     * sameNumber() is the rule movePhone() uses to decide which entry on a
     * customer record is the number that just changed. Getting it wrong in
     * either direction has a cost: too strict leaves a dead number where
     * `Customer::byPhone()` keeps finding the person, too loose rewrites a
     * number nobody said anything about.
     */
    public function test_the_same_number_written_two_ways_is_one_number()
    {
        $this->assertTrue(CustomerMatcher::sameNumber('+34 611-222-333', '34611222333'));
        $this->assertTrue(CustomerMatcher::sameNumber('0034611222333', '+34611222333'));
        $this->assertTrue(CustomerMatcher::sameNumber('(+34) 611 222 333', '+34611222333'));

        $this->assertFalse(CustomerMatcher::sameNumber('+34611222333', '+34655444555'));
        $this->assertFalse(
            CustomerMatcher::sameNumber('611222333', '+34611222333'),
            'a national number is not resolved against a country code the core cannot supply'
        );
        $this->assertFalse(CustomerMatcher::sameNumber('', '+34611222333'));
        $this->assertFalse(CustomerMatcher::sameNumber('12345', '+34611222333'), 'below the usable floor');
    }
}
