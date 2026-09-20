<?php

namespace Modules\MetaWhatsApp\Support;

/**
 * Les coses que fan que el mòdul no funcioni i que no són del mòdul.
 *
 * Surten d'una auditoria del 2026-09-18 que preguntava què dona per fet aquest
 * codi sobre l'entorn. La majoria no les podem arreglar: el cron, el
 * controlador de cues o el tallafocs són de qui té la màquina. El que sí que
 * podem fer, i és tota la raó d'aquesta classe, és **dir-ho**. La diferència
 * entre un administrador que sap què mirar i un que veu que no arriba res.
 *
 * El cas que ho justifica: si el worker de cua no corre, el webhook contesta
 * 200 a Meta perquè encuar ja és èxit, el missatge de l'agent queda a la
 * conversa com si hagués sortit, i les feines s'apilen en silenci. No hi ha
 * cap error enlloc. És el pitjor perfil possible i el més difícil de sospitar.
 */
class EnvironmentCheck
{
    /** Una feina encuada més vella que això vol dir que ningú la processa. */
    const STALE_JOB_MINUTES = 10;

    /** Per sota d'això, un document gros de WhatsApp pot tombar el worker. */
    const LOW_MEMORY_MB = 192;

    /**
     * Cada problema: clau, gravetat ('problem' o 'warning'), i el text ja
     * traduït. La clau serveix per als tests i per no duplicar-ne cap.
     */
    public static function problems(): array
    {
        $found = [];

        foreach (['queueStalled', 'queueSync', 'curlMissing', 'insecureAppUrl', 'logsNotWritable', 'lowMemory'] as $check) {
            $problem = self::$check();

            if ($problem !== null) {
                $found[] = $problem;
            }
        }

        return $found;
    }

    /**
     * Feines encuades que ningú recull. És la comprovació que més suport
     * estalvia, perquè el símptoma que veu l'usuari ("no entra ni surt res")
     * no apunta enlloc.
     */
    protected static function queueStalled(): ?array
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        try {
            $oldest = \DB::table('jobs')->min('available_at');
        } catch (\Exception $e) {
            // Sense taula de feines no hi ha res a dir; no és feina d'aquesta
            // pantalla diagnosticar la base de dades.
            return null;
        }

        if (!$oldest) {
            return null;
        }

        $minutes = (int) floor((time() - (int) $oldest) / 60);

        if ($minutes < self::STALE_JOB_MINUTES) {
            return null;
        }

        return [
            'key'      => 'queue_stalled',
            'severity' => 'problem',
            'title'    => __('metawhatsapp::metawhatsapp.env_queue_stalled_title'),
            'detail'   => __('metawhatsapp::metawhatsapp.env_queue_stalled_detail', ['minutes' => $minutes]),
        ];
    }

    /**
     * Amb el controlador `sync` el mòdul sembla que funciona, i tres coses
     * deixen de ser certes. La pitjor: el nucli encua la sortida amb el retard
     * del botó Desfés, i `sync` ignora els retards, o sigui que el missatge ja
     * ha arribat al client quan l'agent prem Desfés.
     */
    protected static function queueSync(): ?array
    {
        if (config('queue.default') !== 'sync') {
            return null;
        }

        return [
            'key'      => 'queue_sync',
            'severity' => 'warning',
            'title'    => __('metawhatsapp::metawhatsapp.env_queue_sync_title'),
            'detail'   => __('metawhatsapp::metawhatsapp.env_queue_sync_detail'),
        ];
    }

    protected static function curlMissing(): ?array
    {
        if (function_exists('curl_init')) {
            return null;
        }

        return [
            'key'      => 'curl_missing',
            'severity' => 'problem',
            'title'    => __('metawhatsapp::metawhatsapp.env_curl_title'),
            'detail'   => __('metawhatsapp::metawhatsapp.env_curl_detail'),
        ];
    }

    /**
     * Meta només entrega a https amb certificat públic vàlid. Si l'APP_URL no
     * és https, la URL que aquesta pantalla ensenya per enganxar a Meta no
     * funcionarà, i el mòdul quedarà com el culpable.
     */
    protected static function insecureAppUrl(): ?array
    {
        if (strpos((string) config('app.url'), 'https://') === 0) {
            return null;
        }

        return [
            'key'      => 'insecure_app_url',
            'severity' => 'problem',
            'title'    => __('metawhatsapp::metawhatsapp.env_app_url_title'),
            'detail'   => __('metawhatsapp::metawhatsapp.env_app_url_detail'),
        ];
    }

    /**
     * El registre detallat escriu a storage/logs abans de processar res. Si
     * el directori no és escrivible, Monolog llança i el job mor: l'eina de
     * diagnòstic trenca el canal, i just quan algú investigava un problema.
     */
    protected static function logsNotWritable(): ?array
    {
        if (is_writable(storage_path('logs'))) {
            return null;
        }

        return [
            'key'      => 'logs_not_writable',
            'severity' => 'problem',
            'title'    => __('metawhatsapp::metawhatsapp.env_logs_title'),
            'detail'   => __('metawhatsapp::metawhatsapp.env_logs_detail'),
        ];
    }

    /**
     * El mèdia entrant viatja sencer en memòria. WhatsApp accepta documents
     * de fins a 100 MB i molts allotjaments compartits van a 64M o 128M.
     */
    protected static function lowMemory(): ?array
    {
        $limit = self::memoryLimitMb();

        if ($limit === null || $limit >= self::LOW_MEMORY_MB) {
            return null;
        }

        return [
            'key'      => 'low_memory',
            'severity' => 'warning',
            'title'    => __('metawhatsapp::metawhatsapp.env_memory_title'),
            'detail'   => __('metawhatsapp::metawhatsapp.env_memory_detail', ['limit' => $limit]),
        ];
    }

    /** null si no hi ha límit (-1) o no es pot llegir. */
    public static function memoryLimitMb(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $unit  = strtolower(substr($raw, -1));
        $value = (int) $raw;

        if ($unit === 'g') {
            return $value * 1024;
        }
        if ($unit === 'm') {
            return $value;
        }
        if ($unit === 'k') {
            return (int) floor($value / 1024);
        }

        return (int) floor($value / 1048576);
    }
}
