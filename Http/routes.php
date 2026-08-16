<?php

// Administració (sessió + autenticació; el controlador comprova isAdmin()).
Route::group([
    'middleware' => ['web', 'auth'],
    'prefix'     => 'meta-whatsapp',
    'namespace'  => 'Modules\MetaWhatsApp\Http\Controllers',
], function () {
    Route::get('/settings', 'MetaWhatsAppController@settings')->name('metawhatsapp.settings');
    Route::get('/settings/create', 'MetaWhatsAppController@create')->name('metawhatsapp.create');
    Route::post('/settings', 'MetaWhatsAppController@store')->name('metawhatsapp.store');
    Route::get('/settings/{id}/edit', 'MetaWhatsAppController@edit')->name('metawhatsapp.edit');
    Route::put('/settings/{id}', 'MetaWhatsAppController@update')->name('metawhatsapp.update');
    Route::delete('/settings/{id}', 'MetaWhatsAppController@destroy')->name('metawhatsapp.destroy');
    Route::post('/settings/{id}/test-connection', 'MetaWhatsAppController@testConnection')->name('metawhatsapp.test_connection');
    Route::post('/settings/{id}/subscribe-webhook', 'MetaWhatsAppController@subscribeWebhook')->name('metawhatsapp.subscribe_webhook');

    // Banner de finestra expirada: enviament manual de la plantilla de recuperació.
    Route::post('/conversation/{id}/send-template', 'MetaWhatsAppController@sendTemplate')->name('metawhatsapp.send_template');

    // Picker de plantilles en viu (fetch dinàmic de Meta + variables).
    Route::get('/conversation/{id}/templates', 'MetaWhatsAppController@browseTemplates')->name('metawhatsapp.browse_templates');
    Route::post('/conversation/{id}/send-dynamic-template', 'MetaWhatsAppController@sendDynamicTemplate')->name('metawhatsapp.send_dynamic_template');
});

// Webhook de Meta: stateless, SENSE el grup 'web' (sense sessió ni CSRF — spike H5/A7).
Route::group([
    'prefix'    => 'meta-whatsapp',
    'namespace' => 'Modules\MetaWhatsApp\Http\Controllers',
], function () {
    Route::get('/webhook', 'WebhookController@verify')->name('metawhatsapp.webhook.verify');
    Route::post('/webhook', 'WebhookController@receive')->name('metawhatsapp.webhook.receive');
});
