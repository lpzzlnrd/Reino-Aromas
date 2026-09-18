<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Contact;
use App\Models\Message;
use App\Services\Meta\InstagramService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendInstagramMessageJob implements ShouldQueue
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

    public function handle(InstagramService $instagramService): void
    {
        $message = Message::query()
            ->with(['conversation.contact'])
            ->find($this->messageId);

        if ($message === null || $message->direction !== Message::DIRECTION_OUTBOUND) {
            return;
        }

        $contact = $message->conversation?->contact;
        if ($contact === null || $contact->channel !== Contact::CHANNEL_INSTAGRAM) {
            $this->markFailed($message, 'La conversación no pertenece a un contacto de Instagram.');

            return;
        }

        // El IGSID vive en channel_id; instagram_handle es solo para mostrar y
        // la API no lo acepta como destinatario.
        $recipient = $contact->channel_id;
        if ($recipient === null || $recipient === '') {
            $this->markFailed($message, 'El contacto no tiene IGSID de Instagram.');

            return;
        }

        $text = $message->body;
        if ($text === null || $text === '') {
            $this->markFailed($message, 'El cuerpo del mensaje está vacío.');

            return;
        }

        $result = $instagramService->sendMessage($recipient, $text);

        if (! ($result['success'] ?? false)) {
            $error = $result['error'] ?? null;

            // Un mensaje que no cabe hoy tampoco va a caber dentro de dos
            // minutos: reintentarlo ocupa el worker 2,7 minutos (backoff
            // 10+30+120) y escribe tres stacktraces de 35 líneas en el log
            // para llegar al mismo sitio. Con la cola llena de estos, el
            // servidor entero se arrastra.
            //
            // Se marca fallido y se vuelve SIN throw: es el mismo camino que
            // usan el IGSID ausente y el cuerpo vacío, que tampoco mejoran con
            // el tiempo.
            if ($this->esErrorDefinitivo($error)) {
                $this->markFailed($message, $this->textoDeError($error));

                Log::warning('[Instagram] Mensaje descartado sin reintentar', [
                    'message_id' => $this->messageId,
                    'motivo'     => $this->textoDeError($error),
                ]);

                return;
            }

            // El resto sí merece reintento: un 500 de Meta o un corte de red
            // se resuelven solos. Se lanza excepción en vez de marcar failed y
            // volver, porque sin throw la cola considera el Job exitoso y
            // $tries nunca reintenta.
            throw new \RuntimeException($this->textoDeError($error));
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

        Log::error('[Instagram] SendInstagramMessageJob agotó los reintentos', [
            'message_id' => $this->messageId,
            'error'      => $exception->getMessage(),
        ]);
    }

    /**
     * Si el error es de los que no cambian por reintentar.
     *
     * La lista es corta a propósito: ante la duda se reintenta. Dar por
     * definitivo un fallo pasajero pierde un mensaje del cliente, que es peor
     * que gastar tres intentos de más.
     *
     *  - 2534038: el texto supera los 1000 caracteres de Instagram.
     *  - 190:     token inválido o vencido. Reintentar no lo renueva; hay que
     *             reconectar la cuenta desde Ajustes.
     *  - 10 / 200: falta un permiso. Es configuración de la app en Meta.
     *
     * @param  array<string, mixed>|mixed $error
     */
    private function esErrorDefinitivo(mixed $error): bool
    {
        if (! is_array($error)) {
            return false;
        }

        if (($error['error_subcode'] ?? null) === InstagramService::SUBCODIGO_MENSAJE_LARGO) {
            return true;
        }

        return in_array($error['code'] ?? null, [10, 190, 200], strict: true);
    }

    /**
     * El texto del error, venga como string o como el array de Meta.
     *
     * @param  array<string, mixed>|mixed $error
     */
    private function textoDeError(mixed $error): string
    {
        if (is_string($error)) {
            return $error;
        }

        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        return json_encode($error) ?: 'Error al enviar el mensaje de Instagram.';
    }

    private function markFailed(Message $message, string $reason): void
    {
        $message->forceFill([
            'status'        => Message::STATUS_FAILED,
            'failed_reason' => $reason,
        ])->save();
    }
}
