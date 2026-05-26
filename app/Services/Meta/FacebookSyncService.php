<?php

declare(strict_types=1);

namespace App\Services\Meta;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FacebookSyncService
{
    private string $graphApiVersion;
    private Client $http;

    public function __construct(
        private FacebookAccountService $accounts,
        ?Client $http = null,
    ) {
        $this->graphApiVersion = (string) config('services.meta.graph_api_version');
        $this->http = $http ?? new Client(['base_uri' => "https://graph.facebook.com/{$this->graphApiVersion}/"]);
    }

    /**
     * Sincroniza el historial de conversaciones de la Page configurada en .env.
     *
     * @return array{synced_conversations: int, synced_messages: int}
     */
    public function syncConfiguredPage(): array
    {
        $pageId = (string) config('services.meta.facebook.page_id');
        $pageToken = (string) config('services.meta.facebook.page_access_token');

        if ($pageId === '' || $pageToken === '') {
            throw new \RuntimeException('Facebook page is not configured (FACEBOOK_PAGE_ID, FACEBOOK_PAGE_ACCESS_TOKEN).');
        }

        $conversations = $this->fetchConversations($pageId, $pageToken);

        $syncedConversations = 0;
        $syncedMessages = 0;

        foreach ($conversations as $conv) {
            $psid = $this->resolveOtherParticipant($conv, $pageId);
            if ($psid === null) {
                continue;
            }

            $contact = $this->upsertContact($psid, $pageToken);
            $conversation = $this->upsertConversation($contact);

            $messages = $this->fetchMessages($conv['id'] ?? '', $pageToken);
            $syncedMessages += $this->persistMessages($messages, $conversation, $psid);

            $this->touchConversationLastMessage($conversation);
            $syncedConversations++;
        }

        return [
            'synced_conversations' => $syncedConversations,
            'synced_messages' => $syncedMessages,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchConversations(string $pageId, string $pageToken): array
    {
        try {
            $response = $this->http->get("{$pageId}/conversations", [
                'query' => [
                    'access_token' => $pageToken,
                    'platform' => 'messenger',
                    'fields' => 'id,participants,updated_time',
                    'limit' => 100,
                ],
                'timeout' => 30,
            ]);

            $data = json_decode((string) $response->getBody(), true);

            return $data['data'] ?? [];
        } catch (RequestException $e) {
            Log::channel('stack')->error('Facebook fetchConversations failed', [
                'message' => $e->getMessage(),
                'body' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : null,
            ]);

            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchMessages(string $conversationId, string $pageToken): array
    {
        if ($conversationId === '') {
            return [];
        }

        try {
            $response = $this->http->get($conversationId, [
                'query' => [
                    'access_token' => $pageToken,
                    'fields' => 'messages.limit(100){id,message,from,created_time,attachments}',
                ],
                'timeout' => 30,
            ]);

            $data = json_decode((string) $response->getBody(), true);

            return $data['messages']['data'] ?? [];
        } catch (RequestException $e) {
            Log::channel('stack')->error('Facebook fetchMessages failed', [
                'conversation_id' => $conversationId,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $conversation
     */
    private function resolveOtherParticipant(array $conversation, string $pageId): ?string
    {
        $participants = $conversation['participants']['data'] ?? [];
        foreach ($participants as $participant) {
            $id = (string) ($participant['id'] ?? '');
            if ($id !== '' && $id !== $pageId) {
                return $id;
            }
        }

        return null;
    }

    private function upsertContact(string $psid, string $pageToken): Contact
    {
        $contact = Contact::query()
            ->identifiedByChannel(Contact::CHANNEL_FACEBOOK, $psid)
            ->first();

        if ($contact !== null) {
            $contact->forceFill(['last_seen_at' => now()])->save();

            return $contact;
        }

        $profile = $this->accounts->fetchUserProfile($psid, $pageToken);

        return Contact::query()->create([
            'channel' => Contact::CHANNEL_FACEBOOK,
            'channel_id' => $psid,
            'display_name' => $profile['name'] ?? 'Facebook User',
            'profile_picture_url' => $profile['profile_pic'] ?? null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function upsertConversation(Contact $contact): Conversation
    {
        $conversation = $contact->activeConversation()->first();

        if ($conversation !== null) {
            return $conversation;
        }

        return $contact->conversations()->create([
            'status' => Conversation::STATUS_OPEN,
            'within_24h_window' => false,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function persistMessages(array $messages, Conversation $conversation, string $contactPsid): int
    {
        $persisted = 0;

        DB::transaction(function () use ($messages, $conversation, $contactPsid, &$persisted): void {
            foreach ($messages as $payload) {
                $externalId = (string) ($payload['id'] ?? '');
                if ($externalId === '') {
                    continue;
                }

                if (Message::query()->where('external_id', $externalId)->exists()) {
                    continue;
                }

                $direction = $this->resolveDirection($payload, $contactPsid);
                [$type, $body, $mediaUrl] = $this->resolveContent($payload);
                $createdAt = $this->parseTimestamp($payload['created_time'] ?? null);

                Message::query()->create([
                    'conversation_id' => $conversation->id,
                    'sender_user_id' => null,
                    'direction' => $direction,
                    'channel' => Contact::CHANNEL_FACEBOOK,
                    'external_id' => $externalId,
                    'type' => $type,
                    'body' => $body,
                    'media_url' => $mediaUrl,
                    'meta_payload' => $payload,
                    'status' => Message::STATUS_DELIVERED,
                    'sent_at' => $direction === Message::DIRECTION_OUTBOUND ? $createdAt : null,
                    'delivered_at' => $createdAt,
                    'created_at' => $createdAt,
                ]);

                $persisted++;
            }
        });

        return $persisted;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveDirection(array $payload, string $contactPsid): string
    {
        $fromId = (string) ($payload['from']['id'] ?? '');

        return $fromId === $contactPsid
            ? Message::DIRECTION_INBOUND
            : Message::DIRECTION_OUTBOUND;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function resolveContent(array $payload): array
    {
        $attachment = $payload['attachments']['data'][0] ?? null;

        if ($attachment === null) {
            return [Message::TYPE_TEXT, $payload['message'] ?? null, null];
        }

        $attachmentType = (string) ($attachment['type'] ?? 'file');

        return match ($attachmentType) {
            'image' => [Message::TYPE_IMAGE, $payload['message'] ?? null, $attachment['image_data']['url'] ?? null],
            'video' => [Message::TYPE_VIDEO, $payload['message'] ?? null, $attachment['video_data']['url'] ?? null],
            'audio' => [Message::TYPE_AUDIO, $payload['message'] ?? null, $attachment['file_url'] ?? null],
            default => [Message::TYPE_DOCUMENT, $payload['message'] ?? null, $attachment['file_url'] ?? null],
        };
    }

    private function parseTimestamp(?string $timestamp): Carbon
    {
        if ($timestamp === null || $timestamp === '') {
            return now();
        }

        try {
            return Carbon::parse($timestamp);
        } catch (\Throwable) {
            return now();
        }
    }

    private function touchConversationLastMessage(Conversation $conversation): void
    {
        $last = $conversation->messages()->latest('created_at')->first();
        if ($last === null) {
            return;
        }

        $isInboundRecent = $last->direction === Message::DIRECTION_INBOUND
            && $last->created_at !== null
            && $last->created_at->gt(now()->subDay());

        $conversation->forceFill([
            'last_message_at' => $last->created_at,
            'within_24h_window' => $isInboundRecent,
        ])->save();
    }
}
