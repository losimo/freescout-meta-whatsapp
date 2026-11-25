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
