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

        // Instagram manda por el mismo campo 'messaging' los acuses de lectura
        // y de entrega, que traen 'read' o 'delivery' en vez de 'message'. No
        // son errores: se ignoran en silencio. Antes caian en el warning de
        // abajo y llenaban el log de ruido que parecia un fallo.
        if (isset($messaging['read']) || isset($messaging['delivery'])) {
            return;
        }

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

        // El webhook de Instagram NO trae el nombre: 'sender' solo lleva el id
        // (a diferencia de Messenger, donde sender.name viene en el payload).
        // El username se pide aparte a la User Profile API.
        $perfil = $this->fetchUserProfile($senderId);

        $contact = $this->contactService->findOrCreate('instagram', $senderId, [
            'display_name'        => $perfil['username'] ?? $perfil['name'] ?? null,
            'instagram_handle'    => $perfil['username'] ?? null,
            'profile_picture_url' => $perfil['profile_pic'] ?? null,
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
     * Pide el perfil del usuario a la User Profile API.
     *
     * Hace falta porque el webhook de Instagram solo manda `sender.id`; el
     * nombre y el username hay que pedirlos con una llamada aparte al nodo del
     * IGSID.
     *
     * Se prefiere `username` sobre `name` para el nombre visible: en Instagram
     * la gente se reconoce por el @, y `name` puede venir null si el usuario no
     * lo configuro.
     *
     * Nunca lanza: un contacto sin nombre es peor que uno llamado "Instagram
     * User", pero perder el MENSAJE por no poder resolver el perfil es mucho
     * peor. Si falla, se registra y el mensaje se guarda igual.
     *
     * @return array{name?: string, username?: string, profile_pic?: string}
     */
    private function fetchUserProfile(string $igsid): array
    {
        $accessToken = $this->credentials->obtener('instagram_access_token')
            ?: $this->credentials->obtener('access_token');

        if (! $accessToken) {
            return [];
        }

        try {
            $response = Http::timeout(10)
                ->withToken($accessToken)
                ->get($this->credentials->urlGraphInstagram($igsid), [
                    'fields' => 'name,username,profile_pic',
                ]);
        } catch (\Throwable $e) {
            Log::warning('[Instagram] No se pudo pedir el perfil del usuario', [
                'igsid' => $igsid,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if ($response->failed()) {
            // El caso normal: "User consent is required to access user profile"
            // cuando el usuario comento pero nunca escribio. No es un fallo
            // nuestro, asi que se registra como info y no como error.
            Log::info('[Instagram] Perfil no disponible', [
                'igsid' => $igsid,
                'error' => $response->json('error.message'),
            ]);

            return [];
        }

        return array_filter([
            'name'        => $response->json('name'),
            'username'    => $response->json('username'),
            'profile_pic' => $response->json('profile_pic'),
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

        // El producto "Instagram API con login de Instagram" tiene su propio
        // token; si no está configurado se cae al de la app principal, que es
        // el correcto cuando Instagram entra por el topic de esa app.
        $accessToken = $this->credentials->obtener('instagram_access_token')
            ?: $this->credentials->obtener('access_token');

        // graph.instagram.com, NO graph.facebook.com: el host de Facebook
        // responde "(#3) Application does not have the capability to make this
        // API call" para estos endpoints. Ver urlGraphInstagram().
        $response = Http::withToken($accessToken)
            ->post($this->credentials->urlGraphInstagram("{$igAccountId}/messages"), [
                'recipient' => ['id' => $recipientIgsid],
                'message'   => ['text' => $text],
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
