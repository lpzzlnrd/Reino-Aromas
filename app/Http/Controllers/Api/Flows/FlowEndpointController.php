<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Flows;

use App\Exceptions\Flows\FlowDecryptionException;
use App\Http\Controllers\Controller;
use App\Services\Flows\FlowEncryptionService;
use App\Services\Flows\FlowRouterService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Canal de datos de WhatsApp Flows.
 *
 * Un único POST recibe todas las interacciones del Flow, cifradas. El flujo es:
 *
 *   validar firma → descifrar → decidir pantalla → cifrar respuesta
 *
 * Los códigos de error NO son los habituales de Laravel: Meta define códigos
 * propios y reacciona distinto a cada uno.
 *
 *   421 → no pude descifrar; el cliente le pide al usuario que reintente
 *   432 → firma inválida; Meta descarta la request
 *   500 → error nuestro; Meta marca el Flow como no saludable
 *
 * Devolver un 200 con un error dentro deja el Flow colgado en el teléfono del
 * cliente, así que los códigos importan de verdad.
 */
class FlowEndpointController extends Controller
{
    /**
     * Código que Meta define para "no pude descifrar la request".
     * No existe como constante en Symfony (421 es Misdirected Request).
     */
    private const HTTP_NO_DESCIFRABLE = 421;

    /**
     * Código que Meta define para "la firma no valida".
     */
    private const HTTP_FIRMA_INVALIDA = 432;

    public function __construct(
        private readonly FlowEncryptionService $encryption,
        private readonly FlowRouterService $router,
    ) {}

    /**
     * POST /api/webhooks/flows
     */
    public function __invoke(Request $request): Response
    {
        $privateKey = (string) config('services.meta.flows.private_key');

        if ($privateKey === '') {
            Log::error('[Flows] FLOWS_PRIVATE_KEY no está configurada.');

            return response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // 1. Firma. Se valida contra el cuerpo CRUDO: cualquier
        //    reserialización cambiaría el HMAC.
        $firmaValida = $this->encryption->isSignatureValid(
            $request->getContent(),
            $request->header('X-Hub-Signature-256', ''),
        );

        if (! $firmaValida) {
            Log::warning('[Flows] Firma inválida en el endpoint.');

            return response('', self::HTTP_FIRMA_INVALIDA);
        }

        // 2. Descifrado.
        try {
            $descifrado = $this->encryption->decryptRequest(
                $request->all(),
                $privateKey,
                (string) config('services.meta.flows.passphrase'),
            );
        } catch (FlowDecryptionException $e) {
            // Problema con esta request en concreto: Meta puede reintentar.
            Log::warning('[Flows] No se pudo descifrar la request', ['motivo' => $e->getMessage()]);

            return response('', self::HTTP_NO_DESCIFRABLE);
        } catch (Throwable $e) {
            // Problema de configuración nuestro: reintentar no lo arregla.
            Log::error('[Flows] Error al descifrar', ['error' => $e->getMessage()]);

            return response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // 3. Lógica de pantallas.
        try {
            $respuesta = $this->router->handle($descifrado['body']);
        } catch (Throwable $e) {
            Log::error('[Flows] Error al resolver la pantalla', [
                'error'  => $e->getMessage(),
                'action' => $descifrado['body']['action'] ?? null,
                'screen' => $descifrado['body']['screen'] ?? null,
            ]);

            return response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // 4. Cifrado de la respuesta.
        //
        //    Meta espera el base64 como cuerpo plano con content-type de texto,
        //    NO un JSON. Devolver application/json aquí rompe el Flow.
        try {
            $cifrada = $this->encryption->encryptResponse(
                $respuesta,
                $descifrado['aesKey'],
                $descifrado['iv'],
            );
        } catch (Throwable $e) {
            Log::error('[Flows] Error al cifrar la respuesta', ['error' => $e->getMessage()]);

            return response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response($cifrada, Response::HTTP_OK)
            ->header('Content-Type', 'text/plain');
    }
}
