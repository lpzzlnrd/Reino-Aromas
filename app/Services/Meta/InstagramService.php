<?php

namespace App\Services\Meta;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\ContactService;
use App\Services\ConversationService;
use App\Services\TicketService;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramService
{
    public function __construct(
        private ContactService $contactService,
        private ConversationService $conversationService,
        private TicketService $ticketService,
        private ActivityLogService $activityLogService,
        private MetaCredentials $credentials = new MetaCredentials(),
    ) {}

    /**
     * Procesa el payload completo del webhook de Instagram.
     * Llamado desde ProcessInstagramMessageJob.
     */
    public function processWebhookPayload(array $payload): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $messaging) {
                $this->handleMessagingEvent($messaging);
            }
        }
    }

    /**
     * Maneja un evento de mensajería individual del webhook.
     */
    private function handleMessagingEvent(array $messaging): void
    {
        $senderId = $messaging['sender']['id'] ?? null;
        $messageData = $messaging['message'] ?? null;

        if (!$senderId || !$messageData) {
            Log::warning('[Instagram] Evento de mensajería sin sender o message', $messaging);
            return;
        }

        // Ignorar mensajes de eco (mensajes que nosotros enviamos)
        if ($messageData['is_echo'] ?? false) {
            return;
        }

        $externalId = $messageData['mid'] ?? null;

        // Idempotencia: ignorar si ya procesamos este mensaje
        if ($externalId && Message::where('external_id', $externalId)->exists()) {
            Log::info("[Instagram] Mensaje duplicado ignorado: {$externalId}");
            return;
        }

        $contact = $this->contactService->findOrCreate('instagram', $senderId, [
            'display_name' => $messaging['sender']['name'] ?? null,
        ]);

        $conversation = $this->conversationService->getOrOpenActive($contact);

        $this->storeInboundMessage($conversation, $messageData, $externalId);

        $this->ticketService->ensureTicketExists($conversation);

        $this->conversationService->updateLastMessageAt($conversation);
        $this->conversationService->refreshWindowStatus($conversation);
    }

    /**
     * Persiste el mensaje entrante en la BD.
     */
    private function storeInboundMessage(Conversation $conversation, array $messageData, ?string $externalId): Message
    {
        $type = $this->resolveMessageType($messageData);
        $body = $messageData['text'] ?? null;
        $mediaUrl = $this->extractMediaUrl($messageData);

        return Message::create([
            'conversation_id' => $conversation->id,
            'sender_user_id'  => null,
            'direction'       => 'inbound',
            'channel'         => 'instagram',
            'external_id'     => $externalId,
            'type'            => $type,
            'body'            => $body,
            'media_url'       => $mediaUrl,
            'meta_payload'    => $messageData,
            'status'          => 'delivered',
        ]);
    }

    /**
     * Envía un mensaje de texto saliente vía Instagram Messaging API.
     * Llamado desde SendInstagramMessageJob.
     */
    public function sendMessage(string $recipientIgsid, string $text): array
    {
        // Falla con el nombre de la variable ausente en vez de pedirle a Meta
        // una URL con el id vacío.
        $igAccountId = $this->credentials->obtener('instagram_account_id');
        $accessToken = $this->credentials->obtener('access_token');

        $response = Http::withToken($accessToken)
            ->post($this->credentials->urlGraph("{$igAccountId}/messages"), [
                'recipient'      => ['id' => $recipientIgsid],
                'message'        => ['text' => $text],
                'messaging_type' => 'RESPONSE',
            ]);

        if ($response->failed()) {
            $error = $response->json('error', []);
            Log::error('[Instagram] Error al enviar mensaje', [
                'recipient' => $recipientIgsid,
                'error'     => $error,
            ]);

            return ['success' => false, 'error' => $error];
        }

        return [
            'success'    => true,
            'message_id' => $response->json('message_id'),
        ];
    }

    /**
     * Verifica la firma HMAC del webhook entrante.
     *
     * Sin app_secret devuelve false sin calcular nada: un HMAC con clave vacía
     * lo puede reproducir cualquiera. Ver la nota en WhatsAppService.
     */
    public function verifySignature(string $rawBody, string $signature): bool
    {
        // Instagram puede entrar por dos caminos, y CADA UNO firma con un
        // secret distinto:
        //
        //   a) el producto "Instagram API con login de Instagram", que es una
        //      app aparte con su propio app secret -> META_INSTAGRAM_APP_SECRET
        //   b) el topic 'instagram' de la app principal -> META_APP_SECRET
        //
        // Usar el secret equivocado devuelve 403 a Meta y el mensaje se pierde
        // sin dejar rastro en el log de Laravel: el rechazo ocurre antes.
        // Se prueban los dos en vez de elegir por configuracion, porque asi la
        // integracion sigue funcionando si el negocio cambia de camino sin que
        // nadie se acuerde de tocar el .env.
        $secretos = array_values(array_filter([
            $this->credentials->obtener('instagram_app_secret'),
            $this->credentials->obtener('app_secret'),
        ]));

        if ($secretos === []) {
            Log::error('[Instagram] Sin META_INSTAGRAM_APP_SECRET ni META_APP_SECRET: se rechaza el webhook por no poder verificar su firma.');

            return false;
        }

        foreach ($secretos as $secreto) {
            $expected = 'sha256=' . hash_hmac('sha256', $rawBody, (string) $secreto);

            // hash_equals y no ===: comparar firmas con == filtra informacion
            // por el tiempo que tarda en fallar.
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        Log::warning('[Instagram] Firma del webhook invalida con todos los secrets configurados.', [
            'secrets_probados' => count($secretos),
        ]);

        return false;
    }

    private function resolveMessageType(array $messageData): string
    {
        if (isset($messageData['attachments'])) {
            $attachmentType = $messageData['attachments'][0]['type'] ?? 'document';
            return match ($attachmentType) {
                'image'  => 'image',
                'audio'  => 'audio',
                'video'  => 'video',
                default  => 'document',
            };
        }

        return 'text';
    }

    private function extractMediaUrl(array $messageData): ?string
    {
        return $messageData['attachments'][0]['payload']['url'] ?? null;
    }
}
