<?php

namespace Modules\MetaWhatsApp\Tests;

use App\Thread;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

/**
 * T16. When the attachment could not be downloaded, the module replaced the
 * message body with its own notice and threw the caption away, although
 * downloadInboundMedia() hands it back on the failing path on purpose.
 *
 * So the person wrote a sentence next to their photo, the photo did not make
 * it, and the agent was shown neither. The file is the module's problem; the
 * sentence is the customer's message.
 */
class InboundCaptionSurvivesTest extends TestCase
{
    use DatabaseTransactions;

    protected function runWithFailedDownload(WhatsAppAccount $account, array $payload)
    {
        $fakeClient = \Mockery::mock(WhatsAppApiClient::class);
        $fakeClient->shouldReceive('downloadMedia')->andReturn([
            'ok' => false, 'bytes' => null, 'mime_type' => null,
            'http_status' => 400, 'error_code' => 'media_not_found',
            'error_message' => 'Media not found', 'transient' => false,
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
        $job->handle();
    }

    protected function imagePayload(WhatsAppAccount $account, string $wamid, array $image): array
    {
        $payload = $this->inboundPayload($account, $wamid, '34611222333', 'irrelevant');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['type']  = 'image';
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['image'] = $image;
        unset($payload['entry'][0]['changes'][0]['value']['messages'][0]['text']);

        return $payload;
    }

    public function test_the_caption_is_kept_when_the_file_cannot_be_downloaded()
    {
        $account = $this->createTestAccount();

        $this->runWithFailedDownload($account, $this->imagePayload($account, 'wamid.cap1', [
            'id'      => 'media-gone-1',
            'caption' => 'Aquesta és la factura que em vas demanar',
        ]));

        $record = WhatsAppMessage::where('wamid', 'wamid.cap1')->first();
        $this->assertNotNull($record);
        $body = Thread::find($record->thread_id)->body;

        $this->assertStringContainsString(
            'Aquesta és la factura que em vas demanar',
            $body,
            'the words the customer wrote were replaced by the module notice'
        );
        $this->assertStringContainsString(
            'could not be downloaded',
            $body,
            'the agent still has to be told the file is missing'
        );
    }

    /**
     * The person's words first and the module's notice after, because the
     * thread is a conversation and not a status page.
     */
    public function test_the_customers_words_come_before_the_modules_notice()
    {
        $account = $this->createTestAccount();

        $this->runWithFailedDownload($account, $this->imagePayload($account, 'wamid.cap2', [
            'id'      => 'media-gone-2',
            'caption' => 'Primer les meves paraules',
        ]));

        $body = Thread::find(WhatsAppMessage::where('wamid', 'wamid.cap2')->first()->thread_id)->body;

        // Both positions are asserted to exist before being compared. A
        // missing needle makes strpos() answer false, which compares as 0 and
        // would make this read as "the caption comes first" precisely when the
        // caption is not there at all.
        $caption = strpos($body, 'Primer les meves paraules');
        $notice  = strpos($body, 'could not be downloaded');
        $this->assertIsInt($caption, 'the caption is not in the body at all');
        $this->assertIsInt($notice, 'the notice is not in the body at all');

        $this->assertLessThan(
            $notice,
            $caption,
            'the module notice is printed above the message'
        );
    }

    /**
     * With no caption there is nothing to keep, and the notice stays exactly
     * as it was. Guarding the case the fix could have broken.
     */
    public function test_a_file_with_no_caption_still_shows_only_the_notice()
    {
        $account = $this->createTestAccount();

        $this->runWithFailedDownload($account, $this->imagePayload($account, 'wamid.cap3', [
            'id' => 'media-gone-3',
        ]));

        $body = Thread::find(WhatsAppMessage::where('wamid', 'wamid.cap3')->first()->thread_id)->body;

        $this->assertEquals(
            __('metawhatsapp::metawhatsapp.media_attachment_unavailable'),
            trim(strip_tags($body)),
            'a message with no caption gained something it should not have'
        );
    }
}
