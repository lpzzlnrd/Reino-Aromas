<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMetaWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de Facebook Messenger.
 *
 * Existía el Job (ProcessMetaWebhookJob) pero no el endpoint que lo dispara.
 * Apuntar el webhook de la Página a la URL de WhatsApp *parece* funcionar
 * porque el verify token es el mismo para las tres redes y Meta da el visto
 * bueno, pero WhatsAppWebhookController descarta todo lo que no traiga
 * object=whatsapp_business_account: los mensajes de la Página se perdían en
 * silencio, sin error en ningún log.
 */
class FacebookWebhookController extends Controller
{
    /**
     * GET /api/webhooks/facebook
     *
     * Verificación inicial desde el dashboard de Meta.
     *
     * Nota: se leen `hub_mode` y `hub_verify_token` con guion bajo aunque Meta
     * envía `hub.mode` con punto — PHP convierte los puntos en guiones bajos
     * en los nombres de los parámetros. NO "arreglar" esto.
     */
    public function verify(Request $request): Response
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.meta.webhook_verify_token')) {
            Log::info('[Facebook] Webhook verificado correctamente');

            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('[Facebook] Intento de verificación fallido', [
            'mode'  => $mode,
            'token' => $token,
        ]);

        return response('Forbidden', 403);
    }

    /**
     * POST /api/webhooks/facebook
     *
     * Responde 200 de inmediato y delega al Job: Meta corta la conexión a los
     * 5s y reintenta el evento si no recibe respuesta a tiempo.
     */
    public function receive(Request $request): Response
    {
        $signature = $request->header('X-Hub-Signature-256', '');
        $rawBody   = $request->getContent();

        if (! $this->verifySignature($rawBody, $signature)) {
            Log::warning('[Facebook] Firma inválida en webhook entrante');

            return response('Forbidden', 403);
        }

        $payload = $request->all();

        // Los mensajes de Página llegan con object=page. Se responde 200 igual
        // cuando no coincide: un 4xx haría que Meta reintente indefinidamente
        // un evento que nunca vamos a procesar.
        if (($payload['object'] ?? '') !== 'page') {
            return response('OK', 200);
        }

        ProcessMetaWebhookJob::dispatch($payload);

        return response('OK', 200);
    }

    /**
     * Valida el HMAC-SHA256 que Meta firma con el app secret.
     *
     * Duplica la lógica de WhatsAppService::verifySignature porque no hay un
     * servicio de Messenger que la aloje; FacebookMessagingService solo envía.
     */
    private function verifySignature(string $rawBody, string $signature): bool
    {
        $appSecret = (string) config('services.meta.app_secret');

        // Sin app secret configurado no se puede validar nada. Se rechaza en
        // vez de dejar pasar: aceptar sin firma abriría el endpoint a
        // cualquiera que conozca la URL.
        if ($appSecret === '' || $signature === '') {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, $signature);
    }
}
