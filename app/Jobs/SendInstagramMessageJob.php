<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Meta\InstagramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendInstagramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(
        private readonly string $recipientIgsid,
        private readonly string $text,
        private readonly int $messageId,
    ) {}

    public function handle(InstagramService $instagramService): void
    {
        $result = $instagramService->sendMessage($this->recipientIgsid, $this->text);

        $message = Message::find($this->messageId);

        if (!$message) {
            Log::warning("[Instagram] Mensaje {$this->messageId} no encontrado para actualizar estado");
            return;
        }

        if ($result['success']) {
            $message->update([
                'status'      => 'sent',
                'external_id' => $result['message_id'],
                'sent_at'     => now(),
            ]);
        } else {
            $message->update([
                'status'        => 'failed',
                'failed_reason' => json_encode($result['error']),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[Instagram] SendInstagramMessageJob fallido para mensaje {$this->messageId}", [
            'error' => $exception->getMessage(),
        ]);

        Message::find($this->messageId)?->update([
            'status'        => 'failed',
            'failed_reason' => $exception->getMessage(),
        ]);
    }
}
