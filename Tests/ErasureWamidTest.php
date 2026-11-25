<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Support\PersonalDataErasure;

/**
 * What the receipt promises, checked against every column of the row.
 *
 * Meta's message id is base64 and carries the contact's phone number in
 * clear text, so clearing `contact_phone` and `contact_user_id` left the
 * number recoverable from the row the erasure had just reported as
 * cleaned. The receipt said the identifiers were kept on purpose and
 * explained why, which made the gap harder to see rather than easier.
 * Reported by MarkoD (T02).
 */
class ErasureWamidTest extends TestCase
{
    use DatabaseTransactions;

    /** The sample id from Meta's own documentation. It decodes to 16503879439. */
    const SAMPLE_WAMID = 'wamid.HBgLMTY1MDM4Nzk0MzkVAgASGBQzQTRBNjU5OUFFRTAzODEwMTQ0RgA=';

    const SAMPLE_PHONE = '+16503879439';

    protected function makeRow(array $overrides = []): WhatsAppMessage
    {
        $account = $this->createTestAccount();

        return WhatsAppMessage::create(array_merge([
            'wamid'         => self::SAMPLE_WAMID,
            'account_id'    => $account->id,
            'contact_phone' => self::SAMPLE_PHONE,
            'direction'     => WhatsAppMessage::DIRECTION_INBOUND,
            'status'        => WhatsAppMessage::STATUS_RECEIVED,
        ], $overrides));
    }

    public function test_the_phone_number_cannot_be_recovered_from_any_column_after_erasure()
    {
        $row = $this->makeRow();

        PersonalDataErasure::erase(self::SAMPLE_PHONE);

        $fresh = WhatsAppMessage::find($row->id);
        $this->assertNotNull($fresh, 'the row itself must survive, or a redelivery recreates the person');

        foreach ($fresh->getAttributes() as $column => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            $this->assertStringNotContainsString(
                '16503879439',
                $value,
                'the phone number is still readable from ' . $column
            );
        }

        // The column that carried it was never readable as such: it had to
        // be decoded first, which is exactly why nobody saw it.
        $decoded = base64_decode(preg_replace('/^wamid\./', '', $fresh->wamid), false);
        $this->assertStringNotContainsString(
            '16503879439',
            (string) $decoded,
            'the phone number comes back out of the identifier with one base64_decode'
        );
    }

    public function test_a_redelivery_of_the_original_identifier_is_still_a_no_op()
    {
        $this->makeRow();

        PersonalDataErasure::erase(self::SAMPLE_PHONE);

        $this->assertTrue(
            WhatsAppMessage::existsForWamid(self::SAMPLE_WAMID),
            'an erased message must still be recognised, or Meta redelivering it recreates the customer and the conversation'
        );
    }

    /** Erasing twice must not wrap the identifier twice. */
    public function test_the_transformation_is_applied_once()
    {
        $row = $this->makeRow();

        PersonalDataErasure::erase(self::SAMPLE_PHONE);
        $once = WhatsAppMessage::find($row->id)->wamid;

        PersonalDataErasure::erase(self::SAMPLE_PHONE);
        $this->assertSame($once, WhatsAppMessage::find($row->id)->wamid);
    }

    /**
     * 'erased-' plus 64 hex characters is always 71, so asserting it is
     * under the column width proves nothing on its own: the column could
     * be narrowed to 50 and that assertion would still pass. The width is
     * read from the database instead, so narrowing the column fails here
     * rather than in production on the first erasure.
     */
    public function test_the_erased_form_fits_the_column()
    {
        $this->assertSame(71, strlen(WhatsAppMessage::erasedWamid(self::SAMPLE_WAMID)));

        $column = \DB::select("SHOW COLUMNS FROM meta_whatsapp_messages LIKE 'wamid'");
        $this->assertNotEmpty($column);
        preg_match('/\((\d+)\)/', $column[0]->Type, $m);
        $this->assertGreaterThanOrEqual(
            71,
            (int) ($m[1] ?? 0),
            'the wamid column is now too narrow for an erased identifier'
        );
    }

    /** Migrations are not autoloaded, so the file is required by hand. */
    protected function migration()
    {
        require_once __DIR__ . '/../Database/Migrations/2026_11_23_000001_hash_wamid_of_already_erased_rows.php';

        return new \HashWamidOfAlreadyErasedRows();
    }

    /**
     * A row erased by the previous version: no identity left, and Meta's
     * id still in the clear. Nothing can find it by searching, because
     * searching is by identity and that is exactly what it no longer has,
     * so the migration is the only thing that reaches it.
     */
    public function test_a_row_erased_by_the_old_code_is_reachable_by_the_migration()
    {
        $row = $this->makeRow(['contact_phone' => null, 'contact_user_id' => null]);

        $this->migration()->up();

        $this->assertSame(
            WhatsAppMessage::erasedWamid(self::SAMPLE_WAMID),
            WhatsAppMessage::find($row->id)->wamid
        );
    }

    /** A live row keeps Meta's id: an administrator needs it to raise anything with Meta. */
    public function test_a_row_that_still_holds_an_identity_is_untouched()
    {
        $row = $this->makeRow();

        $this->migration()->up();

        $this->assertSame(self::SAMPLE_WAMID, WhatsAppMessage::find($row->id)->wamid);
    }

    /** A send that never reached Meta has a synthetic id with no phone in it. */
    public function test_a_synthetic_identifier_is_untouched()
    {
        $row = $this->makeRow([
            'wamid'         => 'failed-thread-' . uniqid(),
            'contact_phone' => null,
            'direction'     => WhatsAppMessage::DIRECTION_OUTBOUND,
        ]);
        $synthetic = $row->wamid;

        $this->migration()->up();

        $this->assertSame($synthetic, WhatsAppMessage::find($row->id)->wamid);
    }

    /** Running it twice must not wrap a value twice either. */
    public function test_the_migration_is_safe_to_run_again()
    {
        $row = $this->makeRow(['contact_phone' => null, 'contact_user_id' => null]);

        $this->migration()->up();
        $once = WhatsAppMessage::find($row->id)->wamid;
        $this->migration()->up();

        $this->assertSame($once, WhatsAppMessage::find($row->id)->wamid);
    }
}
