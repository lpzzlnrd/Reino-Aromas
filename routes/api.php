<?php

use App\Http\Controllers\Api\Webhooks\InstagramWebhookController;
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
});
