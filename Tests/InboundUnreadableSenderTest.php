<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Customer;
use App\CustomerChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

/**
 * T21. A message whose sender the module cannot read used to take down the
 * whole webhook, and with it every other message that arrived in the same
 * delivery.
 *
 * `from` is not always there, and that is documented rather than exotic: Meta
 * omits it when the person has adopted a WhatsApp username and their phone
 * number is not available to us, and sends `from_user_id` with their
 * business-scoped id instead. Every webhook reference in the dated corpus of
 * 2026-11-22 carries the same warning: "wa_id and from are omitted when the
 * user has adopted a username". The module read neither, so those messages
 * were not merely crashing, they had nowhere to be filed.
 */
class InboundUnreadableSenderTest extends TestCase
{
    use DatabaseTransactions;

    /** A job whose Graph API client is a double, so no download leaves here. */
    protected function jobWithFakeDownload(WhatsAppAccount $account, array $payload, array $result = null)
    {
        $fakeClient = \Mockery::mock(WhatsAppApiClient::class);
        $fakeClient->shouldReceive('downloadMedia')->andReturn($result ?: [
            'ok' => true, 'bytes' => 'fake-bytes', 'mime_type' => 'image/jpeg',
            'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false,
        ]);

        $job = new class($account->id, $payload) extends ProcessInboundWebhook {
            private $fakeApiClient;
            public function setFakeApiClient($client)
            {
                $this->fakeApiClient = $client;
            }
            protected function apiClient(WhatsAppAccount $account): WhatsAppApiClient
            {
                return $this->fakeApiClient ?: parent::apiClient($account);
            }
        };
        $job->setFakeApiClient($fakeClient);

        return $job;
    }

