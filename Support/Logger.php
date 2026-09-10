<?php

namespace Modules\MetaWhatsApp\Support;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger as Monolog;

class Logger
{
    /**
     * Debug-log structured data (webhook payloads, API requests/responses).
     *
     * Array values in $context are JSON-encoded before logging: Monolog's
     * normalizer has a max depth of 9, and WhatsApp webhook payloads nest
     * past that, so logging them as raw arrays silently truncates every
     * value below the limit to "Over 9 levels deep, aborting normalization".
     *
     * When detailed logging is on (see DebugLog), also writes to a
     * module-only log file at debug level, independent of the app-wide log
     * level. That file holds message text and phone numbers, which is why
     * DebugLog carries a window and a retention rather than a plain switch.
     */
    public static function debugData(string $message, array $context): void
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        Log::debug($message, $context);

        if (DebugLog::isEnabled()) {
            // Rotació diària (issue #10 follow-up), mateix patró que Laravel
            // fa servir al canal 'daily': metawhatsapp-debug-YYYY-MM-DD.log.
            // La retenció ja no és fixa a 7: la decideix l'administrador des
            // del panell, perquè és el seu servidor i les seves dades.
            $logger = new Monolog('metawhatsapp');
            $logger->pushHandler(new RotatingFileHandler(
                storage_path('logs/metawhatsapp-debug.log'),
                DebugLog::retentionDays(),
                Monolog::DEBUG
            ));
            $logger->debug($message, $context);
        }
    }
}
