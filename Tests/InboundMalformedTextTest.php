<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;

/**
 * Our own finding 16, and it is less than we claimed.
 *
 * `htmlspecialchars()` without ENT_SUBSTITUTE answers the empty string for a
 * malformed UTF-8 sequence, so a message would be stored with no text at all.
 * That much is true of the function and verified in the container (PHP
 * 8.2.28).
 *
 * What is not true is that it can happen through the real door. Measured on
 * the same PHP:
 *
 *   json_decode('{"t":"\ud83d"}')        => null, "Single unpaired UTF-16 surrogate"
 *   json_decode('{"t":"a' . 0xC3 . '"}') => null, "Malformed UTF-8 characters"
 *   json_encode(['x' => "a\xC3b"])       => false
 *
 * So the webhook body is rejected before any of this module's code sees it,
 * and the queue could not serialise such a payload either. No `substr()` in
 * the module cuts customer text, so nothing truncates a character on the way
 * in. This test therefore drives the job directly, which is the only way the
 * value can get there, and the fix is a landmine removed rather than a defect
 * repaired. Written down because a finding of ours that turned out smaller
 * than stated is worth as much as one that holds.
 */
class InboundMalformedTextTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_malformed_byte_no_longer_empties_the_whole_message()
    {
        $account = $this->createTestAccount();
        $broken  = 'Bon dia ' . chr(0xC3) . ' us escric per la factura';

        $payload = $this->inboundPayload($account, 'wamid.utf1', '34611222333', $broken);
        (new ProcessInboundWebhook($account->id, $payload))->handle();

        $record = WhatsAppMessage::where('wamid', 'wamid.utf1')->first();
        $this->assertNotNull($record);

        $body = Thread::find($record->thread_id)->body;
        $this->assertStringContainsString('Bon dia', $body, 'the readable text was dropped');
        $this->assertStringContainsString(
            'us escric per la factura',
            $body,
            'everything after the malformed byte was dropped'
        );
    }

    /** Ordinary text, and accented text, must come through untouched. */
    public function test_accented_text_is_unaffected()
    {
        $account = $this->createTestAccount();

        $payload = $this->inboundPayload($account, 'wamid.utf2', '34611222333', 'Això és una cançó, ¿sí?');
        (new ProcessInboundWebhook($account->id, $payload))->handle();

        $record = WhatsAppMessage::where('wamid', 'wamid.utf2')->first();
        $this->assertStringContainsString(
            'Això és una cançó',
            Thread::find($record->thread_id)->body
        );
    }
}
