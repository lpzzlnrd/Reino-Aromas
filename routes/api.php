<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Webhooks\MetaWebhookController;
use App\Http\Controllers\Facebook\FacebookAuthController;
use App\Http\Controllers\Facebook\FacebookMessageController;
use App\Http\Controllers\Facebook\FacebookPostController;
use Illuminate\Support\Facades\Route;

Route::prefix('webhooks/meta')->name('webhooks.meta.')->group(function (): void {
    Route::get('/', [MetaWebhookController::class, 'verify'])->name('verify');
    Route::post('/', [MetaWebhookController::class, 'receive'])->name('receive');
});

Route::middleware('auth')->group(function (): void {
    Route::get('facebook/auth/url', [FacebookAuthController::class, 'authUrl'])
        ->name('facebook.auth.url');
    Route::get('facebook/auth/callback', [FacebookAuthController::class, 'callback'])
        ->name('facebook.auth.callback');

    Route::post(
        'conversations/{conversation}/facebook/messages',
        [FacebookMessageController::class, 'store']
    )->name('facebook.messages.store');

    Route::post('facebook/posts', [FacebookPostController::class, 'store'])
        ->name('facebook.posts.store');
});
