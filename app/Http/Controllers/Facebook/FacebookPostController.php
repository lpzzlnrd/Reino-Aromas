<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\MetaBaseController;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FacebookPostController extends MetaBaseController
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:63206'],
        ]);

        $pageId = (string) config('services.meta.facebook.page_id');
        $pageAccessToken = (string) config('services.meta.facebook.page_access_token');
        $graphApiVersion = (string) config('services.meta.graph_api_version');

        if ($pageId === '' || $pageAccessToken === '') {
            return $this->jsonError('Facebook page is not configured.', 500);
        }

        try {
            $http = new Client(['base_uri' => "https://graph.facebook.com/{$graphApiVersion}/"]);
            $response = $http->post("{$pageId}/feed", [
                'query' => ['access_token' => $pageAccessToken],
                'json' => ['message' => $validated['message']],
                'timeout' => 30,
            ]);

            $data = json_decode((string) $response->getBody(), true);

            return $this->jsonSuccess([
                'post_id' => (string) ($data['id'] ?? ''),
                'payload' => is_array($data) ? $data : [],
            ], 201);
        } catch (RequestException $e) {
            Log::channel('stack')->error('Facebook post creation failed', [
                'message' => $e->getMessage(),
                'body' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : null,
            ]);

            return $this->jsonError('Failed to create Facebook post.', 502);
        }
    }
}
