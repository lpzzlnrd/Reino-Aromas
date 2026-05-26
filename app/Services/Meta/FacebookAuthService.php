<?php

declare(strict_types=1);

namespace App\Services\Meta;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class FacebookAuthService
{
    private string $graphApiVersion;
    private string $appId;
    private string $appSecret;
    private ?string $redirectUri;
    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->graphApiVersion = (string) config('services.meta.graph_api_version');
        $this->appId = (string) config('services.meta.app_id');
        $this->appSecret = (string) config('services.meta.app_secret');
        $this->redirectUri = config('services.meta.facebook.redirect_uri');
        $this->http = $http ?? new Client(['base_uri' => "https://graph.facebook.com/{$this->graphApiVersion}/"]);
    }

    public function exchangeCodeForToken(string $code): ?string
    {
        try {
            $response = $this->http->get('oauth/access_token', [
                'query' => [
                    'client_id' => $this->appId,
                    'client_secret' => $this->appSecret,
                    'redirect_uri' => $this->redirectUri,
                    'code' => $code,
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            return $data['access_token'] ?? null;
        } catch (RequestException $e) {
            Log::channel('stack')->error('Facebook token exchange failed', [
                'message' => $e->getMessage(),
                'body' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : null,
            ]);

            return null;
        }
    }

    public function exchangeForLongLivedToken(string $shortLivedToken): ?string
    {
        try {
            $response = $this->http->get('oauth/access_token', [
                'query' => [
                    'grant_type' => 'fb_exchange_token',
                    'client_id' => $this->appId,
                    'client_secret' => $this->appSecret,
                    'fb_exchange_token' => $shortLivedToken,
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            return $data['access_token'] ?? null;
        } catch (RequestException $e) {
            Log::channel('stack')->error('Facebook long-lived token exchange failed', [
                'message' => $e->getMessage(),
                'body' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : null,
            ]);

            return null;
        }
    }
}
