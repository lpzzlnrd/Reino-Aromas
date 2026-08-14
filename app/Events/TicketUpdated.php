<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un ticket cambió de estado, prioridad o asignación.
 *
 * Es lo que hará que el Kanban se mueva solo: si un agente arrastra una tarjeta
 * de "Interesado" a "Reservado", los demás la ven cambiar sin recargar. También
 * lo usan el badge de la bandeja y los contadores de los filtros.
 *
 * Se dispara desde el modelo (evento `updated`) y solo si cambió algo que
 * importa — guardar una nota no debería mover ninguna columna.
 */
class TicketUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param array<string, mixed> $cambios Campos que cambiaron, con su valor
     *                                      anterior. Sirve para que el front
     *                                      sepa de qué columna sacar la tarjeta.
     */
    public function __construct(
        public readonly Ticket $ticket,
        public readonly array $cambios = [],
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            // El tablero: todos los agentes ven el mismo Kanban.
            new PrivateChannel('tickets'),
            // Y el chat, que muestra el ticket en su panel lateral.
            new PrivateChannel("conversations.{$this->ticket->conversation_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ticket.updated';
    }

    /**
     * Misma forma que ConversationController::serializarTicket, para que el
     * panel del chat pueda reemplazar su ticket sin transformar nada.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->ticket->loadMissing(['assignedUser:id,name,avatar_url', 'tags:id,name,color']);

        return [
            'conversation_id' => $this->ticket->conversation_id,
            // De dónde venía: el Kanban necesita saber de qué columna quitarla.
            'previous'        => $this->cambios,
            'ticket' => [
                'id'              => $this->ticket->id,
                'status'          => $this->ticket->status,
                'status_label'    => $this->ticket->statusLabel(),
                'priority'        => $this->ticket->priority,
                'city'            => $this->ticket->city,
                'course_interest' => $this->ticket->course_interest,
                'notes'           => $this->ticket->notes,
                'assigned_user'   => $this->ticket->assignedUser ? [
                    'id'     => $this->ticket->assignedUser->id,
                    'name'   => $this->ticket->assignedUser->name,
                    'avatar' => $this->ticket->assignedUser->avatar_url,
                ] : null,
                'tags' => $this->ticket->tags->map(fn ($tag): array => [
                    'id'    => $tag->id,
                    'name'  => $tag->name,
                    'color' => $tag->color,
                ])->values(),
            ],
        ];
    }
}
