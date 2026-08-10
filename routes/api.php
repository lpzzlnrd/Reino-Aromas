<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Flows\FlowEndpointController;
use App\Http\Controllers\Api\Webhooks\InstagramWebhookController;
use App\Http\Controllers\Api\Webhooks\WhatsAppWebhookController;
use App\Http\Controllers\Facebook\FacebookAuthController;
use App\Http\Controllers\Facebook\FacebookMessageController;
use App\Http\Controllers\Facebook\FacebookPostController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\UserController;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|=============================================================================
| API Routes — Reino Aromas CRM
|
| Convención de respuesta (heredada de MetaBaseController):
|   éxito  → { "status": true,  ...datos }
|   error  → { "status": false, "message": "..." }
|
| Autenticación: cookie de sesión Laravel (Sanctum stateful).
| El Vue envía el header X-XSRF-TOKEN que axios lee del cookie automáticamente.
| No se usan tokens Bearer.
|=============================================================================
*/

/*
|-----------------------------------------------------------------------------
| Webhooks Meta — SIN autenticación
|
| Meta firma cada request con HMAC-SHA256 (X-Hub-Signature-256).
| La verificación ocurre dentro de cada controller.
| Estos endpoints deben ser accesibles públicamente para que Meta los alcance.
|-----------------------------------------------------------------------------
*/
Route::prefix('webhooks')->group(function (): void {

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
|-----------------------------------------------------------------------------
| WhatsApp Flows — canal de datos, SIN autenticación de sesión
|
| Meta llama a este endpoint desde sus servidores: no hay cookie ni usuario.
| La seguridad es doble y va dentro del controlador:
|   1. firma HMAC en X-Hub-Signature-256 (responde 432 si no valida)
|   2. cifrado híbrido RSA + AES-GCM (responde 421 si no descifra)
|
| Se agrupa bajo /webhooks para que toda la superficie pública que llama Meta
| viva bajo el mismo prefijo — así una regla de nginx o un rate-limit futuro
| cubre todo de una vez. Técnicamente NO es un webhook (es un canal
| bidireccional), de ahí el segmento 'flows' que lo distingue.
|
| Esta URL se configura en el Flow Builder de WhatsApp Manager.
|-----------------------------------------------------------------------------
*/
Route::post('webhooks/flows', FlowEndpointController::class)->name('webhooks.flows');

/*
|-----------------------------------------------------------------------------
| Rutas autenticadas — requieren sesión activa (auth:sanctum stateful)
|-----------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function (): void {

    /*
    |-------------------------------------------------------------------------
    | Usuario autenticado
    | GET /api/user
    |
    | El Vue lo llama al montar (App.vue o composable de sesión) para saber
    | el nombre, rol y avatar del agente logueado.
    | Responde con los campos seguros del modelo User (password y
    | remember_token están en $hidden).
    |-------------------------------------------------------------------------
    */
    Route::get('/user', fn(Request $request) => response()->json($request->user()))
        ->name('api.user');

    /*
    |-------------------------------------------------------------------------
    | Gestión de usuarios administradores — solo superadmin
    |-------------------------------------------------------------------------
    */
    Route::get('/users', [UserController::class, 'index'])->name('api.users.index');
    Route::post('/users', [UserController::class, 'store'])->name('api.users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('api.users.update');
    Route::patch('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('api.users.toggle-active');

    /*
    |-------------------------------------------------------------------------
    | Plantillas de respuesta rápida
    |
    | El gestor (/app/settings/templates) consume el CRUD; el selector dentro
    | del chat usa forConversation + markUsed.
    |
    | OJO con el orden: /templates/preview va ANTES de las rutas con
    | {template}, o Laravel intentaría resolver "preview" como un ID y
    | devolvería 404.
    |-------------------------------------------------------------------------
    */
    Route::post('/templates/preview', [TemplateController::class, 'preview'])->name('api.templates.preview');

    Route::get('/templates', [TemplateController::class, 'index'])->name('api.templates.index');
    Route::post('/templates', [TemplateController::class, 'store'])->name('api.templates.store');
    Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('api.templates.update');
    Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('api.templates.destroy');
    Route::patch('/templates/{template}/toggle-active', [TemplateController::class, 'toggleActive'])->name('api.templates.toggle-active');
    Route::post('/templates/{template}/use', [TemplateController::class, 'markUsed'])->name('api.templates.use');

    // Plantillas aplicables a una conversación, ya renderizadas con los datos
    // del contacto. Vive fuera del prefijo /meta porque no depende de Meta.
    Route::get('/conversations/{conversation}/templates', [TemplateController::class, 'forConversation'])
        ->name('api.conversations.templates');

    /*
    |=========================================================================
    | Meta — conversaciones y mensajes
    |
    | Agrupa los endpoints que el Vue consume para la bandeja de mensajes.
    | Los canales soportados hoy: facebook, instagram, whatsapp.
    |=========================================================================
    */
    Route::prefix('meta')->name('api.meta.')->group(function (): void {

        /*
        |---------------------------------------------------------------------
        | GET /api/meta/chats
        |
        | Lista de conversaciones activas (status = 'open') con el último
        | mensaje de cada una. Usado por useMetaData.ts → loadMetaChats().
        |
        | Estructura de cada item (mapeada al tipo MetaApiChat del frontend):
        |   id            → conversation.id
        |   contact_name  → contact.display_name
        |   contact_avatar→ contact.profile_picture_url
        |   last_message  → último mensaje de la conversación
        |   message_time  → last_message_at de la conversación
        |   location      → contact.city
        |   case_status   → ticket.status (si existe) o 'Nuevo'
        |   channel       → contact.channel (facebook | instagram | whatsapp)
        |---------------------------------------------------------------------
        */
        Route::get('/chats', function () {
            $conversations = Conversation::query()
                ->where('status', 'open')
                ->with([
                    'contact',
                    // Solo el último mensaje para no cargar toda la historia
                    'messages' => fn($q) => $q->latest('created_at')->limit(1),
                    'ticket',
                ])
                ->orderByDesc('last_message_at')
                ->get()
                ->map(fn(Conversation $conv) => [
                    'id'             => $conv->id,
                    'contact_name'   => $conv->contact?->display_name ?? 'Sin nombre',
                    'contact_avatar' => $conv->contact?->profile_picture_url,
                    'last_message'   => $conv->messages->first()?->body ?? '',
                    'message_time'   => $conv->last_message_at?->toIso8601String(),
                    'location'       => $conv->contact?->city,
                    // El Vue espera uno de los valores del enum CaseStatus.ts
                    // Mapeamos el status del ticket al valor en español del enum
                    'case_status'    => match($conv->ticket?->status) {
                        'interested'   => 'Interesado',
                        'high_priority'=> 'Urgente',
                        'following'    => 'En seguimiento',
                        'reserved'     => 'Reservado',
                        'closed'       => 'Cerrado',
                        default        => 'Nuevo',
                    },
                    'channel'        => $conv->contact?->channel,
                ]);

            return response()->json($conversations);
        })->name('chats');

        /*
        |---------------------------------------------------------------------
        | GET /api/meta/conversations/{conversation}
        |
        | Detalle de una conversación: datos del contacto + historial completo
        | de mensajes. El Vue lo carga cuando el agente abre un chat.
        |
        | Devuelve 403 si la conversación no pertenece al usuario actual.
        | (Semana 3: aquí irá la policy ConversationPolicy)
        |---------------------------------------------------------------------
        */
        Route::get('/conversations/{conversation}', function (Conversation $conversation) {
            $conversation->load([
                'contact',
                'messages.sender:id,name,avatar_url',
                'ticket.assignedUser:id,name',
                'ticket.tags:id,name,color',
            ]);

            return response()->json([
                'id'              => $conversation->id,
                'status'          => $conversation->status,
                'within_24h_window' => $conversation->within_24h_window,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'contact'         => [
                    'id'                  => $conversation->contact?->id,
                    'display_name'        => $conversation->contact?->display_name,
                    'profile_picture_url' => $conversation->contact?->profile_picture_url,
                    'channel'             => $conversation->contact?->channel,
                    'channel_id'          => $conversation->contact?->channel_id,
                    'city'                => $conversation->contact?->city,
                    'phone'               => $conversation->contact?->phone,
                    'instagram_handle'    => $conversation->contact?->instagram_handle,
                    'first_seen_at'       => $conversation->contact?->first_seen_at?->toIso8601String(),
                ],
                'ticket'          => $conversation->ticket ? [
                    'id'            => $conversation->ticket->id,
                    'status'        => $conversation->ticket->status,
                    'priority'      => $conversation->ticket->priority,
                    'city'          => $conversation->ticket->city,
                    'course_interest' => $conversation->ticket->course_interest,
                    'notes'         => $conversation->ticket->notes,
                    'assigned_user' => $conversation->ticket->assignedUser ? [
                        'id'     => $conversation->ticket->assignedUser->id,
                        'name'   => $conversation->ticket->assignedUser->name,
                        'avatar' => $conversation->ticket->assignedUser->avatar_url,
                    ] : null,
                    'tags'          => $conversation->ticket->tags->map(fn($tag) => [
                        'id'    => $tag->id,
                        'name'  => $tag->name,
                        'color' => $tag->color,
                    ]),
                ] : null,
                'messages'        => $conversation->messages->map(fn($msg) => [
                    'id'          => $msg->id,
                    'direction'   => $msg->direction,   // inbound | outbound
                    'channel'     => $msg->channel,
                    'type'        => $msg->type,        // text | image | audio | ...
                    'body'        => $msg->body,
                    'media_url'   => $msg->media_url,
                    'status'      => $msg->status,      // sent | delivered | read | failed
                    'sender'      => $msg->sender ? [
                        'id'     => $msg->sender->id,
                        'name'   => $msg->sender->name,
                        'avatar' => $msg->sender->avatar_url,
                    ] : null,
                    'sent_at'     => $msg->sent_at?->toIso8601String(),
                    'delivered_at'=> $msg->delivered_at?->toIso8601String(),
                    'read_at'     => $msg->read_at?->toIso8601String(),
                    'created_at'  => $msg->created_at->toIso8601String(),
                ]),
            ]);
        })->name('conversations.show');

        /*
        |---------------------------------------------------------------------
        | POST /api/meta/conversations/{conversation}/messages
        |
        | Envía un mensaje saliente desde el CRM hacia el canal del contacto.
        | Delega al controlador correcto según el canal (facebook por ahora;
        | instagram y whatsapp se conectan en Semana 3).
        |
        | Body esperado: { "body": "texto del mensaje" }
        |---------------------------------------------------------------------
        */
        Route::post('/conversations/{conversation}/messages', FacebookMessageController::class . '@store')
            ->name('conversations.messages.store');

        /*
        |=====================================================================
        | Facebook — autenticación OAuth y páginas
        |=====================================================================
        */
        Route::prefix('facebook')->name('facebook.')->group(function (): void {

            /*
            |----------------------------------------------------------------
            | GET /api/meta/facebook/auth-url
            |
            | Genera la URL de autorización OAuth de Facebook.
            | El Vue la usa en settings.accounts.vue para el botón
            | "Vincular cuenta" de Facebook.
            |
            | Respuesta: { "status": true, "auth_url": "https://facebook.com/..." }
            |----------------------------------------------------------------
            */
            Route::get('/auth-url', [FacebookAuthController::class, 'authUrl'])
                ->name('auth-url');

            /*
            |----------------------------------------------------------------
            | GET /api/meta/facebook/callback
            |
            | Recibe el código OAuth de Facebook tras autorizar la app.
            | Intercambia el código por un token de larga duración y
            | devuelve las páginas disponibles para vincular.
            |
            | Respuesta: { "status": true, "access_token": "...", "pages": [...] }
            |----------------------------------------------------------------
            */
            Route::get('/callback', [FacebookAuthController::class, 'callback'])
                ->name('callback');

            /*
            |----------------------------------------------------------------
            | POST /api/meta/facebook/posts
            |
            | Publica un post en la página de Facebook configurada.
            | Body esperado: { "message": "texto del post" }
            |
            | Respuesta: { "status": true, "post_id": "...", "payload": {...} }
            |----------------------------------------------------------------
            */
            Route::post('/posts', [FacebookPostController::class, 'store'])
                ->name('posts.store');

        });

    });

});
