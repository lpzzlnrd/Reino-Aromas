<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Contact;
use App\Models\Message;
use App\Services\Meta\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 120];

    /**
     * Recibe solo el id del mensaje: el destinatario y el texto se leen del
     * registro ya persistido. Así el Job no puede enviar algo distinto de lo
     * que el CRM muestra en el chat.
     */
    public function __construct(
        private int $messageId,
    ) {}

    public function handle(WhatsAppService $whatsAppService): void
    {
        $message = Message::query()
            ->with(['conversation.contact'])
            ->find($this->messageId);

        if ($message === null || $message->direction !== Message::DIRECTION_OUTBOUND) {
            return;
        }

        $contact = $message->conversation?->contact;
        if ($contact === null || $contact->channel !== Contact::CHANNEL_WHATSAPP) {
            $this->markFailed($message, 'La conversación no pertenece a un contacto de WhatsApp.');

            return;
        }

        // El teléfono de destino: `phone` es el formato E.164 legible y
        // `channel_id` el wa_id que devuelve Meta. Se prefiere channel_id
        // porque es el que la API acepta sin normalizar.
        $recipient = $contact->channel_id ?: $contact->phone;
        if ($recipient === null || $recipient === '') {
            $this->markFailed($message, 'El contacto no tiene número de WhatsApp.');

            return;
        }

        $text = $message->body;
        if ($text === null || $text === '') {
            $this->markFailed($message, 'El cuerpo del mensaje está vacío.');

            return;
        }

        $result = $whatsAppService->sendMessage($recipient, $text);

        // Se lanza excepción en vez de marcar failed y volver: sin throw la
        // cola considera el Job exitoso y $tries nunca reintenta.
        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException(
                is_string($result['error'] ?? null)
                    ? $result['error']
                    : json_encode($result['error'] ?? 'Error al enviar el mensaje de WhatsApp.'),
            );
        }

        $message->forceFill([
            'external_id'   => (string) ($result['message_id'] ?? $message->external_id),
            'meta_payload'  => $result['payload'] ?? $message->meta_payload,
            'status'        => Message::STATUS_SENT,
            'sent_at'       => $message->sent_at ?? now(),
            'failed_reason' => null,
        ])->save();
    }

    public function failed(\Throwable $exception): void
    {
        $message = Message::query()->find($this->messageId);
        if ($message !== null) {
            $this->markFailed($message, $exception->getMessage());
        }

        Log::error('[WhatsApp] SendWhatsAppMessageJob agotó los reintentos', [
            'message_id' => $this->messageId,
            'error'      => $exception->getMessage(),
        ]);
    }

    private function markFailed(Message $message, string $reason): void
    {
        $message->forceFill([
            'status'        => Message::STATUS_FAILED,
            'failed_reason' => $reason,
        ])->save();
    }
}
