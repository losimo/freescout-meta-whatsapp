<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\MetaWhatsApp\Support\EnvironmentCheck;

/**
 * Les comprovacions d'entorn no arreglen res: diuen. Per això el que s'ha de
 * provar no és que detectin, sinó que **arribin a la pantalla**, que és l'únic
 * lloc on serveixen d'alguna cosa.
 */
class EnvironmentCheckTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * En una instal·lació que funciona no hi pot haver cap alerta vermella.
     * Els avisos grocs sí que poden sortir, i de fet aquest contenidor en té
     * un de legítim: va amb memory_limit de 128M, que és curt per a un adjunt
     * de WhatsApp gros. Aquesta és la distinció que manté la pantalla
     * llegible: si crida el llop en vermell, ningú la mirarà el dia que
     * importi.
     */
    public function test_a_working_install_raises_no_red_alert()
    {
        $red = array_filter(EnvironmentCheck::problems(), function ($p) {
            return $p['severity'] === 'problem';
        });

        $this->assertEmpty(
            $red,
            'Una comprovació crida el llop en un entorn que funciona: ' . implode(', ', array_column($red, 'key'))
        );
    }

    public function test_the_sync_queue_driver_is_reported()
    {
        config(['queue.default' => 'sync']);

        $keys = array_column(EnvironmentCheck::problems(), 'key');

        $this->assertContains('queue_sync', $keys);
    }

    public function test_an_app_url_without_https_is_reported()
    {
        config(['app.url' => 'http://example.com']);

        $keys = array_column(EnvironmentCheck::problems(), 'key');

        $this->assertContains('insecure_app_url', $keys);
    }

    /**
     * El cas que més suport estalvia: feines encuades que ningú recull. El
     * símptoma que veu l'usuari, "no entra ni surt res", no apunta enlloc.
     */
    public function test_jobs_waiting_for_too_long_are_reported()
    {
        config(['queue.default' => 'database']);

        \DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => '{}',
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => time() - ((EnvironmentCheck::STALE_JOB_MINUTES + 5) * 60),
            'created_at'   => time(),
        ]);

        $problems = EnvironmentCheck::problems();
        $keys     = array_column($problems, 'key');

        $this->assertContains('queue_stalled', $keys);

        $stalled = $problems[array_search('queue_stalled', $keys)];
        $this->assertStringContainsString('15', $stalled['detail'], 'Ha de dir quants minuts fa que espera.');
    }

    public function test_a_job_queued_a_moment_ago_is_not_reported()
    {
        config(['queue.default' => 'database']);

        \DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => '{}',
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => time(),
            'created_at'   => time(),
        ]);

        $keys = array_column(EnvironmentCheck::problems(), 'key');

        $this->assertNotContains('queue_stalled', $keys, 'Una cua que treballa no és cap problema.');
    }

    public function test_the_memory_limit_is_read_in_megabytes()
    {
        $this->assertEquals(128, $this->callLimit('128M'));
        $this->assertEquals(1024, $this->callLimit('1G'));
        $this->assertNull($this->callLimit('-1'));
    }

    protected function callLimit(string $raw): ?int
    {
        $previous = ini_set('memory_limit', $raw);
        $value    = EnvironmentCheck::memoryLimitMb();
        ini_set('memory_limit', $previous);

        return $value;
    }

    /**
     * La porta que importa: que surti a la pantalla. Una comprovació que
     * detecta i no es pinta enlloc no serveix de res, i aquest mòdul ja ha
     * publicat codi correcte darrere d'una porta que ningú travessava.
     */
    public function test_the_settings_screen_shows_the_problem()
    {
        config(['queue.default' => 'sync']);

        $response = $this->actingAs($this->makeAdminUser())
            ->get($this->url('/meta-whatsapp/settings'));

        $response->assertStatus(200);
        $this->assertStringContainsString('metawhatsapp-env-notice', $response->getContent());
        $this->assertStringContainsString(
            __('metawhatsapp::metawhatsapp.env_queue_sync_title'),
            $response->getContent()
        );
    }
}
