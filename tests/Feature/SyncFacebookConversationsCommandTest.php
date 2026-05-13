<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Meta\FacebookSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncFacebookConversationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fails_when_facebook_config_missing(): void
    {
        config()->set('services.meta.facebook.page_id', '');
        config()->set('services.meta.facebook.page_access_token', '');

        $this->artisan('meta:facebook:sync')
            ->expectsOutput('Missing FACEBOOK_PAGE_ID or FACEBOOK_PAGE_ACCESS_TOKEN.')
            ->assertExitCode(1);
    }

    public function test_command_runs_successfully_when_service_returns_counts(): void
    {
        config()->set('services.meta.facebook.page_id', 'page_123');
        config()->set('services.meta.facebook.page_access_token', 'token_123');

        $mock = Mockery::mock(FacebookSyncService::class);
        $mock->shouldReceive('syncConfiguredPage')
            ->once()
            ->andReturn([
                'synced_conversations' => 2,
                'synced_messages' => 7,
            ]);
        $this->app->instance(FacebookSyncService::class, $mock);

        $this->artisan('meta:facebook:sync')
            ->expectsOutput('Facebook sync completed. Conversations: 2, Messages: 7')
            ->assertExitCode(0);
    }
}
