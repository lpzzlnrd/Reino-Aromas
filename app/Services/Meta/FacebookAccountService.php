<?php

declare(strict_types=1);

namespace App\Services\Meta;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class FacebookAccountService
{
    private string $graphApiVersion;
    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->graphApiVersion = (string) config('services.meta.graph_api_version');
        $this->http = $http ?? new Client(['base_uri' => "https://graph.facebook.com/{$this->graphApiVersion}/"]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchPages(string $userAccessToken): array
    {
        try {
            $response = $this->http->get('me/accounts', [
                'query' => [
                    'access_token' => $userAccessToken,
                    'fields' => 'id,name,username,access_token,category,picture',
                    'limit' => 100,
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            return $data['data'] ?? [];
        } catch (RequestException $e) {
            Log::channel('stack')->error('Facebook fetchPages failed', [
                'message' => $e->getMessage(),
                'body' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : null,
            ]);

            return [];
        }
    }

    /**
     * @return array{name: string, profile_pic: ?string}|null
     */
    public function fetchUserProfile(string $psid, string $pageAccessToken): ?array
    {
        try {
            $response = $this->http->get($psid, [
                'query' => [
                    'access_token' => $pageAccessToken,
                    'fields' => 'first_name,last_name,profile_pic',
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            $name = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));

            return [
                'name' => $name !== '' ? $name : 'Facebook User',
                'profile_pic' => $data['profile_pic'] ?? null,
            ];
        } catch (RequestException $e) {
            Log::channel('stack')->warning('Facebook fetchUserProfile failed', [
                'psid' => $psid,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{id: string, name: ?string, verified_name: ?string}|null
     */
    public function fetchPageInfo(string $pageId, string $pageAccessToken): ?array
    {
        try {
            $response = $this->http->get($pageId, [
                'query' => [
                    'access_token' => $pageAccessToken,
                    'fields' => 'id,name,username,category',
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            return [
                'id' => (string) ($data['id'] ?? $pageId),
                'name' => $data['name'] ?? null,
                'verified_name' => $data['username'] ?? null,
            ];
        } catch (RequestException $e) {
            Log::channel('stack')->error('Facebook fetchPageInfo failed', [
                'page_id' => $pageId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
