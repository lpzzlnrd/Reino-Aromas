<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Flows\FlowEndpointController;
use App\Http\Controllers\Api\Webhooks\FacebookWebhookController;
use App\Http\Controllers\Api\Webhooks\InstagramWebhookController;
use App\Http\Controllers\Api\Webhooks\WhatsAppWebhookController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\Facebook\FacebookAuthController;
use App\Http\Controllers\Facebook\FacebookPostController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UserController;
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

    /*
    | Facebook Messenger. Cada red necesita su propia URL: aunque el verify
    | token es común y Meta valide igual, cada controlador descarta los eventos
    | cuyo `object` no le corresponde. Apuntar la Página a la URL de WhatsApp
    | hace que los mensajes se pierdan sin dejar rastro en los logs.
    */
    Route::get('facebook', [FacebookWebhookController::class, 'verify'])
        ->name('webhooks.facebook.verify');

    Route::post('facebook', [FacebookWebhookController::class, 'receive'])
        ->name('webhooks.facebook.receive');

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
    |-------------------------------------------------------------------------
    | Tickets
    |
    | Alimentan el Kanban y el panel lateral del chat. Los cambios de estado,
    | prioridad y asignación pasan por TicketService para que queden en
    | activity_logs.
    |
    | /tickets/counts va ANTES de /tickets/{ticket} o Laravel resolvería
    | "counts" como un id y devolvería 404.
    |-------------------------------------------------------------------------
    */
    Route::get('/tickets/counts', [TicketController::class, 'counts'])->name('api.tickets.counts');

    Route::get('/tickets', [TicketController::class, 'index'])->name('api.tickets.index');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('api.tickets.show');
    Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])->name('api.tickets.update');
    Route::put('/tickets/{ticket}/tags', [TicketController::class, 'syncTags'])->name('api.tickets.tags');

    /*
    |-------------------------------------------------------------------------
    | Etiquetas
    |
    | Catálogo para el selector del panel del chat. Es la contraparte de
    | PUT /tickets/{id}/tags: ese endpoint recibe tag_ids, y sin este no había
    | forma de saber qué ids existen.
    |
    | Solo lectura: las etiquetas las define el negocio y viven en TagsSeeder.
    |-------------------------------------------------------------------------
    */
    Route::get('/tags', [TagController::class, 'index'])->name('api.tags.index');

    /*
    |-------------------------------------------------------------------------
    | Reportes del panel de control
    |
    | /reports/summary trae los cuatro bloques del dashboard en una sola
    | llamada: cuatro requests en paralelo al montar la vista solo suman
    | latencia y parpadeo.
    |-------------------------------------------------------------------------
    */
    Route::get('/reports/summary', [ReportController::class, 'summary'])->name('api.reports.summary');
    Route::get('/reports/by-city', [ReportController::class, 'byCity'])->name('api.reports.by-city');
    Route::get('/reports/activity', [ReportController::class, 'activity'])->name('api.reports.activity');

    /*
    |-------------------------------------------------------------------------
    | Contactos
    |
    | Sin POST: los contactos nacen de los webhooks de Meta. Crearlos a mano
    | daría registros sin channel_id que ningún canal puede alcanzar.
    |-------------------------------------------------------------------------
    */
    Route::get('/contacts', [ContactController::class, 'index'])->name('api.contacts.index');
    Route::get('/contacts/{contact}', [ContactController::class, 'show'])->name('api.contacts.show');
    Route::patch('/contacts/{contact}', [ContactController::class, 'update'])->name('api.contacts.update');

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
        | Lista de conversaciones para la bandeja, con el último mensaje de
        | cada una. Usado por useMetaData.ts → loadMetaChats().
        |
        | Estructura de cada item (tipo MetaApiChat del frontend):
        |   id            → conversation.id
        |   contact_name  → contact.display_name
        |   contact_avatar→ contact.profile_picture_url
        |   last_message  → último mensaje de la conversación
        |   message_time  → last_message_at de la conversación
        |   location      → contact.city
        |   case_status   → etiqueta del enum CaseStatus.ts, o 'Nuevo' sin ticket
        |   channel       → contact.channel (facebook | instagram | whatsapp)
        |
        | Filtros opcionales: ?status=&channel=&case=&search=&mine=1
        |---------------------------------------------------------------------
        */
        Route::get('/chats', [ConversationController::class, 'index'])->name('chats');

        /*
        |---------------------------------------------------------------------
        | GET /api/meta/conversations/{conversation}
        |
        | Detalle de una conversacion: contacto, ticket e historial completo
        | de mensajes. El Vue lo carga cuando el agente abre un chat.
        |---------------------------------------------------------------------
        */
        Route::get("/conversations/{conversation}", [ConversationController::class, "show"])
            ->name("conversations.show");

        // Cierre y reapertura del chat. No tocan el ticket: son ciclos de vida
        // distintos — el chat puede cerrarse con el ticket aun en seguimiento.
        Route::patch("/conversations/{conversation}/close", [ConversationController::class, "close"])
            ->name("conversations.close");

        Route::patch("/conversations/{conversation}/reopen", [ConversationController::class, "reopen"])
            ->name("conversations.reopen");

        /*
        |---------------------------------------------------------------------
        | POST /api/meta/conversations/{conversation}/messages
        |
        | Encola un mensaje saliente hacia el canal del contacto.
        |
        | MessageController no sabe de canales: OutboundMessageService elige el
        | Job segun contact.channel. Antes esta ruta apuntaba directo a
        | FacebookMessageController, asi que responder un WhatsApp intentaba
        | salir por Facebook.
        |
        | Body esperado: { "body": "texto del mensaje" }
        | Responde 202: el envio real ocurre en la cola y el mensaje nace
        | en estado "pending".
        |---------------------------------------------------------------------
        */
        Route::post("/conversations/{conversation}/messages", [MessageController::class, "store"])
            ->name("conversations.messages.store");

        /*
        |---------------------------------------------------------------------
        | Mensajes fallidos
        |
        | Hasta ahora un envio fallido solo existia como una fila con status
        | 'failed' y una linea en el log: nadie se enteraba salvo que abriera
        | ese chat. Estas dos rutas lo hacen visible y recuperable.
        |
        | /messages/failed va ANTES de /messages/{message} o Laravel intentaria
        | resolver "failed" como un id.
        |---------------------------------------------------------------------
        */
        Route::get("/messages/failed", [MessageController::class, "failed"])
            ->name("messages.failed");

        Route::post("/messages/{message}/retry", [MessageController::class, "retry"])
            ->name("messages.retry");

        // Envio de una plantilla ya renderizada en el servidor. Es lo que
        // consume el selector de plantillas del chat.
        Route::post("/conversations/{conversation}/messages/template/{template}", [MessageController::class, "storeFromTemplate"])
            ->name("conversations.messages.template");

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
