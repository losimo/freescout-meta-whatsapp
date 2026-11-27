<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * The receipt is the one piece of this module that someone outside the
 * installation may end up reading, so it is where a sentence that is
 * almost true costs the most. It used to say Meta's identifiers were kept
 * on purpose, and explain why that was harmless, while they carried the
 * contact's phone number (T02).
 *
 * Posted through the real screen, because what matters is the text that
 * gets rendered, not the value of a key.
 *
 * The receipt does name the identifier that was erased, deliberately: it
 * is shown once, stored nowhere, and whoever asked for the erasure has to
 * be able to tell which identity it covered. So that is not asserted
 * against here.
 */
class ErasureReceiptTest extends TestCase
{
    use DatabaseTransactions;

    protected function eraseThrough(string $identifier)
    {
        return $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/erasure/erase'), [
                'identifier' => $identifier,
            ]);
    }

    /**
     * The receipt carries the same figure the screen showed, so the two can
     * be compared, and it carries nothing extra when there is nothing to
     * say: an empty line inside a block meant to be copied in one go is a
     * question the administrator cannot answer.
     */
    public function test_the_receipt_repeats_what_the_screen_said_about_other_data()
    {
        $account = $this->createTestAccount();
        WhatsAppMessage::create([
            'wamid'         => 'wamid.RECEIPT' . uniqid(),
            'account_id'    => $account->id,
            'contact_phone' => '+34600111333',
            'direction'     => WhatsAppMessage::DIRECTION_INBOUND,
            'status'        => WhatsAppMessage::STATUS_RECEIVED,
        ]);

        $imported = new \App\Customer();
        $imported->first_name = 'Imported';
        $imported->last_name  = 'Contact';
        $imported->save();
        $imported->setPhones([
            ['value' => '+34600111333', 'type' => \App\Customer::PHONE_TYPE_WORK],
            ['value' => '+34655111399', 'type' => \App\Customer::PHONE_TYPE_MOBILE],
        ]);
        $imported->save();

        $body = $this->eraseThrough('+34600111333')->getContent();

        $this->assertStringContainsString(
            e(__('metawhatsapp::metawhatsapp.erasure_records_other_data', ['count' => 1])),
            $body,
            'the receipt does not say that one of the records it changed holds other data'
        );
    }

    /** And nothing is added to the block when every record was only this identity. */
    public function test_the_receipt_says_nothing_extra_when_no_record_holds_other_data()
    {
        $account = $this->createTestAccount();
        WhatsAppMessage::create([
            'wamid'         => 'wamid.RECEIPT' . uniqid(),
            'account_id'    => $account->id,
            'contact_phone' => '+34600111444',
            'direction'     => WhatsAppMessage::DIRECTION_INBOUND,
            'status'        => WhatsAppMessage::STATUS_RECEIVED,
        ]);

        $body = $this->eraseThrough('+34600111444')->getContent();

        $this->assertStringNotContainsString(
            e(__('metawhatsapp::metawhatsapp.erasure_records_other_data', ['count' => 0])),
            $body
        );
        $this->assertStringNotContainsString(
            ":count",
            $body,
            'a placeholder reached the screen, so a string was printed without its values'
        );
    }

    public function test_the_receipt_no_longer_claims_the_identifiers_were_kept_as_they_were()
    {
        $account = $this->createTestAccount();
        WhatsAppMessage::create([
            'wamid'         => 'wamid.RECEIPT' . uniqid(),
            'account_id'    => $account->id,
            'contact_phone' => '+34600111222',
            'direction'     => WhatsAppMessage::DIRECTION_INBOUND,
            'status'        => WhatsAppMessage::STATUS_RECEIVED,
        ]);

        $response = $this->eraseThrough('+34600111222');

        $response->assertStatus(200);
        $body = $response->getContent();

        $this->assertStringNotContainsString(
            'Kept on purpose',
            $body,
            'the receipt still presents Meta identifiers as kept and harmless'
        );
        $this->assertStringContainsString(
            'unreadable',
            $body,
            'the receipt does not say what was done to Meta identifiers'
        );
    }

    /**
     * The sentence has to be there in every language the module speaks
     * itself, or an administrator reading in Catalan or Spanish gets the
     * old promise in a receipt they may forward.
     */
    public function test_the_sentence_is_translated_where_this_module_translates()
    {
        // The word each language uses for what was done to the identifier.
        // Asserting only the absence of the old phrase would pass on an
        // empty string, which is the failure mode worth guarding: a
        // missing key renders as the key itself in a receipt someone may
        // forward.
        $expected = [
            'en' => 'unreadable',
            'ca' => 'il·legible',
            'es' => 'ilegible',
        ];

        foreach ($expected as $locale => $word) {
            $strings = include __DIR__ . '/../Resources/lang/' . $locale . '/metawhatsapp.php';

            $this->assertArrayHasKey('erasure_receipt_kept', $strings);
            $this->assertStringContainsString(
                $word,
                $strings['erasure_receipt_kept'],
                $locale . ' does not say that the identifier was made unreadable'
            );
            foreach (['Kept on purpose', 'a posta', 'a propósito'] as $oldClaim) {
                $this->assertStringNotContainsString(
                    $oldClaim,
                    $strings['erasure_receipt_kept'],
                    $locale . ' still presents the identifiers as kept on purpose'
                );
            }
        }
    }
}
