<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Support\DebugLog;

/**
 * El registre detallat escriu a storage/logs abans de processar el missatge.
 * Si el directori no és escrivible, i passa sovint després d'executar
 * comandes artisan com a root, Monolog llança i el job mor: ni entra ni surt
 * res. L'eina de diagnòstic tombava el canal, i justament el dia que algú
 * l'engegava per investigar una avaria.
 */
class DebugLogWritableTest extends TestCase
{
    use DatabaseTransactions;

    protected $tmp;

    protected function tearDown(): void
    {
        if ($this->tmp && is_dir($this->tmp)) {
            @rmdir($this->tmp);
        }

        parent::tearDown();
    }

    /**
     * Apunta l'aplicació a un storage/ on logs/ no existeix.
     *
     * El cas real és de permisos, però aquests tests corren com a root dins
     * del contenidor i root escriu encara que el mode ho prohibeixi, així que
     * amb un chmod la guarda no veuria mai res. Un directori que no hi és fa
     * que is_writable() torni fals per a tothom, root inclòs, que és
     * exactament la condició que la guarda ha de detectar.
     */
    protected function makeStorageUnwritable(): void
    {
        $this->tmp = sys_get_temp_dir() . '/mw-storage-' . uniqid();
        mkdir($this->tmp, 0777, true);

        $this->app->useStoragePath($this->tmp);
    }

    public function test_the_detailed_log_refuses_to_start_when_it_cannot_write()
    {
        $this->makeStorageUnwritable();

        $response = $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/diagnostics'), [
                'debug_window'    => '1',
                'debug_retention' => 7,
            ]);

        $response->assertStatus(302);
        $response->assertSessionHas('flash_error_floating');

        $this->assertFalse(
            DebugLog::isEnabled(),
            'El registre s\'ha engegat en un directori on no es pot escriure: el canal hauria quedat mut.'
        );
    }

    /**
     * Apagar-lo s'ha de poder fer sempre. Qui ha quedat sense permisos amb el
     * registre engegat ha de poder tornar enrere sense arreglar abans el
     * servidor.
     */
    public function test_turning_it_off_always_works()
    {
        DebugLog::enableFor(1);
        $this->assertTrue(DebugLog::isEnabled());

        $this->makeStorageUnwritable();

        $this->actingAs($this->makeAdminUser())
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post($this->url('/meta-whatsapp/diagnostics'), [
                'debug_window'    => 'off',
                'debug_retention' => 7,
            ]);

        $this->assertFalse(DebugLog::isEnabled());
    }
}
