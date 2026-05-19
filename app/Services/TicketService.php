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
