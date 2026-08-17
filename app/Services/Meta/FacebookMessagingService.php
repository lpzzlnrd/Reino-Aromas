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
        // Este servicio degrada en vez de lanzar (a diferencia de WhatsApp e
        // Instagram) porque su contrato es devolver el array de resultado. Se
        // nombra la variable que falta para no dejar al dev adivinando.
        $faltantes = (new MetaCredentials())->faltantes([
            'facebook.page_id',
            'facebook.page_access_token',
        ]);

        if ($faltantes !== []) {
            $error = 'Facebook no está configurado: falta ' . implode(', ', $faltantes) . '.';
            Log::warning('[Facebook] Envío omitido por credenciales ausentes', ['faltantes' => $faltantes]);

            return ['success' => false, 'error' => $error];
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
