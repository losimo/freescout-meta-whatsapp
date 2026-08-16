<?php

return [
    // Meta API base URL. Overridable per environment (local mock for tests).
    'api_base' => env('META_WHATSAPP_API_BASE', 'https://graph.facebook.com'),

    // Force debug-level logging for this module only, independent of the
    // app-wide log level (APP_LOG_LEVEL). Writes to
    // storage/logs/metawhatsapp-debug-YYYY-MM-DD.log (rotated daily, 7-day
    // retention) instead of putting the whole FreeScout instance into debug
    // mode. Set METAWHATSAPP_DEBUG=true in FreeScout's own .env file — do
    // not hardcode true here, .env survives module updates and this file
    // may get overwritten by them.
    'debug' => env('METAWHATSAPP_DEBUG', false),
];
