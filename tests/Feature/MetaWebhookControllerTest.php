<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessMetaWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_receive_rejects_invalid_signature(): void
    {
        config()->set('services.meta.app_secret', 'test_secret');

        $response = $this->postJson('/api/webhooks/meta', ['entry' => []], [
            'X-Hub-Signature-256' => 'sha256=invalid',
        ]);

        $response->assertStatus(403)
            ->assertJson(['status' => 'invalid_signature']);
    }

    public function test_receive_queues_job_with_valid_signature(): void
    {
        Queue::fake();
        config()->set('services.meta.app_secret', 'test_secret');

        $payload = [
            'entry' => [
                ['messaging' => [['sender' => ['id' => '1'], 'recipient' => ['id' => '2'], 'message' => ['mid' => 'm1', 'text' => 'hi']]]],
            ],
        ];

        $signature = 'sha256='.hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), 'test_secret');

        $response = $this->withHeaders(['X-Hub-Signature-256' => $signature])
            ->postJson('/api/webhooks/meta', $payload);

        $response->assertStatus(202)
            ->assertJson(['status' => 'queued']);

        Queue::assertPushed(ProcessMetaWebhookJob::class);
    }
}
