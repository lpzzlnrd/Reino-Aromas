<?php

use App\Http\Controllers\Api\Webhooks\InstagramWebhookController;
use App\Http\Controllers\Api\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks Meta — sin auth middleware, Meta firma cada request con HMAC
|--------------------------------------------------------------------------
*/
Route::prefix('webhooks')->group(function () {

    Route::get('instagram', [InstagramWebhookController::class, 'verify'])
        ->name('webhooks.instagram.verify');

    Route::post('instagram', [InstagramWebhookController::class, 'receive'])
        ->name('webhooks.instagram.receive');

    Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify'])
        ->name('webhooks.whatsapp.verify');

    Route::post('whatsapp', [WhatsAppWebhookController::class, 'receive'])
        ->name('webhooks.whatsapp.receive');
});