    /** Two messages in one delivery, as Meta batches them. */
    protected function batchPayload(WhatsAppAccount $account, array $messages): array
    {
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
                        'messages' => $messages,
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];
    }

    /**
     * The defect this task exists for. A media message with no `from` threw a
     * TypeError inside downloadInboundMedia(), and nothing in the loop over
     * messages caught it, so the second person's message never got written.
     */
    public function test_a_message_without_a_readable_sender_does_not_take_the_rest_of_the_delivery_down()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [
            [
                // No 'from' and no 'from_user_id': nothing to attribute it to.
                'id'        => 'wamid.unreadable',
                'timestamp' => (string) time(),
                'type'      => 'image',
                'image'     => ['id' => 'media-unreadable'],
            ],
            [
                'from'      => '34611222333',
                'id'        => 'wamid.healthy',
                'timestamp' => (string) time(),
                'type'      => 'text',
                'text'      => ['body' => 'Sóc el segon missatge'],
            ],
        ]);

        $downloads  = 0;
        $fakeClient = \Mockery::mock(WhatsAppApiClient::class);
        $fakeClient->shouldReceive('downloadMedia')->andReturnUsing(function () use (&$downloads) {
            $downloads++;

            return [
                'ok' => true, 'bytes' => 'fake-bytes', 'mime_type' => 'image/jpeg',
                'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false,
            ];
        });

        $job = new class($account->id, $payload) extends ProcessInboundWebhook {
            private $fakeApiClient;
            public function setFakeApiClient($client)
            {
                $this->fakeApiClient = $client;
            }
            protected function apiClient(WhatsAppAccount $account): WhatsAppApiClient
            {
                return $this->fakeApiClient ?: parent::apiClient($account);
            }
        };
        $job->setFakeApiClient($fakeClient);
        $job->handle();

        $this->assertNotNull(
            WhatsAppMessage::where('wamid', 'wamid.healthy')->first(),
            'the second message of the delivery was lost with the first'
        );
        $this->assertNull(
            WhatsAppMessage::where('wamid', 'wamid.unreadable')->first(),
            'a message with no identifier at all must not be filed under anybody'
        );

        // What the guard buys beyond not crashing, and the reason it sits
        // above the download rather than below it: an outside review pointed
        // out that deleting the guard entirely left this test green, because
        // the crash it was written for had been fixed by a signature change.
        $this->assertEquals(
            0,
            $downloads,
            'the file of a message we cannot attribute was fetched from the Graph API'
        );
    }

    /**
     * The half that never worked rather than merely crashing: a person known
     * only by their business-scoped id, which is what Meta sends once they
     * adopt a username.
     */
    public function test_a_sender_known_only_by_their_business_scoped_id_is_processed()
    {
        $account = $this->createTestAccount();
        $bsuid   = 'US.13491208655302741918';

        $payload = $this->batchPayload($account, [[
            'from_user_id' => $bsuid,
            'id'           => 'wamid.username1',
            'timestamp'    => (string) time(),
            'type'         => 'text',
            'text'         => ['body' => 'Escric sense telèfon'],
        ]]);

        $this->jobWithFakeDownload($account, $payload)->handle();

        $record = WhatsAppMessage::where('wamid', 'wamid.username1')->first();
        $this->assertNotNull($record, 'a message carrying only from_user_id was discarded');
        $this->assertEquals($bsuid, $record->contact_user_id);
        $this->assertNull($record->contact_phone, 'there is no phone number to store');
        $this->assertNotNull($record->conversation_id, 'the message reached no conversation');

        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, $bsuid);
        $this->assertNotNull($customer, 'the business-scoped id was not learned as a channel');
    }

    /**
     * `contacts[]` stays the preferred source, because it is the one that also
     * carries the profile name. `from_user_id` is the fallback, not a
     * replacement, and the two must agree on who the sender is.
     */
    public function test_the_contacts_block_is_still_preferred_over_the_message_field()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [[
            'from_user_id' => 'US.111',
            'id'           => 'wamid.username2',
            'timestamp'    => (string) time(),
            'type'         => 'text',
            'text'         => ['body' => 'Hola'],
        ]]);
        $payload['entry'][0]['changes'][0]['value']['contacts'] = [[
            'profile' => ['name' => 'Pau Ferrer'],
            'user_id' => 'US.111',
        ]];

        $this->jobWithFakeDownload($account, $payload)->handle();

        $record = WhatsAppMessage::where('wamid', 'wamid.username2')->first();
        $this->assertNotNull($record);
        $this->assertEquals('US.111', $record->contact_user_id);
        $this->assertEquals(
            1,
            CustomerChannel::where('channel', WhatsAppAccount::CHANNEL_BSUID)
                ->where('channel_id', 'US.111')->count(),
            'the same person must not be learned twice from the two sources'
        );

        // Which of the two sources won is no longer observable at all, and
        // that is the point rather than a gap in the test: since #59 removed
        // the profile name, the contact block contributes only the identifier
        // the message already carries, so preferring one over the other has
        // no visible effect. What still matters, and is asserted above, is
        // that the same person is not learned twice.
        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, 'US.111');
        $this->assertNotNull($customer);
        $this->assertEquals('US.111', $customer->getFullName());
    }

    /**
     * Found by review, and the worst case in this file: a delivery carrying
     * one ordinary sender and one username sender.
     *
     * With `from` absent the match in selectContact() can never succeed, and
     * it used to fall back to the first contact in the block, which belongs
     * to the other person. So the username sender's message was filed under
     * whoever happened to be listed first, with their business-scoped id and
     * their profile name. One delivery with two senders is all it takes, and
     * Meta batches messages and contacts in exactly that shape.
     */
    public function test_a_username_sender_is_not_filed_under_whoever_came_first()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [
            [
                'from'      => '34611222333',
                'id'        => 'wamid.mix1',
                'timestamp' => (string) time(),
                'type'      => 'text',
                'text'      => ['body' => 'Sóc l\'Anna i tinc telèfon'],
            ],
            [
                'from_user_id' => 'US.999',
                'id'           => 'wamid.mix2',
                'timestamp'    => (string) time(),
                'type'         => 'text',
                'text'         => ['body' => 'Sóc en Bru i escric sense telèfon'],
            ],
        ]);
        $payload['entry'][0]['changes'][0]['value']['contacts'] = [
            ['profile' => ['name' => 'Anna'], 'wa_id' => '34611222333', 'user_id' => 'US.111'],
            ['profile' => ['name' => 'Bru'], 'user_id' => 'US.999'],
        ];

        $this->jobWithFakeDownload($account, $payload)->handle();

        $anna = WhatsAppMessage::where('wamid', 'wamid.mix1')->first();
        $bru  = WhatsAppMessage::where('wamid', 'wamid.mix2')->first();
        $this->assertNotNull($anna);
        $this->assertNotNull($bru, 'the username sender was dropped');

        $this->assertEquals('US.999', $bru->contact_user_id, 'the message carries the other person\'s id');
        $this->assertNull($bru->contact_phone, 'the message was given a phone number that is not theirs');
        $this->assertNotEquals(
            $anna->conversation_id,
            $bru->conversation_id,
            'two different people were filed into the same conversation'
        );

        // The customer's name is not asserted here any more. It was, and
        // since the module stopped reading profile names it is the
        // identifier whatever the selection does, so the assertion could not
        // fail. The two lines above are the ones with something to see.
        $this->assertNotNull(
            Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, 'US.999'),
            'the username sender was filed nowhere'
        );
    }

    /**
     * The second round of review found the first fix incomplete, and it was
     * the more likely shape of the two.
     *
     * Counting the contacts was the wrong invariant: what decides whether a
     * contact belongs to this message is the sender, not how many contact
     * objects came along. A delivery with two senders and a single contact
     * object still handed the second sender the first person's contact, and
     * that is exactly the delivery Meta is liable to send, because the
     * sender whose contact it omits is the one whose phone number is
     * unavailable.
     */
    public function test_two_senders_sharing_one_contact_object_are_not_merged()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [
            [
                'from'      => '34611222333',
                'id'        => 'wamid.share1',
                'timestamp' => (string) time(),
                'type'      => 'text',
                'text'      => ['body' => 'Sóc l\'Anna'],
            ],
            [
                'from_user_id' => 'US.999',
                'id'           => 'wamid.share2',
                'timestamp'    => (string) time(),
                'type'         => 'text',
                'text'         => ['body' => 'Sóc en Bru'],
            ],
        ]);
        $payload['entry'][0]['changes'][0]['value']['contacts'] = [
            ['profile' => ['name' => 'Anna'], 'wa_id' => '34611222333', 'user_id' => 'US.111'],
        ];

        $this->jobWithFakeDownload($account, $payload)->handle();

        $anna = WhatsAppMessage::where('wamid', 'wamid.share1')->first();
        $bru  = WhatsAppMessage::where('wamid', 'wamid.share2')->first();
        $this->assertNotNull($anna);
        $this->assertNotNull($bru, 'the username sender was dropped');

        $this->assertEquals('US.111', $anna->contact_user_id);
        $this->assertEquals('US.999', $bru->contact_user_id, 'the message carries the other person\'s id');
        $this->assertNotEquals(
            $anna->conversation_id,
            $bru->conversation_id,
            'two different people were filed into the same conversation'
        );
    }

    /**
     * The shape Meta actually sends for a username sender, which is what
     * settled four rounds of argument about the fallback.
     *
     * In the dated corpus of 2026-11-22 the contact block's `user_id` carries
     * no condition at all, while `wa_id` is documented as "omitted if user's
     * phone number is unavailable", and the BSUID's own description says
     * "included whether or not the user has adopted a username". So the
     * identifier that is always there is the one that matches, and an entry
     * with nothing identifying in it is not a delivery Meta makes.
     */
    public function test_a_username_senders_contact_is_matched_by_their_business_scoped_id()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [[
            'from_user_id' => 'US.SOLE',
            'id'           => 'wamid.one1',
            'timestamp'    => (string) time(),
            'type'         => 'text',
            'text'         => ['body' => 'Hola'],
        ]]);
        $payload['entry'][0]['changes'][0]['value']['contacts'] = [
            ['profile' => ['name' => 'Rosa'], 'user_id' => 'US.SOLE'],
        ];

        $this->jobWithFakeDownload($account, $payload)->handle();

        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, 'US.SOLE');
        $this->assertNotNull($customer, 'the business-scoped id was not learned');

        // The naming rule this used to assert (named after the identifier
        // and not after the profile name, by the reporter's own argument in
        // #59) is pinned where it can still fail, in CustomerMatchingTest:
        // with no profile name read anywhere, the name here is the
        // identifier whatever the selection does.
    }

    /**
     * An entry that identifies nobody does not cost the message.
     *
     * This used to assert what the refusal costs, that the profile name was
     * lost and the customer named after the identifier instead. There is no
     * such cost since the module stopped reading profile names, and with it
     * the assertion stopped being able to fail: the only field the contact
     * block still has a consumer for is `user_id`, and this entry carries
     * none. So what is left here is a crash guard and is stated as one. An
     * entry with nothing in it used to be the kind of value that reached a
     * cast and took the whole delivery down (T21).
     */
    public function test_a_contact_that_identifies_nobody_does_not_cost_the_message()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [[
            'from_user_id' => 'US.NAMELESS',
            'id'           => 'wamid.nameless',
            'timestamp'    => (string) time(),
            'type'         => 'text',
            'text'         => ['body' => 'Hola'],
        ]]);
        $payload['entry'][0]['changes'][0]['value']['contacts'] = [
            ['profile' => ['name' => 'Rosa']],
        ];

        $this->jobWithFakeDownload($account, $payload)->handle();

        $record = WhatsAppMessage::where('wamid', 'wamid.nameless')->first();
        $this->assertNotNull($record, 'the message was lost with the contact');
        $this->assertEquals('US.NAMELESS', $record->contact_user_id, 'the sender lost their own identifier');
    }

    /**
     * Found in the fourth round, and the leak that ended the fallback: an
     * identifier present on one side only was not treated as a contradiction,
     * so an entry carrying `wa_id` was adopted by a sender who has no phone
     * number at all. That is the one sender whose entry Meta omits, so it is
     * the likeliest delivery of all.
     */
    public function test_an_entry_with_a_phone_is_not_adopted_by_a_sender_without_one()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [
            [
                'from'      => '34611222333',
                'id'        => 'wamid.asym1',
                'timestamp' => (string) time(),
                'type'      => 'text',
                'text'      => ['body' => 'Sóc l\'Anna'],
            ],
            [
                'from_user_id' => 'US.999',
                'id'           => 'wamid.asym2',
                'timestamp'    => (string) time(),
                'type'         => 'text',
                'text'         => ['body' => 'Sóc en Bru'],
            ],
        ]);
        // Anna's entry carries her business-scoped id as well as her number.
        // It did not until the 3.0.0 review, and without it this case had
        // nothing left to watch: `user_id` is the only field the contact
        // block still has a consumer for, so an entry without one costs
        // nothing when it is adopted and the test stayed green with the
        // whole rule removed.
        $payload['entry'][0]['changes'][0]['value']['contacts'] = [
            ['profile' => ['name' => 'Anna'], 'wa_id' => '34611222333', 'user_id' => 'US.111'],
        ];

        $this->jobWithFakeDownload($account, $payload)->handle();

        $this->assertEquals(
            'US.999',
            WhatsAppMessage::where('wamid', 'wamid.asym2')->first()->contact_user_id,
            "the username sender's message was filed under the other person's identifier"
        );

        $bru = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, 'US.999');
        $this->assertNotNull($bru, 'the username sender was lost');
        $anna = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $this->assertNotNull($anna);
        $this->assertNotEquals($anna->id, $bru->id, 'two people were filed as one');
    }

    /**
     * The mirror of it, and worse than a name: an entry carrying a
     * business-scoped id was adopted by a phone sender who carries none, so
     * that id was learned as a channel on the wrong customer, which is the
     * identity merge the BSUID channel exists to prevent (#59).
     */
    public function test_a_business_scoped_id_is_not_learned_onto_the_wrong_customer()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [[
            'from'      => '34699888777',
            'id'        => 'wamid.asym3',
            'timestamp' => (string) time(),
            'type'      => 'text',
            'text'      => ['body' => 'Hola'],
        ]]);
        $payload['entry'][0]['changes'][0]['value']['contacts'] = [
            ['profile' => ['name' => 'Bru'], 'user_id' => 'US.777'],
        ];

        $this->jobWithFakeDownload($account, $payload)->handle();

        $record = WhatsAppMessage::where('wamid', 'wamid.asym3')->first();
        $this->assertNotNull($record);
        $this->assertNull(
            $record->contact_user_id,
            'the message was stamped with an id that belongs to nobody we matched'
        );
        $this->assertEquals(
            0,
            CustomerChannel::where('channel', WhatsAppAccount::CHANNEL_BSUID)
                ->where('channel_id', 'US.777')->count(),
            'a business-scoped id was learned onto a customer it does not belong to'
        );
    }

    /**
     * Found in the third round, and it is why counting could never be the
     * rule: one message, one contact, and an entry that positively is not
     * the sender's. No count can tell those apart; a comparison can.
     */
    public function test_a_contact_that_names_somebody_else_is_refused()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [[
            'from_user_id' => 'US.BRU',
            'id'           => 'wamid.contra1',
            'timestamp'    => (string) time(),
            'type'         => 'text',
            'text'         => ['body' => 'Sóc en Bru'],
        ]]);
        $payload['entry'][0]['changes'][0]['value']['contacts'] = [
            ['profile' => ['name' => 'Anna'], 'wa_id' => '34611222333', 'user_id' => 'US.ANNA'],
        ];

        $this->jobWithFakeDownload($account, $payload)->handle();

        $record = WhatsAppMessage::where('wamid', 'wamid.contra1')->first();
        $this->assertNotNull($record);
        $this->assertEquals('US.BRU', $record->contact_user_id, 'the message was stamped with the other person');
        $this->assertNull($record->contact_phone, 'the message was given a phone number that is not theirs');
        $this->assertNull(
            Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, 'US.ANNA'),
            'Anna was invented as a customer out of somebody else\'s message'
        );
    }

    /**
     * Two messages from the same person in one delivery, which is routine
     * because people type in bursts. The previous rule refused the contact
     * block for both of them, and losing the contact's business-scoped id is
     * not cosmetic: nothing learns it, so their next username-only message
     * builds a second customer, which is the #59 failure the BSUID channel
     * exists to prevent.
     */
    public function test_a_burst_from_one_person_does_not_lose_their_contact()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [
            [
                'from_user_id' => 'US.BURST',
                'id'           => 'wamid.burst1',
                'timestamp'    => (string) time(),
                'type'         => 'text',
                'text'         => ['body' => 'Hola'],
            ],
            [
                'from_user_id' => 'US.BURST',
                'id'           => 'wamid.burst2',
                'timestamp'    => (string) time(),
                'type'         => 'text',
                'text'         => ['body' => 'perdó, i una altra cosa'],
            ],
        ]);
        $payload['entry'][0]['changes'][0]['value']['contacts'] = [
            ['profile' => ['name' => 'Jordi'], 'user_id' => 'US.BURST'],
        ];

        $this->jobWithFakeDownload($account, $payload)->handle();

        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL_BSUID, 'US.BURST');
        $this->assertNotNull($customer, 'the business-scoped id was not learned from a burst');
        $this->assertEquals(
            2,
            WhatsAppMessage::whereIn('wamid', ['wamid.burst1', 'wamid.burst2'])->count()
        );
    }

    /**
     * The same number written with and without its '+' is the same number.
     *
     * Only the comparison is normalised, not what gets stored: `from` is
     * still required to be clean digits before it becomes a phone number on
     * the row, which is what Meta sends.
     */
    public function test_a_phone_number_written_with_a_plus_still_matches_its_contact()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [[
            'from'      => '+34611222333',
            'id'        => 'wamid.fmt1',
            'timestamp' => (string) time(),
            'type'      => 'text',
            'text'      => ['body' => 'Hola'],
        ]]);
        $payload['entry'][0]['changes'][0]['value']['contacts'] = [
            ['profile' => ['name' => 'Berta'], 'wa_id' => '34611222333', 'user_id' => 'US.BERTA'],
        ];

        $this->jobWithFakeDownload($account, $payload)->handle();

        $customer = Customer::getCustomerByChannel(WhatsAppAccount::CHANNEL, '+34611222333');
        $this->assertNotNull($customer);

        // The match is observable through the identifier the entry carries,
        // which is the only thing the contact block contributes since the
        // profile name stopped being written (#59).
        $this->assertEquals(
            1,
            CustomerChannel::where('channel', WhatsAppAccount::CHANNEL_BSUID)
                ->where('channel_id', 'US.BERTA')->count(),
            'the contact was not matched across formats'
        );
    }

    /**
     * A malformed value in the contact block must not take the delivery down,
     * which is the whole point of T21 and was reintroduced by a (string) cast
     * on data that comes from outside.
     */
    public function test_a_malformed_contact_entry_does_not_take_the_delivery_down()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [[
            'from'      => '34611222333',
            'id'        => 'wamid.badcontact',
            'timestamp' => (string) time(),
            'type'      => 'text',
            'text'      => ['body' => 'Hola'],
        ]]);
        $payload['entry'][0]['changes'][0]['value']['contacts'] = [
            ['wa_id' => ['34611222333'], 'user_id' => 'US.MALFORMED'],
            ['wa_id' => '34611222333', 'user_id' => 'US.VALID'],
        ];

        $this->jobWithFakeDownload($account, $payload)->handle();

        $this->assertNotNull(
            WhatsAppMessage::where('wamid', 'wamid.badcontact')->first(),
            'a malformed contact entry lost the message'
        );

        // And the valid entry is the one that won, which the two identifiers
        // make visible: asserting only that the row survived would pass
        // whichever entry had been picked.
        $this->assertEquals(
            'US.VALID',
            WhatsAppMessage::where('wamid', 'wamid.badcontact')->first()->contact_user_id,
            'the malformed entry was used'
        );
    }

    /**
     * The same invariant one level up, found in the fourth round: a malformed
     * element inside `messages` raised a TypeError on processMessage()'s
     * parameter, and nothing in that loop catches one either, so it took
     * every other message in the delivery with it.
     */
    public function test_a_malformed_element_in_the_delivery_does_not_take_the_rest_down()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [
            'this is not a message',
            [
                'from'      => '34611222333',
                'id'        => 'wamid.afterjunk',
                'timestamp' => (string) time(),
                'type'      => 'text',
                'text'      => ['body' => 'Jo sí que sóc un missatge'],
            ],
        ]);

        $this->jobWithFakeDownload($account, $payload)->handle();

        $this->assertNotNull(
            WhatsAppMessage::where('wamid', 'wamid.afterjunk')->first(),
            'a malformed element lost the messages that followed it'
        );
    }

    /**
     * Fifth round, and the finding was in the same function again, although
     * this one came over verbatim from the code that was deleted rather than
     * being new: the phone normalisation strips every non-digit, so an
     * identifier that is not a phone number at all was still compared as one.
     *
     * A business-scoped id can arrive in `from`, which this module documents
     * as allowed, and if its digit run happens to be another person's number
     * it matched that person's contact entry. Through a match, not a
     * fallback, which is why deleting the fallback did not reach it.
     */
    public function test_an_identifier_that_is_not_a_phone_is_not_compared_as_one()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [
            [
                'from'      => '34611222333',
                'id'        => 'wamid.coin1',
                'timestamp' => (string) time(),
                'type'      => 'text',
                'text'      => ['body' => 'Sóc l\'Anna'],
            ],
            [
                // A business-scoped id whose digits are Anna's number.
                'from'      => 'US.34611222333',
                'id'        => 'wamid.coin2',
                'timestamp' => (string) time(),
                'type'      => 'text',
                'text'      => ['body' => 'Sóc algú altre'],
            ],
        ]);
        $payload['entry'][0]['changes'][0]['value']['contacts'] = [
            ['profile' => ['name' => 'Anna'], 'wa_id' => '34611222333', 'user_id' => 'US.ANNA'],
        ];

        $this->jobWithFakeDownload($account, $payload)->handle();

        $anna  = WhatsAppMessage::where('wamid', 'wamid.coin1')->first();
        $other = WhatsAppMessage::where('wamid', 'wamid.coin2')->first();
        $this->assertNotNull($anna, 'the ordinary sender was lost');

        // Refused rather than filed under the owner of that number. With no
        // business-scoped id of its own and no contact entry that matches, the
        // module has nothing to attribute it to, which is its existing rule
        // for a sender it cannot read, and it leaves an error line.
        //
        // The shape is synthetic, which is worth saying: Meta always sends
        // `from_user_id`, so a real sender of this kind would be matched by
        // it. The payload is here to drive the comparison, not to claim Meta
        // makes this delivery.
        $this->assertNull(
            $other,
            'the message was attributed to whoever owns the number inside that identifier'
        );
        $this->assertEquals(
            1,
            WhatsAppMessage::where('conversation_id', $anna->conversation_id)->count(),
            'somebody else\'s message landed in this person\'s conversation'
        );
    }

    /**
     * The class of defect behind three of the fifth round's findings: a
     * payload field that is not a string reaching a typed parameter. Each one
     * raises a TypeError that nothing in the loop catches, which is the T21
     * failure mode arriving one field at a time.
     */
    public function test_a_malformed_sender_field_does_not_take_the_rest_down()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [
            [
                'from'      => ['34611222333'],
                'id'        => 'wamid.badfrom',
                'timestamp' => (string) time(),
                'type'      => 'text',
                'text'      => ['body' => 'Sóc un array'],
            ],
            [
                'from_user_id' => ['US.1'],
                'id'           => 'wamid.baduserid',
                'timestamp'    => (string) time(),
                'type'         => 'text',
                'text'         => ['body' => 'I jo un altre'],
            ],
            [
                'from'      => '34611222333',
                'id'        => 'wamid.goodone',
                'timestamp' => (string) time(),
                'type'      => 'text',
                'text'      => ['body' => 'Jo sóc bo'],
            ],
        ]);

        $this->jobWithFakeDownload($account, $payload)->handle();

        $this->assertNotNull(
            WhatsAppMessage::where('wamid', 'wamid.goodone')->first(),
            'a malformed sender field lost the messages that followed it'
        );
    }

    /**
     * And the same class in the statuses loop, where the cost is higher than
     * a lost status: the messages of that delivery are already written when
     * it throws, so handle() discards the buffered agent notifications and
     * the message sits in the mailbox with nobody told about it.
     */
    public function test_a_malformed_status_does_not_lose_the_messages_beside_it()
    {
        $account = $this->createTestAccount();

        $payload = $this->batchPayload($account, [[
            'from'      => '34611222333',
            'id'        => 'wamid.withstatus',
            'timestamp' => (string) time(),
            'type'      => 'text',
            'text'      => ['body' => 'Hola'],
        ]]);
        $payload['entry'][0]['changes'][0]['value']['statuses'] = ['not a status'];

        $this->jobWithFakeDownload($account, $payload)->handle();

        $this->assertNotNull(
            WhatsAppMessage::where('wamid', 'wamid.withstatus')->first(),
            'a malformed status element lost the message beside it'
        );
    }

    /**
     * A collection that is present but is not a collection loses every
     * message in the delivery, so it is logged at error level like anything
     * else that makes a customer's message vanish.
     */
    public function test_a_malformed_messages_collection_is_logged_at_error_level()
    {
        $account = $this->createTestAccount();
        Log::spy();

        $payload = $this->batchPayload($account, []);
        $payload['entry'][0]['changes'][0]['value']['messages'] = 'not a list';

        $this->jobWithFakeDownload($account, $payload)->handle();

        Log::shouldHaveReceived('error')
            ->withArgs(function ($message) {
                return strpos($message, '[MetaWhatsApp]') === 0
                    && stripos($message, 'malformed') !== false;
            })->atLeast()->once();
    }

    /**
     * Error level and not warning: from the customer's side a message they
     * sent has vanished, which is the same reading the group branch above it
     * already uses.
     */
    public function test_a_message_with_no_identifier_is_refused_at_error_level()
    {
        $account = $this->createTestAccount();
        Log::spy();

        $payload = $this->batchPayload($account, [[
            'id'        => 'wamid.nobody',
            'timestamp' => (string) time(),
            'type'      => 'text',
            'text'      => ['body' => 'De qui sóc?'],
        ]]);

        $this->jobWithFakeDownload($account, $payload)->handle();

        // The exact line of this guard, not any error line mentioning a
        // sender: the older refusal further down the method says "Sender
        // without valid phone or user_id", so a loose match was satisfied
        // whether this guard existed or not (found by an outside review).
        Log::shouldHaveReceived('error')
            ->withArgs(function ($message) {
                return $message === '[MetaWhatsApp] Message with no readable sender discarded: neither from nor from_user_id';
            })->once();
    }
}
