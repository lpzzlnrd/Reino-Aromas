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
        $igAccountId = config('services.meta.instagram_account_id');
        $accessToken = config('services.meta.access_token');
        $apiVersion  = config('services.meta.graph_api_version', 'v21.0');

        $response = Http::withToken($accessToken)
            ->post("https://graph.facebook.com/{$apiVersion}/{$igAccountId}/messages", [
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
     */
    public function verifySignature(string $rawBody, string $signature): bool
    {
        $appSecret = config('services.meta.app_secret');
        $expected  = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, $signature);
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
