<?php

namespace Modules\MetaWhatsApp\Tests;

use Illuminate\Support\Facades\Log;
use Modules\MetaWhatsApp\Support\Logger;

class LoggerTest extends TestCase
{
    public function test_debug_data_serialitza_a_json_els_valors_array_per_evitar_truncament_de_monolog()
    {
        // 10 nivells d'imbricació — per sobre del límit per defecte de Monolog (9),
        // el mateix patró que provocava "Over 9 levels deep..." als payloads de Meta.
        $deep = 'leaf';
        for ($i = 0; $i < 10; $i++) {
            $deep = ['level' . $i => $deep];
        }

        Log::shouldReceive('debug')
            ->once()
            ->with('[MetaWhatsApp] test message', \Mockery::on(function ($context) use ($deep) {
                return $context['account_id'] === 1
                    && is_string($context['payload'])
                    && $context['payload'] === json_encode($deep, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }));

        Logger::debugData('[MetaWhatsApp] test message', ['account_id' => 1, 'payload' => $deep]);
    }

    /**
     * Nom real del fitxer amb rotació diària de Monolog\RotatingFileHandler:
     * '{filename}-{date}' amb dateFormat 'Y-m-d', inserit abans de l'extensió.
     */
    protected function rotatedLogPath(): string
    {
        return storage_path('logs/metawhatsapp-debug-' . date('Y-m-d') . '.log');
    }

    public function test_debug_data_escriu_al_fitxer_de_log_propi_amb_data_quan_el_debug_del_modul_esta_actiu()
    {
        config(['metawhatsapp.debug' => true]);
        $path = $this->rotatedLogPath();
        if (file_exists($path)) {
            unlink($path);
        }

        Log::shouldReceive('debug')->once();

        Logger::debugData('[MetaWhatsApp] enabled test', ['account_id' => 42]);

        $this->assertTrue(file_exists($path));
        $this->assertStringContainsString('[MetaWhatsApp] enabled test', file_get_contents($path));

        unlink($path);
        config(['metawhatsapp.debug' => false]);
    }

    public function test_debug_data_no_escriu_fitxer_de_log_propi_quan_el_debug_del_modul_esta_desactivat()
    {
        config(['metawhatsapp.debug' => false]);
        $path = $this->rotatedLogPath();
        if (file_exists($path)) {
            unlink($path);
        }

        Log::shouldReceive('debug')->once();

        Logger::debugData('[MetaWhatsApp] disabled test', ['account_id' => 7]);

        $this->assertFalse(file_exists($path));
    }
}
