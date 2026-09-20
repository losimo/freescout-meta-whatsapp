<?php

namespace Modules\MetaWhatsApp\Tests;

use Modules\MetaWhatsApp\Services\WhatsAppApiClient;

/**
 * El mèdia entrant es guarda sencer en memòria mentre es baixa. WhatsApp
 * accepta documents de fins a 100 MB i molts allotjaments compartits van a
 * 64M o 128M, o sigui que un fitxer gros tomba el worker.
 *
 * El que fa que això valgui la pena i no sigui un detall: quan el worker mor,
 * **es perd el missatge sencer**, no només l'adjunt, perquè el fil es crea
 * dins la mateixa transacció. El client va escriure i no apareix res.
 */
class MediaMemoryGuardTest extends TestCase
{
    protected function guard($fileSize, string $limit): ?string
    {
        $account = new \Modules\MetaWhatsApp\Models\WhatsAppAccount();
        $account->access_token = encrypt(self::TEST_ACCESS_TOKEN);

        $client = new WhatsAppApiClient($account);

        $method = new \ReflectionMethod($client, 'mediaTooBigForMemory');
        $method->setAccessible(true);

        $previous = ini_set('memory_limit', $limit);
        $result   = $method->invoke($client, $fileSize);
        ini_set('memory_limit', $previous);

        return $result;
    }

    public function test_a_file_that_does_not_fit_is_refused_with_the_numbers_in_it()
    {
        // 60 MB amb 128M de límit: en calen uns 180 i no hi caben.
        $message = $this->guard(60 * 1048576, '128M');

        $this->assertNotNull($message, 'Un fitxer que no cap s\'hauria de refusar abans de baixar-lo.');
        $this->assertStringContainsString('60', $message);
        $this->assertStringContainsString('128', $message);
        $this->assertStringContainsString('The message was kept', $message);
    }

    public function test_a_small_file_passes()
    {
        $this->assertNull($this->guard(2 * 1048576, '128M'));
    }

    /**
     * Amb memòria il·limitada o mida desconeguda no es refusa res: val més
     * intentar-ho que rebutjar un fitxer que hauria anat bé.
     */
    public function test_nothing_is_refused_when_we_cannot_know()
    {
        $this->assertNull($this->guard(90 * 1048576, '-1'));
        $this->assertNull($this->guard(null, '128M'));
        $this->assertNull($this->guard(0, '128M'));
    }
}
