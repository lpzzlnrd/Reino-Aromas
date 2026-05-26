<?php


namespace App\Console\Commands;

use App\Services\Meta\FacebookSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncFacebookConversationsCommand extends Command
{
    protected $signature = 'meta:facebook:sync';

    protected $description = 'Sync Facebook conversations/messages for the configured page';

    public function __construct(
        private FacebookSyncService $syncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pageId = (string) config('services.meta.facebook.page_id');
        $pageToken = (string) config('services.meta.facebook.page_access_token');

        if ($pageId === '' || $pageToken === '') {
            $this->error('Missing FACEBOOK_PAGE_ID or FACEBOOK_PAGE_ACCESS_TOKEN.');

            return self::FAILURE;
        }

        try {
            $result = $this->syncService->syncConfiguredPage();

            $this->info(sprintf(
                'Facebook sync completed. Conversations: %d, Messages: %d',
                (int) ($result['synced_conversations'] ?? 0),
                (int) ($result['synced_messages'] ?? 0),
            ));

            Log::channel('stack')->info('Facebook sync command completed', $result);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::channel('stack')->error('Facebook sync command failed', [
                'message' => $e->getMessage(),
            ]);

            $this->error('Facebook sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
