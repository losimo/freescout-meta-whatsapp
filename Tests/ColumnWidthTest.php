<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * FreeScout turns MySQL's strict mode off in its own config/database.php, so
 * a value longer than its column is not refused: it is cut and stored, with
 * no error anywhere. That is how the business-scoped ID was lost for months
 * before a customer reported that their messages never arrived.
 *
 * These tests are the length guards written down. They are not about the
 * numbers being generous, they are about a value coming back exactly as it
 * went in, because the failure they guard against is silent.
 */
class ColumnWidthTest extends TestCase
{
    use DatabaseTransactions;

    protected function storeWithWamid(string $wamid): WhatsAppMessage
    {
        $account = $this->createTestAccount();

        return WhatsAppMessage::create([
            'wamid'           => $wamid,
            'account_id'      => $account->id,
            'conversation_id' => 1,
            'contact_phone'   => '+34611222333',
            'direction'       => WhatsAppMessage::DIRECTION_INBOUND,
            'status'          => WhatsAppMessage::STATUS_RECEIVED,
        ]);
    }

    /**
     * Meta documents no maximum for a wamid. The ones seen in the wild are
     * around 60 characters, so the old ceiling of 100 looked generous until
     * you notice nothing would say when it stopped being so.
     */
    public function test_a_long_wamid_comes_back_exactly_as_it_went_in()
    {
        $wamid = 'wamid.' . str_repeat('A', 150);
        $this->assertEquals(156, strlen($wamid));

        $stored = $this->storeWithWamid($wamid)->fresh();

        $this->assertEquals(
            $wamid,
            $stored->wamid,
            'El wamid s\'ha desat retallat: a partir d\'aquí el missatge queda arxivat sota un identificador que no és el seu.'
        );
    }

    /**
     * The consequence worth naming: two ids sharing a truncated prefix become
     * the same row. The unique index refuses the second, the duplicate-key
     * error reads as "already processed", and the message is dropped silently.
     */
    public function test_two_long_wamids_sharing_a_prefix_stay_two_messages()
    {
        $prefix = 'wamid.' . str_repeat('B', 120);

        $this->storeWithWamid($prefix . 'FIRST');
        $this->storeWithWamid($prefix . 'SECOND');

        $this->assertEquals(2, WhatsAppMessage::where('wamid', 'like', $prefix . '%')->count());
    }
}
