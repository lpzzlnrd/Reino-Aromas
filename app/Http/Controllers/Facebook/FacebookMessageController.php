<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\MetaBaseController;
use App\Models\Conversation;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Meta\FacebookMessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacebookMessageController extends MetaBaseController
{
    public function __construct(
        private FacebookMessagingService $messagingService,
    ) {}

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
        ]);

        $contact = $conversation->contact;
        if ($contact === null || $contact->channel !== Contact::CHANNEL_FACEBOOK) {
            return $this->jsonError('Conversation is not linked to a Facebook contact.', 422);
        }

        $result = $this->messagingService->sendTextMessage($contact->channel_id, $validated['body']);
        if (! ($result['success'] ?? false)) {
            return $this->jsonError((string) ($result['error'] ?? 'Failed to send Facebook message.'), 502);
        }

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $request->user()?->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'channel' => Contact::CHANNEL_FACEBOOK,
            'external_id' => (string) ($result['message_id'] ?? ''),
            'type' => Message::TYPE_TEXT,
            'body' => $validated['body'],
            'media_url' => null,
            'meta_payload' => $result['payload'] ?? null,
            'status' => Message::STATUS_SENT,
            'failed_reason' => null,
            'sent_at' => now(),
            'delivered_at' => null,
            'read_at' => null,
            'created_at' => now(),
        ]);

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
        ])->save();

        return $this->jsonSuccess([
            'queued' => false,
            'sent' => true,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'external_id' => $message->external_id,
        ]);
    }
}
