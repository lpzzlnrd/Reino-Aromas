<?php

declare(strict_types=1);

namespace App\Http\Controllers\Meta;

use App\Http\Controllers\MetaBaseController;
use App\Models\MetaAccount;
use App\Services\Meta\FacebookAuthService;
use App\Services\Meta\MetaAccountStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cuentas vinculadas por Embedded Signup (Ajustes > Cuentas vinculadas).
 *
 * El popup de Meta no es OAuth clásico: el JS SDK abre FB.login() y dos
 * datos llegan por caminos separados que el frontend debe juntar antes de
 * llamar aquí:
 *   - el `code`      → por el callback interno de FB.login()
 *   - los ids del negocio (waba_id, etc.) → por postMessage
 *
 * El `code` vive 30 segundos: exchange() debe llamarse de inmediato.
 */
class MetaAccountController extends MetaBaseController
{
    private const CANALES = [
        MetaAccount::CHANNEL_FACEBOOK,
        MetaAccount::CHANNEL_INSTAGRAM,
        MetaAccount::CHANNEL_WHATSAPP,
    ];

    public function __construct(
        private MetaAccountStatusService $status,
        private FacebookAuthService $authService,
    ) {}

    public function index(): JsonResponse
    {
        return $this->jsonSuccess([
            'app_id'   => (string) config('services.meta.app_id'),
            'accounts' => $this->status->estadoDeTodos(),
        ]);
    }

    public function exchange(Request $request, string $channel): JsonResponse
    {
        if (! in_array($channel, self::CANALES, true)) {
            return $this->jsonError('Canal desconocido.', 404);
        }

        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $shortToken = $this->authService->exchangeCodeForToken($data['code']);
        if ($shortToken === null || $shortToken === '') {
            return $this->respondExchangeFailure($channel, 'No se pudo canjear el código (puede haber expirado).');
        }

        $longToken = $this->authService->exchangeForLongLivedToken($shortToken);
        if ($longToken === null || $longToken === '') {
            return $this->respondExchangeFailure($channel, 'No se pudo obtener un token de larga duración.');
        }

        $cuenta = MetaAccount::query()->updateOrCreate(
            ['channel' => $channel],
            [
                'access_token'         => $longToken,
                'status'               => MetaAccount::STATUS_CONNECTED,
                'error_message'        => null,
                'connected_by_user_id' => $request->user()?->id,
            ],
        );

        return $this->jsonSuccess(['account' => $this->status->estadoDe($channel)], 200)
            ->setStatusCode($cuenta->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Recibe los ids del negocio que llegaron por postMessage y los junta
     * con el token ya guardado por exchange(). Sin esta llamada, la cuenta
     * queda con token pero sin saberse qué número/página quedó vinculado.
     */
    public function verify(Request $request, string $channel): JsonResponse
    {
        if (! in_array($channel, self::CANALES, true)) {
            return $this->jsonError('Canal desconocido.', 404);
        }

        $data = $request->validate([
            'external_id'  => ['nullable', 'string'],
            'waba_id'      => ['nullable', 'string'],
            'display_name' => ['nullable', 'string'],
            'meta_payload' => ['nullable', 'array'],
        ]);

        $cuenta = MetaAccount::query()->where('channel', $channel)->first();
        if ($cuenta === null) {
            return $this->jsonError('Primero hay que completar la vinculación (falta el token).', 409);
        }

        $cuenta->fill($data);
        $cuenta->verified_at = now();
        $cuenta->save();

        return $this->jsonSuccess(['account' => $this->status->estadoDe($channel)]);
    }

    public function destroy(string $channel): JsonResponse
    {
        if (! in_array($channel, self::CANALES, true)) {
            return $this->jsonError('Canal desconocido.', 404);
        }

        $cuenta = MetaAccount::query()->where('channel', $channel)->first();

        if ($cuenta === null) {
            return $this->jsonError('Este canal no está vinculado desde el CRM.', 404);
        }

        $cuenta->delete();

        return $this->jsonSuccess(['account' => $this->status->estadoDe($channel)]);
    }

    private function respondExchangeFailure(string $channel, string $message): JsonResponse
    {
        MetaAccount::query()->updateOrCreate(
            ['channel' => $channel],
            ['status' => MetaAccount::STATUS_ERROR, 'error_message' => $message],
        );

        return $this->jsonError($message, 502);
    }
}
