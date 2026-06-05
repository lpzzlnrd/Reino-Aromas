<?php

declare(strict_types=1);

namespace App\Services\Meta;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class FacebookMessagingService
{
    private string $graphApiVersion;
    private ?string $pageId;
    private ?string $pageAccessToken;
    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->graphApiVersion = (string) config('services.meta.graph_api_version');
        $this->pageId = config('services.meta.facebook.page_id');
        $this->pageAccessToken = config('services.meta.facebook.page_access_token');
        $this->http = $http ?? new Client(['base_uri' => "https://graph.facebook.com/{$this->graphApiVersion}/"]);
    }

    /**
     * @return array{success: bool, message_id?: string, error?: string, payload?: array<string, mixed>}
     */
    public function sendTextMessage(string $recipientPsid, string $message): array
    {
        if ($this->pageId === null || $this->pageId === '' || $this->pageAccessToken === null || $this->pageAccessToken === '') {
            return ['success' => false, 'error' => 'Facebook page is not configured.'];
        }

        try {
            $response = $this->http->post("{$this->pageId}/messages", [
                'query' => [
                    'access_token' => $this->pageAccessToken,
                ],
                'json' => [
                    'messaging_type' => 'RESPONSE',
                    'recipient' => ['id' => $recipientPsid],
                    'message' => ['text' => $message],
                ],
                'timeout' => 30,
            ]);

            $data = json_decode((string) $response->getBody(), true);

            return [
                'success' => true,
                'message_id' => (string) ($data['message_id'] ?? ''),
                'payload' => is_array($data) ? $data : [],
            ];
        } catch (RequestException $e) {
            Log::channel('stack')->error('Facebook sendTextMessage failed', [
                'message' => $e->getMessage(),
                'body' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : null,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to send Facebook message.',
            ];
        }
    }
}
