<?php

use App\Http\Controllers\Api\Webhooks\InstagramWebhookController;
use App\Http\Controllers\Api\Webhooks\WhatsAppWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks Meta — SIN autenticación
|
| Meta firma cada request con HMAC-SHA256 usando META_APP_SECRET.
| La verificación ocurre dentro de cada controller, no a nivel de middleware.
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

/*
|--------------------------------------------------------------------------
| API autenticada — requiere sesión web (auth:sanctum + cookie session)
|
| El Vue usa las mismas cookies de sesión que el Blade login.
| No se usan tokens Bearer — auth:sanctum con 'stateful' domains es suficiente
| para una SPA en el mismo dominio.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {

    // Usuario autenticado actual — el Vue lo llama al montar para saber
    // el nombre y rol del agente logueado.
    Route::get('/user', fn(Request $request) => $request->user());

    /*
    |----------------------------------------------------------------------
    | Meta chats — conversaciones activas de WhatsApp e Instagram
    |
    | Usado por useMetaData.ts (loadMetaChats → GET /api/meta/chats).
    | TODO Semana 3: implementar ConversationController y apuntar aquí.
    |----------------------------------------------------------------------
    */
    Route::prefix('meta')->group(function () {

        // Lista de chats/conversaciones activas
        Route::get('/chats', function () {
            // Placeholder hasta que ConversationController esté listo (Semana 3).
            // Retorna un array vacío para que el Vue no rompa al montar.
            return response()->json([]);
        })->name('api.meta.chats');

    });

});
