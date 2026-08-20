<?php

declare(strict_types=1);

namespace App\Services\Meta;

use App\Models\MetaAccount;

/**
 * Estado de vinculación de cada canal, para la vista de Cuentas.
 *
 * Existe porque hay DOS fuentes de verdad y la UI necesita una sola:
 *
 *   1. La tabla `meta_accounts` — lo que se vinculó desde el CRM.
 *   2. El `.env` — lo que el dev configuró a mano en el servidor.
 *
 * El caso real de este proyecto es el 2: las META_* se cargan a mano y no hay
 * ninguna fila en la tabla. Si la UI solo mirara la tabla diría "No vinculado"
 * con WhatsApp funcionando perfectamente, que es justo la confusión que se
 * quiere evitar.
 *
 * Precedencia: la tabla gana. Si hay fila, es porque alguien vinculó desde la
 * UI y eso es más reciente y más específico que el .env.
 */
class MetaAccountStatusService
{
    public function __construct(
        private readonly MetaCredentials $credentials = new MetaCredentials(),
    ) {}

    /**
     * Credenciales del .env que hacen falta por canal.
     *
     * Se comprueban TODAS: un access_token sin phone_number_id no permite
     * enviar nada, así que el canal no está realmente configurado.
     *
     * @var array<string, list<string>>
     */
    private const ENV_POR_CANAL = [
        MetaAccount::CHANNEL_WHATSAPP  => ['access_token', 'whatsapp_phone_number_id'],
        MetaAccount::CHANNEL_INSTAGRAM => ['access_token', 'instagram_account_id'],
        MetaAccount::CHANNEL_FACEBOOK  => ['facebook.page_id', 'facebook.page_access_token'],
    ];

    /**
     * Estado de los tres canales, en el orden que los pinta la UI.
     *
     * @return list<array<string, mixed>>
     */
    public function todos(): array
    {
        // Una sola consulta para los tres: la vista los pide siempre juntos.
        $filas = MetaAccount::query()->get()->keyBy('channel');

        return array_map(
            fn (string $canal): array => $this->paraCanal($canal, $filas->get($canal)),
            MetaAccount::channels(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function paraCanal(string $canal, ?MetaAccount $cuenta = null): array
    {
        // El ?? false permite pasar la cuenta ya cargada desde todos() y evitar
        // una consulta por canal.
        $cuenta ??= MetaAccount::query()->where('channel', $canal)->first();

        $faltanEnv = $this->credentials->faltantes(self::ENV_POR_CANAL[$canal] ?? []);
        $envListo  = $faltanEnv === [];

        $base = [
            'channel'       => $canal,
            'label'         => MetaAccount::channelLabels()[$canal] ?? $canal,
            // Qué falta en el .env. La UI lo muestra para que el dev sepa por
            // qué el botón está deshabilitado, en vez de adivinar.
            'missing_env'   => $faltanEnv,
            // ¿Se puede lanzar el flujo de vinculación? Sin app_id no hay
            // popup posible: el SDK de Meta no arranca.
            'can_connect'   => $this->credentials->tiene('app_id'),
        ];

        // La tabla gana: si hay fila, alguien vinculó desde la UI.
        if ($cuenta !== null) {
            return $base + [
                'status'           => $cuenta->status,
                'connected'        => $cuenta->estaOperativa(),
                'display_name'     => $cuenta->display_name,
                'external_id'      => $cuenta->external_id,
                'expires_soon'     => $cuenta->caducaPronto(),
                'token_expires_at' => $cuenta->token_expires_at?->toIso8601String(),
                'error_message'    => $cuenta->error_message,
                'verified_at'      => $cuenta->verified_at?->toIso8601String(),
                'connected_by'     => $cuenta->connectedBy?->name,
                // De dónde salió, para que la UI pueda explicarlo.
                'source'           => 'crm',
            ];
        }

        // Sin fila: el .env decide. Este es el estado real del proyecto hoy.
        return $base + [
            'status'           => $envListo ? MetaAccount::STATUS_CONNECTED : MetaAccount::STATUS_DISCONNECTED,
            'connected'        => $envListo,
            // No se puede saber el nombre del negocio sin llamar a la Graph API;
            // la UI muestra el id o un texto genérico.
            'display_name'     => null,
            'external_id'      => $envListo ? $this->idDelEnv($canal) : null,
            'expires_soon'     => false,
            'token_expires_at' => null,
            'error_message'    => null,
            'verified_at'      => null,
            'connected_by'     => null,
            'source'           => $envListo ? 'env' : null,
        ];
    }

    /**
     * El identificador que el .env tiene para este canal.
     *
     * Se muestra en la UI para que el dev confirme que apuntó al número o a la
     * página correcta — un id equivocado no falla, simplemente escribe a otro
     * sitio.
     */
    private function idDelEnv(string $canal): ?string
    {
        $clave = match ($canal) {
            MetaAccount::CHANNEL_WHATSAPP  => 'whatsapp_phone_number_id',
            MetaAccount::CHANNEL_INSTAGRAM => 'instagram_account_id',
            MetaAccount::CHANNEL_FACEBOOK  => 'facebook.page_id',
            default                        => null,
        };

        if ($clave === null || ! $this->credentials->tiene($clave)) {
            return null;
        }

        return $this->credentials->obtener($clave);
    }
}
