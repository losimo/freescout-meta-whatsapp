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
});

// Webhook de Meta: stateless, SENSE el grup 'web' (sense sessió ni CSRF — spike H5/A7).
Route::group([
    'prefix'    => 'meta-whatsapp',
    'namespace' => 'Modules\MetaWhatsApp\Http\Controllers',
], function () {
    Route::get('/webhook', 'WebhookController@verify')->name('metawhatsapp.webhook.verify');
    Route::post('/webhook', 'WebhookController@receive')->name('metawhatsapp.webhook.receive');
});
