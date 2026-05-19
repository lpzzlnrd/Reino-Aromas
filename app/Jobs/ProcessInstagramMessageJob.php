<?php

namespace App\Jobs;

use App\Services\Meta\InstagramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessInstagramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(private readonly array $payload) {}

    public function handle(InstagramService $instagramService): void
    {
        Log::info('[Instagram] Procesando webhook payload', [
            'entry_count' => count($this->payload['entry'] ?? []),
        ]);

        $instagramService->processWebhookPayload($this->payload);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[Instagram] Job fallido tras ' . $this->tries . ' intentos', [
            'error' => $exception->getMessage(),
        ]);
    }
}
