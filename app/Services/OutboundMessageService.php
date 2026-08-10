<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendFacebookMessageJob;
use App\Jobs\SendInstagramMessageJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Punto único de salida de mensajes del CRM hacia los canales de Meta.
 *
 * Existe para resolver el bug que tenía routes/api.php: el envío apuntaba
 * SIEMPRE a FacebookMessageController sin mirar el canal, así que responder un
 * chat de WhatsApp intentaba salir por Facebook.
 *
 * El contrato es: persistir el mensaje como `pending` y despachar el Job del
 * canal. El Job es el único que habla con la API de Meta y el único que mueve
 * el estado a `sent` o `failed`. Persistir primero garantiza que el mensaje
 * aparezca en el chat aunque la cola esté caída — se queda en `pending`, que
 * es visible, en vez de perderse.
 */
class OutboundMessageService
{
    public function __construct(
        private readonly ConversationService $conversationService,
    ) {}

    /**
     * Encola un mensaje de texto saliente para el canal del contacto.
     *
     * @throws \RuntimeException si la conversación no tiene contacto o el canal
     *                           no está soportado.
     */
    public function queueTextMessage(
        Conversation $conversation,
        string $body,
        ?User $sender = null,
    ): Message {
        $contact = $conversation->contact;

        if ($contact === null) {
            throw new \RuntimeException('La conversación no tiene un contacto asociado.');
        }

        // Se resuelve el Job ANTES de escribir en la base: si el canal no está
        // soportado no queremos dejar un mensaje huérfano en `pending` que
        // nadie va a enviar nunca.
        $jobClass = $this->jobForChannel($contact->channel);

        // La transacción cubre el mensaje y el last_message_at de la
        // conversación: si el segundo falla, la bandeja no debe quedar
        // ordenada por un mensaje que no se guardó.
        $message = DB::transaction(function () use ($conversation, $contact, $body, $sender): Message {
            $message = $conversation->messages()->create([
                'sender_user_id' => $sender?->id,
                'direction'      => Message::DIRECTION_OUTBOUND,
                'channel'        => $contact->channel,
                'type'           => Message::TYPE_TEXT,
                'body'           => $body,
                'status'         => Message::STATUS_PENDING,
            ]);

            $this->conversationService->updateLastMessageAt($conversation);

            return $message;
        });

        // Fuera de la transacción a propósito: si se despachara dentro y la
        // cola usara la misma conexión de base, el worker podría tomar el Job
        // antes del commit y no encontrar el mensaje.
        $jobClass::dispatch($message->id);

        return $message;
    }

    /**
     * @return class-string
     */
    private function jobForChannel(string $channel): string
    {
        return match ($channel) {
            Contact::CHANNEL_WHATSAPP  => SendWhatsAppMessageJob::class,
            Contact::CHANNEL_INSTAGRAM => SendInstagramMessageJob::class,
            Contact::CHANNEL_FACEBOOK  => SendFacebookMessageJob::class,
            default => throw new \RuntimeException("Canal no soportado para envío: {$channel}"),
        };
    }
}
