<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Jobs\ProcessInboundWebhook;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;
use Modules\MetaWhatsApp\Models\WhatsAppMessage;
use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

/**
 * T30. The module downloaded the attachment and then checked whether it had
 * already processed the message, which is the wrong way round for a webhook
 * Meta redelivers whenever it does not get a 200 in time.
 *
 * Nothing was corrupted by it, because the duplicate check did its job. What
 * it cost was a second full download of the same file from the Graph API,
 * every time, discarded immediately afterwards.
 */
class InboundDuplicateDownloadTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_redelivered_media_message_is_not_downloaded_a_second_time()
    {
        $account = $this->createTestAccount();

        $payload = $this->inboundPayload($account, 'wamid.dup1', '34611222333', 'irrelevant');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['type']  = 'image';
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['image'] = [
            'id'      => 'media-dup-1',
            'caption' => 'Mira això',
        ];
        unset($payload['entry'][0]['changes'][0]['value']['messages'][0]['text']);

        $downloads  = 0;
        $fakeClient = \Mockery::mock(WhatsAppApiClient::class);
        $fakeClient->shouldReceive('downloadMedia')->andReturnUsing(function () use (&$downloads) {
            $downloads++;

            return [
                'ok' => true, 'bytes' => 'fake-image-bytes', 'mime_type' => 'image/jpeg',
                'http_status' => 200, 'error_code' => null, 'error_message' => null, 'transient' => false,
            ];
        });

        // The same delivery twice, which is what Meta does when our 200 is
        // slow. Two separate job runs, one shared client, so the count is of
        // calls that actually left the module.
        foreach ([1, 2] as $attempt) {
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

        $this->assertEquals(
            1,
            $downloads,
            'the file was fetched from the Graph API once per redelivery'
        );
        $this->assertEquals(
            1,
            WhatsAppMessage::where('wamid', 'wamid.dup1')->count(),
            'the duplicate check itself must keep working'
        );
    }

    /**
     * The guard has to stay below the one that refuses a message with no
     * readable sender, or a redelivery of that message would be answered with
     * silence instead of the log line that says what happened.
     */
    public function test_a_first_delivery_still_downloads()
    {
        $account = $this->createTestAccount();

        $payload = $this->inboundPayload($account, 'wamid.dup2', '34611222333', 'irrelevant');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['type']  = 'document';
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['document'] = [
            'id'        => 'media-dup-2',
            'filename'  => 'factura.pdf',
            'mime_type' => 'application/pdf',
        ];
        unset($payload['entry'][0]['changes'][0]['value']['messages'][0]['text']);

        $downloads  = 0;
        $fakeClient = \Mockery::mock(WhatsAppApiClient::class);
        $fakeClient->shouldReceive('downloadMedia')->andReturnUsing(function () use (&$downloads) {
            $downloads++;

            return [
                'ok' => true, 'bytes' => '%PDF-fake', 'mime_type' => 'application/pdf',
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

        $this->assertEquals(1, $downloads);
        $record = WhatsAppMessage::where('wamid', 'wamid.dup2')->first();
        $this->assertNotNull($record);
        $this->assertTrue((bool) \App\Thread::find($record->thread_id)->has_attachments);
    }
}
