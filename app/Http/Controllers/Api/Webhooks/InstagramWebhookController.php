<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInstagramMessageJob;
use App\Services\Meta\InstagramService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class InstagramWebhookController extends Controller
{
    public function __construct(private readonly InstagramService $instagramService) {}

    /**
     * GET /api/webhooks/instagram
     * Verificación inicial del webhook desde Meta Developers.
     */
    public function verify(Request $request): Response
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.meta.webhook_verify_token')) {
            Log::info('[Instagram] Webhook verificado correctamente');
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('[Instagram] Intento de verificación fallido', [
            'mode'  => $mode,
            'token' => $token,
        ]);

        return response('Forbidden', 403);
    }

    /**
     * POST /api/webhooks/instagram
     * Recibe eventos entrantes de Meta. Responde 200 inmediatamente
     * y delega el procesamiento al job para no bloquear el response.
     * Meta corta la conexión a los 5s si no recibe respuesta.
     */
    public function receive(Request $request): Response
    {
        $signature = $request->header('X-Hub-Signature-256', '');
        $rawBody   = $request->getContent();

        if (!$this->instagramService->verifySignature($rawBody, $signature)) {
            Log::warning('[Instagram] Firma inválida en webhook entrante');
            return response('Forbidden', 403);
        }

        $payload = $request->all();

        // Solo procesar eventos de página de Instagram
        if (($payload['object'] ?? '') !== 'instagram') {
            return response('OK', 200);
        }

        ProcessInstagramMessageJob::dispatch($payload);

        return response('OK', 200);
    }
}
