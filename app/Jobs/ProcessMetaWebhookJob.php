<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
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
     * @var list<int>
     */
    public array $backoff = [10, 30, 120];

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private array $payload,
    ) {}

    public function handle(): void
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

                $this->persistMessagingEvent($event);
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
     * @param array<string, mixed> $event
     */
    private function persistMessagingEvent(array $event): void
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

        DB::transaction(function () use ($contactPsid, $messagePayload, $externalId, $eventTime): void {
            $contact = Contact::query()->firstOrCreate(
                ['channel' => Contact::CHANNEL_FACEBOOK, 'channel_id' => $contactPsid],
                [
                    'display_name' => 'Facebook User',
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ],
            );

            $contact->forceFill(['last_seen_at' => now()])->save();

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
