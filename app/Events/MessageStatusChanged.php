<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un mensaje saliente cambió de estado: pending → sent → delivered → read,
 * o pending → failed.
 *
 * Es el evento que le faltaba a la bandeja. El envío responde 202 y el mensaje
 * nace en `pending`; hasta ahora el paso a `sent` solo se veía recargando, así
 * que el agente no sabía si su mensaje había salido. Con esto la burbuja se
 * actualiza sola cuando el Job termina.
 *
 * Va solo al canal de la conversación: la bandeja muestra el texto del último
 * mensaje, no su estado de entrega, así que no necesita enterarse.
 */
class MessageStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversations.{$this->message->conversation_id}")];
    }

    public function broadcastAs(): string
    {
        return 'message.status';
    }

    /**
     * Solo lo que cambia.
     *
     * No se reenvía el mensaje completo: el front ya tiene la burbuja pintada y
     * únicamente necesita mover el estado. `failed_reason` viaja porque es lo
     * que explica al agente POR QUÉ no salió.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->message->conversation_id,
            'id'              => $this->message->id,
            'status'          => $this->message->status,
            'failed_reason'   => $this->message->failed_reason,
            'sent_at'         => $this->message->sent_at?->toIso8601String(),
            'delivered_at'    => $this->message->delivered_at?->toIso8601String(),
            'read_at'         => $this->message->read_at?->toIso8601String(),
        ];
    }
}
