<?php

return [
    // Base de l'API de Meta. Sobreescrivible per entorn (tests amb mock local).
    'api_base' => env('META_WHATSAPP_API_BASE', 'https://graph.facebook.com'),
];
