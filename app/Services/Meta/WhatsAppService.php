<?php

namespace App\Services\Meta;

use App\Jobs\SendWhatsAppFlowJob;
use App\Models\Message;
use App\Models\Conversation;
use App\Services\ContactService;
use App\Services\ConversationService;
use App\Services\TicketService;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public function __construct(
        private ContactService $contactService,
        private ConversationService $conversationService,
        private TicketService $ticketService,
        private ActivityLogService $activityLogService,
    ) {}

    /**
     * Procesa el payload completo del webhook de WhatsApp.
     * Llamado desde ProcessWhatsAppMessageJob.
     */
    public function processWebhookPayload(array $payload): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];

                foreach ($value['messages'] ?? [] as $message) {
                    $this->handleMessageEvent($message, $value['contacts'] ?? []);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->handleStatusUpdate($status);
                }
            }
        }
    }

    /**
     * Maneja un mensaje entrante individual del webhook.
     */
    private function handleMessageEvent(array $message, array $contacts): void
    {
        $senderId  = $message['from'] ?? null;
        $messageId = $message['id'] ?? null;

        if (!$senderId || !$messageId) {
            Log::warning('[WhatsApp] Mensaje sin from o id', $message);
            return;
        }

        // Idempotencia: ignorar si ya procesamos este mensaje
        if (Message::where('external_id', $messageId)->exists()) {
            Log::info("[WhatsApp] Mensaje duplicado ignorado: {$messageId}");
            return;
        }

        // Buscar nombre del contacto en el array de contacts del payload
        $displayName = $this->resolveDisplayName($senderId, $contacts);

        $contact = $this->contactService->findOrCreate('whatsapp', $senderId, [
            'display_name' => $displayName,
            'phone'        => $senderId,
        ]);

        // Se captura ANTES de tocar la conversación: findOrCreate ya guardó el
        // contacto, así que wasRecentlyCreated es la única señal de que este
        // lead es nuevo y todavía no ha hablado con nadie.
        $esContactoNuevo = $contact->wasRecentlyCreated;

        $conversation = $this->conversationService->getOrOpenActive($contact);

        $this->storeInboundMessage($conversation, $message, $messageId);

        // El ticket se crea SIEMPRE, antes de intentar el Flow. Es la red de
        // seguridad: si el Flow falla o el lead lo cierra sin completarlo, el
        // ticket sigue en la bandeja para que un agente lo atienda.
        $this->ticketService->ensureTicketExists($conversation);

        $this->conversationService->updateLastMessageAt($conversation);
        $this->conversationService->refreshWindowStatus($conversation);

        // Flow de bienvenida: reemplaza el saludo y la pregunta de ciudad que
        // hoy el agente manda a mano. Solo para leads nuevos — a un contacto
        // conocido que vuelve a escribir se le atiende directo.
        if ($esContactoNuevo) {
            SendWhatsAppFlowJob::dispatch($conversation->id, $senderId);
        }
    }

    /**
     * Actualiza el estado de un mensaje saliente (delivered, read, failed).
     */
    private function handleStatusUpdate(array $status): void
    {
        $externalId = $status['id'] ?? null;
        $newStatus  = $status['status'] ?? null;

        if (!$externalId || !$newStatus) {
            return;
        }

        $message = Message::where('external_id', $externalId)->first();

        if (!$message) {
            return;
        }

        $updates = match ($newStatus) {
            'delivered' => ['status' => 'delivered', 'delivered_at' => now()],
            'read'      => ['status' => 'read', 'read_at' => now()],
            'failed'    => [
                'status'        => 'failed',
                'failed_reason' => $status['errors'][0]['title'] ?? 'Unknown error',
            ],
            default => [],
        };

        if (!empty($updates)) {
            $message->update($updates);
        }
    }

    /**
     * Persiste el mensaje entrante en la BD.
     */
    private function storeInboundMessage(Conversation $conversation, array $messageData, string $externalId): Message
    {
        $type     = $this->resolveMessageType($messageData);
        $body     = $this->extractBody($messageData, $type);
        $mediaUrl = $this->extractMediaUrl($messageData, $type);

        return Message::create([
            'conversation_id' => $conversation->id,
            'sender_user_id'  => null,
            'direction'       => 'inbound',
            'channel'         => 'whatsapp',
            'external_id'     => $externalId,
            'type'            => $type,
            'body'            => $body,
            'media_url'       => $mediaUrl,
            'meta_payload'    => $messageData,
            'status'          => 'delivered',
        ]);
    }

    /**
     * Envía un mensaje de texto saliente vía WhatsApp Cloud API.
     * Llamado desde SendWhatsAppMessageJob.
     */
    public function sendMessage(string $recipientPhone, string $text): array
    {
        $phoneNumberId = config('services.meta.whatsapp_phone_number_id');
        $accessToken   = config('services.meta.access_token');
        $apiVersion    = config('services.meta.graph_api_version', 'v21.0');

        $response = Http::withToken($accessToken)
            ->post("https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $recipientPhone,
                'type'              => 'text',
                'text'              => ['body' => $text],
            ]);

        if ($response->failed()) {
            $error = $response->json('error', []);
            Log::error('[WhatsApp] Error al enviar mensaje', [
                'recipient' => $recipientPhone,
                'error'     => $error,
            ]);

            return ['success' => false, 'error' => $error];
        }

        return [
            'success'    => true,
            'message_id' => $response->json('messages.0.id'),
        ];
    }

    /**
     * Envía el mensaje interactivo que abre un WhatsApp Flow.
     *
     * Solo funciona DENTRO de la ventana de servicio de 24h (es decir, cuando
     * el cliente escribió primero). Fuera de esa ventana hay que usar una
     * plantilla aprobada con componente de Flow, que es otro endpoint.
     *
     * El flow_token identifica esta sesión concreta del Flow: vuelve en cada
     * request al endpoint de datos y en la respuesta final, y es como se
     * correlaciona el Flow con la conversación del CRM.
     *
     * @param  string $recipientPhone Número del destinatario en formato E.164 sin '+'.
     * @param  string $flowId         ID del Flow publicado en WhatsApp Manager.
     * @param  string $flowToken      Identificador de esta sesión, generado por nosotros.
     * @param  string $flowCta        Texto del botón que abre el Flow (máx. 20 caracteres).
     * @param  string $bodyText       Texto del mensaje que acompaña al botón.
     * @param  string $firstScreen    Pantalla inicial del Flow JSON.
     * @return array{success: bool, message_id?: string, error?: mixed}
     */
    public function sendFlowMessage(
        string $recipientPhone,
        string $flowId,
        string $flowToken,
        string $flowCta,
        string $bodyText,
        string $firstScreen,
        ?string $headerText = null,
        ?string $footerText = null,
    ): array {
        $phoneNumberId = config('services.meta.whatsapp_phone_number_id');
        $accessToken   = config('services.meta.access_token');
        $apiVersion    = config('services.meta.graph_api_version', 'v21.0');

        $interactive = [
            'type' => 'flow',
            'body' => ['text' => $bodyText],
            'action' => [
                'name' => 'flow',
                'parameters' => [
                    // 'published' exige que el Flow esté publicado en Meta.
                    // Para probar un borrador hay que cambiarlo a 'draft'.
                    'flow_message_version' => '3',
                    'flow_token'           => $flowToken,
                    'flow_id'              => $flowId,
                    'flow_cta'             => $flowCta,
                    'flow_action'          => 'navigate',
                    'flow_action_payload'  => ['screen' => $firstScreen],
                ],
            ],
        ];

        if ($headerText !== null) {
            $interactive['header'] = ['type' => 'text', 'text' => $headerText];
        }

        if ($footerText !== null) {
            $interactive['footer'] = ['text' => $footerText];
        }

        $response = Http::withToken($accessToken)
            ->post("https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $recipientPhone,
                'type'              => 'interactive',
                'interactive'       => $interactive,
            ]);

        if ($response->failed()) {
            $error = $response->json('error', []);
            Log::error('[WhatsApp] Error al enviar el mensaje de Flow', [
                'recipient' => $recipientPhone,
                'flow_id'   => $flowId,
                'error'     => $error,
            ]);

            return ['success' => false, 'error' => $error];
        }

        return [
            'success'    => true,
            'message_id' => $response->json('messages.0.id'),
        ];
    }

    /**
     * Verifica la firma HMAC del webhook entrante.
     */
    public function verifySignature(string $rawBody, string $signature): bool
    {
        $appSecret = config('services.meta.app_secret');
        $expected  = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, $signature);
    }

    private function resolveDisplayName(string $senderId, array $contacts): ?string
    {
        foreach ($contacts as $contact) {
            if (($contact['wa_id'] ?? '') === $senderId) {
                return $contact['profile']['name'] ?? null;
            }
        }

        return null;
    }

    private function resolveMessageType(array $messageData): string
    {
        return match ($messageData['type'] ?? 'text') {
            'image'    => 'image',
            'audio'    => 'audio',
            'video'    => 'video',
            'document' => 'document',
            default    => 'text',
        };
    }

    private function extractBody(array $messageData, string $type): ?string
    {
        if ($type === 'text') {
            return $messageData['text']['body'] ?? null;
        }

        // Caption en adjuntos (opcional)
        return $messageData[$type]['caption'] ?? null;
    }

    private function extractMediaUrl(array $messageData, string $type): ?string
    {
        if ($type === 'text') {
            return null;
        }

        // WhatsApp Cloud API devuelve un media_id que hay que resolver vía /media/{id}
        // Guardamos el ID como referencia; la resolución de URL se hace on-demand
        $mediaId = $messageData[$type]['id'] ?? null;

        return $mediaId ? "whatsapp_media:{$mediaId}" : null;
    }
}
