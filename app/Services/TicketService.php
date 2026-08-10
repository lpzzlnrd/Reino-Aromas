<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;

class TicketService
{
    public function __construct(private readonly ActivityLogService $activityLogService) {}

    /**
     * Crea un ticket para la conversación si todavía no tiene uno.
     */
    public function ensureTicketExists(Conversation $conversation): Ticket
    {
        if ($conversation->ticket) {
            return $conversation->ticket;
        }

        $ticket = $conversation->ticket()->create([
            'status'   => 'nuevo',
            'priority' => 'media',
            'city'     => $conversation->contact->city,
        ]);

        $this->activityLogService->log(
            causerType: null,
            causerId:   null,
            targetType: Ticket::class,
            targetId:   $ticket->id,
            action:     'ticket.created',
            metadata:   ['channel' => $conversation->contact->channel],
        );

        return $ticket;
    }

    /**
     * Califica un ticket desde una automatización, sin usuario humano.
     *
     * Existe aparte de changeStatus/changePriority porque esos exigen un User
     * y aquí no lo hay: el cambio lo origina un Flow de WhatsApp, no un agente.
     * Forzar un usuario real ensuciaría la auditoría atribuyéndole a alguien
     * algo que no hizo.
     *
     * Actualiza estado y prioridad en una sola operación porque en el Flow
     * ambos cambian juntos: "estoy interesado" implica status=interesado Y
     * priority=muy_alta. Dos llamadas separadas dejarían el ticket en un
     * estado intermedio inconsistente si la segunda falla.
     *
     * @param string      $origen   Qué automatización lo originó (ej. 'whatsapp_flow').
     * @param array<string, mixed> $extra Campos adicionales del ticket (course_interest, city…).
     */
    public function qualifyAutomatically(
        Ticket $ticket,
        string $status,
        string $priority,
        string $origen,
        array $extra = [],
    ): Ticket {
        $anterior = ['status' => $ticket->status, 'priority' => $ticket->priority];

        $ticket->update([
            'status'      => $status,
            'priority'    => $priority,
            'reserved_at' => $status === 'reservado' ? now() : $ticket->reserved_at,
            'closed_at'   => $status === 'cerrado' ? now() : $ticket->closed_at,
            ...$extra,
        ]);

        // causer nulo a propósito: no hay humano detrás. El campo 'origen' del
        // metadata es lo que permite filtrar después qué tickets llegaron
        // precalificados por un Flow.
        $this->activityLogService->log(
            causerType: null,
            causerId:   null,
            targetType: Ticket::class,
            targetId:   $ticket->id,
            action:     'ticket.qualified_automatically',
            metadata:   [
                'origen'   => $origen,
                'from'     => $anterior,
                'to'       => ['status' => $status, 'priority' => $priority],
            ],
        );

        return $ticket->fresh();
    }

    /**
     * Cambia el estado de un ticket y registra el cambio en activity_logs.
     */
    public function changeStatus(Ticket $ticket, string $status, User $byUser): void
    {
        $previous = $ticket->status;

        $ticket->update([
            'status'      => $status,
            'closed_at'   => $status === 'cerrado' ? now() : $ticket->closed_at,
            'reserved_at' => $status === 'reservado' ? now() : $ticket->reserved_at,
        ]);

        $this->activityLogService->log(
            causerType: User::class,
            causerId:   $byUser->id,
            targetType: Ticket::class,
            targetId:   $ticket->id,
            action:     'ticket.status_changed',
            metadata:   ['from' => $previous, 'to' => $status],
        );
    }

    /**
     * Cambia la prioridad de un ticket y registra el cambio.
     */
    public function changePriority(Ticket $ticket, string $priority, User $byUser): void
    {
        $previous = $ticket->priority;

        $ticket->update(['priority' => $priority]);

        $this->activityLogService->log(
            causerType: User::class,
            causerId:   $byUser->id,
            targetType: Ticket::class,
            targetId:   $ticket->id,
            action:     'ticket.priority_changed',
            metadata:   ['from' => $previous, 'to' => $priority],
        );
    }
}
