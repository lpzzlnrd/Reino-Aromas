<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Meta\FacebookAccountService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessMetaWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Nombre de relleno cuando Meta no da el perfil.
     *
     * El webhook de Messenger solo trae el PSID: el nombre hay que pedirlo a la
     * User Profile API, y eso exige Advanced Access de "Business Asset User
     * Profile Access" (App Review). Sin eso Meta devuelve {} y no hay nombre
     * que guardar. Tampoco lo hay para cuentas de Messenger creadas con número
     * de teléfono (error 2018218) ni para quien llegó por Click-to-Messenger y
     * todavía no respondió.
     */
    public const NOMBRE_DESCONOCIDO = 'Facebook User';

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 120];

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private array $payload,
    ) {}

    public function handle(FacebookAccountService $accounts): void
    {
        $entries = $this->payload['entry'] ?? [];
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $messagingItems = $entry['messaging'] ?? [];
            if (! is_array($messagingItems)) {
                continue;
            }

            foreach ($messagingItems as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $this->persistMessagingEvent($event, $accounts);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('stack')->error('ProcessMetaWebhookJob failed', [
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * Pide a Meta el nombre y la foto de quien escribió.
     *
     * Devuelve null (y el contacto queda con el nombre de relleno) si no hay
     * page token configurado o si Meta no da el perfil. Nunca revienta: un
     * mensaje sin nombre se guarda igual — perder el mensaje por no saber cómo
     * se llama el cliente sería mucho peor que mostrarlo sin nombre.
     *
     * @return array{name: string, profile_pic: ?string}|null
     */
    private function resolverPerfil(string $psid, FacebookAccountService $accounts): ?array
    {
        $pageToken = (string) config('services.meta.facebook.page_access_token');

        if ($pageToken === '') {
            return null;
        }

        $perfil = $accounts->fetchUserProfile($psid, $pageToken);

        // fetchUserProfile ya devuelve el nombre de relleno cuando Meta responde
        // con un objeto vacío. Se descarta aquí para que el llamador distinga
        // "Meta no sabe" de "Meta dio un nombre".
        if ($perfil === null || ($perfil['name'] ?? '') === self::NOMBRE_DESCONOCIDO) {
            return null;
        }

        return $perfil;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function persistMessagingEvent(array $event, FacebookAccountService $accounts): void
    {
        $senderId = (string) ($event['sender']['id'] ?? '');
        $recipientId = (string) ($event['recipient']['id'] ?? '');

        if ($senderId === '' || $recipientId === '') {
            return;
        }

        $pageId = (string) config('services.meta.facebook.page_id');
        $contactPsid = $senderId === $pageId ? $recipientId : $senderId;
        if ($contactPsid === '') {
            return;
        }

        $messagePayload = $event['message'] ?? null;
        if (! is_array($messagePayload)) {
            return;
        }

        $externalId = (string) ($messagePayload['mid'] ?? '');
        if ($externalId !== '' && Message::query()->where('external_id', $externalId)->exists()) {
            return;
        }

        $eventTime = $this->resolveEventTime($event['timestamp'] ?? null);

        // El perfil se pide FUERA de la transacción: es una llamada HTTP a Meta
        // y dejarla dentro mantendría abierta la transacción durante toda la
        // latencia de red.
        $perfil = $this->resolverPerfil($contactPsid, $accounts);

        DB::transaction(function () use ($contactPsid, $messagePayload, $externalId, $eventTime, $perfil): void {
            $contact = Contact::query()->firstOrCreate(
                ['channel' => Contact::CHANNEL_FACEBOOK, 'channel_id' => $contactPsid],
                [
                    'display_name' => $perfil['name'] ?? self::NOMBRE_DESCONOCIDO,
                    'profile_picture_url' => $perfil['profile_pic'] ?? null,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ],
            );

            $contact->forceFill(['last_seen_at' => now()])->save();

            // Un contacto que ya existía con el nombre de relleno se corrige en
            // cuanto la API responde: los que se guardaron antes de tener
            // Advanced Access quedarían con "Facebook User" para siempre.
            //
            // Va en try/catch porque el perfil es un ADORNO y el mensaje es el
            // dato. Cuando profile_picture_url era VARCHAR(255), una URL de la
            // CDN de Meta (~470 caracteres) hacía rollback de toda la
            // transacción y el Message::create() de más abajo no se ejecutaba:
            // se perdía el mensaje entero por una foto. La columna ya es TEXT,
            // pero el mensaje no debe volver a depender de que el perfil quepa.
            if (
                ! $contact->wasRecentlyCreated
                && isset($perfil['name'])
                && $contact->display_name === self::NOMBRE_DESCONOCIDO
            ) {
                try {
                    $contact->forceFill([
                        'display_name' => $perfil['name'],
                        'profile_picture_url' => $perfil['profile_pic'] ?? $contact->profile_picture_url,
                    ])->save();
                } catch (\Throwable $e) {
                    Log::warning('No se pudo actualizar el perfil del contacto', [
                        'contact_id' => $contact->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            $conversation = $contact->activeConversation()->first();
            if ($conversation === null) {
                $conversation = $contact->conversations()->create([
                    'status' => Conversation::STATUS_OPEN,
                    'within_24h_window' => true,
                ]);
            }

            $body = isset($messagePayload['text']) && is_string($messagePayload['text']) ? $messagePayload['text'] : null;
            [$type, $mediaUrl] = $this->resolveMessageTypeAndMedia($messagePayload);

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_user_id' => null,
                'direction' => Message::DIRECTION_INBOUND,
                'channel' => Contact::CHANNEL_FACEBOOK,
                'external_id' => $externalId !== '' ? $externalId : null,
                'type' => $type,
                'body' => $body,
                'media_url' => $mediaUrl,
                'meta_payload' => $messagePayload,
                'status' => Message::STATUS_DELIVERED,
                'sent_at' => null,
                'delivered_at' => $eventTime,
                'read_at' => null,
                'created_at' => $eventTime,
            ]);

            $conversation->forceFill([
                'last_message_at' => $eventTime,
                'within_24h_window' => true,
            ])->save();
        });
    }

    /**
     * @param mixed $timestamp
     */
    private function resolveEventTime(mixed $timestamp): Carbon
    {
        if (! is_numeric($timestamp)) {
            return now();
        }

        return Carbon::createFromTimestampMs((int) $timestamp);
    }

    /**
     * @param array<string, mixed> $messagePayload
     * @return array{0: string, 1: ?string}
     */
    private function resolveMessageTypeAndMedia(array $messagePayload): array
    {
        $attachments = $messagePayload['attachments'] ?? [];
        if (! is_array($attachments) || $attachments === []) {
            return [Message::TYPE_TEXT, null];
        }

        $first = $attachments[0] ?? null;
        if (! is_array($first)) {
            return [Message::TYPE_DOCUMENT, null];
        }

        $type = (string) ($first['type'] ?? 'file');
        $url = $first['payload']['url'] ?? null;
        $mediaUrl = is_string($url) ? $url : null;

        return match ($type) {
            'image' => [Message::TYPE_IMAGE, $mediaUrl],
            'video' => [Message::TYPE_VIDEO, $mediaUrl],
            'audio' => [Message::TYPE_AUDIO, $mediaUrl],
            default => [Message::TYPE_DOCUMENT, $mediaUrl],
        };
    }
}
