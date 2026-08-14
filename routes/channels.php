<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|------------------------------------------------------------------------------
| Canales de broadcasting
|
| Todos son privados: la autorización pasa por POST /broadcasting/auth, que va
| detrás del middleware web (sesión Laravel). Un usuario sin sesión no puede
| suscribirse a ninguno.
|
| Devolver `true` autoriza. Devolver false o null rechaza la suscripción.
|------------------------------------------------------------------------------
*/

/**
 * Notificaciones dirigidas a un usuario concreto.
 * Lo deja Laravel por defecto y lo usan las Notifications.
 */
Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return (int) $user->id === (int) $id;
});

/**
 * Una conversación concreta: mensajes nuevos y cambios de estado de entrega.
 *
 * Hoy cualquier agente activo puede abrir cualquier chat — la bandeja es
 * compartida y no hay asignación exclusiva de conversaciones. Así que la
 * comprobación es que el usuario esté activo y que la conversación exista, no
 * que sea "suya".
 *
 * Se valida la existencia a propósito: sin eso un cliente podría suscribirse a
 * `conversations.99999` y quedarse escuchando un canal que mañana existirá.
 */
Broadcast::channel('conversations.{conversationId}', function (User $user, string $conversationId): bool {
    if (! $user->is_active) {
        return false;
    }

    return Conversation::whereKey($conversationId)->exists();
});

/**
 * La bandeja compartida: avisa de mensajes nuevos para reordenar la lista.
 *
 * Es un canal común porque los agentes ven la MISMA bandeja: un chat nuevo
 * tiene que aparecerle a todos, no solo a quien lo tenga asignado.
 */
Broadcast::channel('inbox', function (User $user): bool {
    return (bool) $user->is_active;
});

/**
 * El tablero de tickets (Kanban).
 *
 * También compartido: si un agente mueve una tarjeta, los demás deben verla
 * moverse. Sin esto, dos agentes podrían trabajar el mismo lead creyendo que
 * nadie más lo tocó.
 */
Broadcast::channel('tickets', function (User $user): bool {
    return (bool) $user->is_active;
});
