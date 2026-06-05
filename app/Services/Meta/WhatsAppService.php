<?php

namespace App\Services\Meta;

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

        $conversation = $this->conversationService->getOrOpenActive($contact);

        $this->storeInboundMessage($conversation, $message, $messageId);

        $this->ticketService->ensureTicketExists($conversation);

        $this->conversationService->updateLastMessageAt($conversation);
        $this->conversationService->refreshWindowStatus($conversation);
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
