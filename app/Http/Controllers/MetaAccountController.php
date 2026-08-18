<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MetaAccount;
use App\Services\ActivityLogService;
use App\Services\Meta\MetaAccountStatusService;
use App\Services\Meta\MetaCredentials;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cuentas de Meta vinculadas: lo que consume la vista de Cuentas.
 *
 * El flujo de vinculación es el **Embedded Signup** de Meta, que corre en el
 * navegador: el JS SDK abre un popup, el usuario elige su negocio y Meta
 * devuelve un `code` de 30 segundos de vida al `window` que lo abrió. Ese code
 * llega aquí y se cambia por un token de larga duración.
 *
 * Por qué el intercambio va en el backend y no en el Vue: hace falta el
 * `app_secret`, y ese no puede salir al navegador jamás.
 */
class MetaAccountController extends MetaBaseController
{
    public function __construct(
        private readonly MetaAccountStatusService $estado,
        private readonly MetaCredentials $credentials,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * GET /api/meta/accounts
     *
     * Estado de los tres canales. Une la tabla con el .env — ver
     * MetaAccountStatusService para la precedencia.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'accounts' => $this->estado->todos(),
            // Lo que el frontend necesita para arrancar el SDK de Meta. Se
            // manda desde aquí y no se hornea en el build porque el app_id es
            // distinto entre entornos y las VITE_* quedan congeladas en el
            // bundle.
            'config'   => [
                'app_id'          => $this->credentials->tiene('app_id')
                    ? $this->credentials->obtener('app_id')
                    : null,
                'graph_version'   => $this->credentials->versionApi(),
                // config_id del Embedded Signup, uno por canal. Se saca del
                // dashboard de Meta (Facebook Login for Business > Configs).
                'configurations'  => [
                    MetaAccount::CHANNEL_WHATSAPP  => config('services.meta.signup.whatsapp_config_id'),
                    MetaAccount::CHANNEL_INSTAGRAM => config('services.meta.signup.instagram_config_id'),
                    MetaAccount::CHANNEL_FACEBOOK  => config('services.meta.signup.facebook_config_id'),
                ],
            ],
        ]);
    }

    /**
     * POST /api/meta/accounts/{channel}/exchange
     *
     * Cambia el `code` del Embedded Signup por un token de larga duración y
     * guarda la cuenta.
     *
     * Body: { code, waba_id?, phone_number_id?, external_id?, display_name? }
     *
     * El code vive 30 segundos, así que esto se llama en cuanto el popup
     * responde. Si tarda más, Meta lo rechaza y hay que repetir el flujo.
     */
    public function exchange(Request $request, string $channel): JsonResponse
    {
        if (! in_array($channel, MetaAccount::channels(), true)) {
            return $this->jsonError('Canal no soportado.', 422);
        }

        $data = $request->validate([
            'code'            => ['required', 'string'],
            'waba_id'         => ['nullable', 'string', 'max:64'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
            'external_id'     => ['nullable', 'string', 'max:64'],
            'display_name'    => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        if ($user === null) {
            return $this->jsonError('Sesión no válida.', 401);
        }

        // Sin app_secret no hay intercambio posible, y el error de Meta sería
        // opaco. Se corta antes con un mensaje que dice qué falta.
        foreach (['app_id', 'app_secret'] as $clave) {
            if (! $this->credentials->tiene($clave)) {
                return $this->jsonError(
                    'Falta configurar ' . strtoupper("META_{$clave}") . ' en el servidor.',
                    503,
                );
            }
        }

        $token = $this->intercambiarCode($data['code']);

        if ($token === null) {
            return $this->jsonError(
                'Meta rechazó el código de autorización. Suele ser porque expiró '
                . '(dura 30 segundos): volvé a intentar la vinculación.',
                502,
            );
        }

        // El id relevante cambia según el canal: WhatsApp manda el
        // phone_number_id, los otros dos el id de la cuenta/página.
        $externalId = $data['phone_number_id'] ?? $data['external_id'] ?? null;

        $cuenta = MetaAccount::updateOrCreate(
            ['channel' => $channel],
            [
                'display_name'         => $data['display_name'] ?? null,
                'external_id'          => $externalId,
                'waba_id'              => $data['waba_id'] ?? null,
                'access_token'         => $token['access_token'],
                'token_expires_at'     => $token['expires_at'],
                'status'               => MetaAccount::STATUS_CONNECTED,
                'error_message'        => null,
                'connected_by_user_id' => $user->id,
                'verified_at'          => now(),
                'meta_payload'         => $data,
            ],
        );

        $this->activityLog->log(
            causerType: User::class,
            causerId: $user->id,
            targetType: MetaAccount::class,
            targetId: $cuenta->id,
            action: 'meta_account.connected',
            // El token NO va al log de actividad: quedaría en claro en la base.
            metadata: ['channel' => $channel, 'external_id' => $externalId],
        );

        return $this->jsonSuccess(['account' => $this->estado->paraCanal($channel, $cuenta->fresh())]);
    }

    /**
     * DELETE /api/meta/accounts/{channel}
     *
     * Desvincula la cuenta.
     *
     * NO borra la fila: se marca 'disconnected' y se limpia el token. Así queda
     * el rastro de que estuvo vinculada y quién la conectó, que es justo lo que
     * se quiere saber cuando alguien pregunta por qué dejaron de entrar
     * mensajes.
     */
    public function destroy(Request $request, string $channel): JsonResponse
    {
        $cuenta = MetaAccount::query()->where('channel', $channel)->first();

        if ($cuenta === null) {
            return $this->jsonError('Ese canal no está vinculado desde el CRM.', 404);
        }

        $cuenta->update([
            'access_token'     => null,
            'token_expires_at' => null,
            'status'           => MetaAccount::STATUS_DISCONNECTED,
            'verified_at'      => null,
        ]);

        $this->activityLog->log(
            causerType: User::class,
            causerId: $request->user()?->id,
            targetType: MetaAccount::class,
            targetId: $cuenta->id,
            action: 'meta_account.disconnected',
            metadata: ['channel' => $channel],
        );

        return $this->jsonSuccess(['account' => $this->estado->paraCanal($channel, $cuenta->fresh())]);
    }

    /**
     * POST /api/meta/accounts/{channel}/verify
     *
     * Comprueba contra la Graph API que el token todavía sirve.
     *
     * Hace falta porque "configurado" no es "funciona": un token revocado o
     * caducado pasa cualquier chequeo estático y falla al enviar. Esto lo
     * detecta antes de que un agente escriba un mensaje que no va a salir.
     */
    public function verify(string $channel): JsonResponse
    {
        $cuenta = MetaAccount::query()->where('channel', $channel)->first();

        // Sin fila, se verifica lo del .env, que es el caso real del proyecto.
        $token = $cuenta?->access_token
            ?? ($this->credentials->tiene('access_token') ? $this->credentials->obtener('access_token') : null);

        if ($token === null) {
            return $this->jsonError('No hay token que verificar para ese canal.', 422);
        }

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get($this->credentials->urlGraph('me'), ['fields' => 'id,name']);
        } catch (\Throwable $e) {
            Log::error('[Meta] No se pudo contactar la Graph API al verificar', [
                'channel' => $channel,
                'error'   => $e->getMessage(),
            ]);

            return $this->jsonError('No se pudo contactar con Meta. Reintentá en un momento.', 504);
        }

        if ($response->failed()) {
            $mensaje = $response->json('error.message') ?? 'Meta rechazó el token.';

            // Se persiste el error para que la UI lo muestre sin repetir la
            // llamada en cada carga de la página.
            $cuenta?->update([
                'status'        => MetaAccount::STATUS_ERROR,
                'error_message' => $mensaje,
            ]);

            return $this->jsonError($mensaje, 502);
        }

        $cuenta?->update([
            'status'        => MetaAccount::STATUS_CONNECTED,
            'error_message' => null,
            'verified_at'   => now(),
            // Meta devuelve el nombre real del negocio: mejor que el que
            // adivinó el frontend.
            'display_name'  => $response->json('name') ?? $cuenta->display_name,
        ]);

        return $this->jsonSuccess([
            'verified' => true,
            'meta'     => $response->json(),
            'account'  => $this->estado->paraCanal($channel, $cuenta?->fresh()),
        ]);
    }

    /**
     * Cambia el code del popup por un token de larga duración.
     *
     * @return array{access_token: string, expires_at: \Illuminate\Support\Carbon|null}|null
     */
    private function intercambiarCode(string $code): ?array
    {
        try {
            $response = Http::timeout(20)->get($this->credentials->urlGraph('oauth/access_token'), [
                'client_id'     => $this->credentials->obtener('app_id'),
                'client_secret' => $this->credentials->obtener('app_secret'),
                'code'          => $code,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Meta] Falló el intercambio del code', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            // Se registra el mensaje de Meta pero NO el code: es un secreto de
            // un solo uso.
            Log::warning('[Meta] Meta rechazó el code', [
                'error' => $response->json('error.message'),
            ]);

            return null;
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            return null;
        }

        // expires_in viene en segundos y solo para tokens con caducidad; los de
        // sistema no lo traen y su ausencia significa "no caduca".
        $expiresIn = $response->json('expires_in');

        return [
            'access_token' => $token,
            'expires_at'   => is_numeric($expiresIn) ? now()->addSeconds((int) $expiresIn) : null,
        ];
    }
}
