<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Contact;
use App\Models\Message;
use App\Services\Meta\FacebookMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendFacebookMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 120];

    public function __construct(
        private int $messageId,
    ) {}

    public function handle(FacebookMessagingService $messagingService): void
    {
        $message = Message::query()
            ->with(['conversation.contact'])
            ->find($this->messageId);

        if ($message === null || $message->direction !== Message::DIRECTION_OUTBOUND) {
            return;
        }

        $contact = $message->conversation?->contact;
        if ($contact === null || $contact->channel !== Contact::CHANNEL_FACEBOOK) {
            $this->markFailed($message, 'Conversation is not linked to a Facebook contact.');

            return;
        }

        $text = $message->body;
        if ($text === null || $text === '') {
            $this->markFailed($message, 'Message body is empty.');

            return;
        }

        $result = $messagingService->sendTextMessage($contact->channel_id, $text);
        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException((string) ($result['error'] ?? 'Failed to send Facebook message.'));
        }

        $message->forceFill([
            'external_id' => (string) ($result['message_id'] ?? $message->external_id),
            'meta_payload' => $result['payload'] ?? $message->meta_payload,
            'status' => Message::STATUS_SENT,
            'sent_at' => $message->sent_at ?? now(),
            'failed_reason' => null,
        ])->save();
    }

    public function failed(\Throwable $exception): void
    {
        $message = Message::query()->find($this->messageId);
        if ($message !== null) {
            $this->markFailed($message, $exception->getMessage());
        }

        Log::channel('stack')->error('SendFacebookMessageJob failed', [
            'message_id' => $this->messageId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function markFailed(Message $message, string $reason): void
    {
        $message->forceFill([
            'status' => Message::STATUS_FAILED,
            'failed_reason' => $reason,
        ])->save();
    }
}
