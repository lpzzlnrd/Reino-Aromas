<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\MetaBaseController;
use App\Jobs\SendFacebookMessageJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacebookMessageController extends MetaBaseController
{
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
        ]);

        $contact = $conversation->contact;
        if ($contact === null || $contact->channel !== Contact::CHANNEL_FACEBOOK) {
            return $this->jsonError('Conversation is not linked to a Facebook contact.', 422);
        }

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $request->user()?->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'channel' => Contact::CHANNEL_FACEBOOK,
            'external_id' => null,
            'type' => Message::TYPE_TEXT,
            'body' => $validated['body'],
            'media_url' => null,
            'meta_payload' => null,
            'status' => Message::STATUS_PENDING,
            'failed_reason' => null,
            'sent_at' => null,
            'delivered_at' => null,
            'read_at' => null,
            'created_at' => now(),
        ]);

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
        ])->save();

        SendFacebookMessageJob::dispatch($message->id);

        return $this->jsonSuccess([
            'queued' => true,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'status' => $message->status,
        ], 202);
    }
}
