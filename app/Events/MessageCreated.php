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
 * Un mensaje entró o salió de una conversación.
 *
 * Lo escuchan dos vistas a la vez y por eso va a dos canales:
 *   - el chat abierto, para añadir la burbuja sin recargar
 *   - la bandeja, para reordenar la lista y actualizar el preview
 *
 * Se dispara desde el modelo (evento `created` de Eloquent) y no desde los
 * servicios: los mensajes nacen en tres sitios distintos —
 * OutboundMessageService, WhatsAppService e InstagramService— y engancharlo en
 * el modelo cubre los tres, más cualquiera que se añada después.
 */
class MessageCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("conversations.{$this->message->conversation_id}"),
            // Canal común de la bandeja: todos los agentes ven la misma lista,
            // así que un chat nuevo debe aparecerles a todos.
            new PrivateChannel('inbox'),
        ];
    }

    /**
     * Nombre corto en lugar del FQCN.
     *
     * El Vue escucha 'message.created', no
     * 'App\Events\MessageCreated' — así el front no queda atado al namespace
     * de PHP y renombrar la clase no rompe el cliente.
     */
    public function broadcastAs(): string
    {
        return 'message.created';
    }

    /**
     * Lo que viaja por el socket.
     *
     * Es la MISMA forma que devuelve ConversationController::serializarMensaje,
     * a propósito: el Vue ya tiene el tipo ChatMessage y así puede empujar esto
     * al historial sin transformar nada.
     *
     * No se manda el modelo entero: meta_payload trae el JSON crudo de Meta, que
     * puede pesar y no le sirve a nadie en el front.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->message->loadMissing('sender:id,name,avatar_url');

        return [
            'conversation_id' => $this->message->conversation_id,
            'message' => [
                'id'            => $this->message->id,
                'direction'     => $this->message->direction,
                'channel'       => $this->message->channel,
                'type'          => $this->message->type,
                'body'          => $this->message->body,
                'media_url'     => $this->message->media_url,
                'status'        => $this->message->status,
                'failed_reason' => $this->message->failed_reason,
                'sender'        => $this->message->sender ? [
                    'id'     => $this->message->sender->id,
                    'name'   => $this->message->sender->name,
                    'avatar' => $this->message->sender->avatar_url,
                ] : null,
                'sent_at'      => $this->message->sent_at?->toIso8601String(),
                'delivered_at' => $this->message->delivered_at?->toIso8601String(),
                'read_at'      => $this->message->read_at?->toIso8601String(),
                'created_at'   => $this->message->created_at->toIso8601String(),
            ],
        ];
    }
}
