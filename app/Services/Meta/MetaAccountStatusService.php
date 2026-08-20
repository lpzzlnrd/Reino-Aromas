<?php

declare(strict_types=1);

namespace App\Services\Meta;

use App\Models\MetaAccount;

/**
 * Une las DOS fuentes de verdad sobre qué cuenta está vinculada por canal.
 *
 * 1. La tabla meta_accounts: lo vinculado desde el CRM (botón "Vincular").
 * 2. El .env: lo que el dev configuró a mano en el servidor (p.ej.
 *    META_WHATSAPP_PHONE_NUMBER_ID ya estaba puesto antes de que existiera
 *    este flujo).
 *
 * La tabla gana cuando tiene una fila conectada: es más reciente y
 * específica. El campo `source` dice de dónde salió el estado
 * ('crm' | 'env'); la UI lo usa para decidir si mostrar "Desvincular" — si
 * vino del .env, desvincular desde la UI mentiría (el .env seguiría
 * teniendo el valor).
 */
class MetaAccountStatusService
{
    public function __construct(private MetaCredentials $credentials) {}

    /**
     * @return array{
     *     channel: string,
     *     connected: bool,
     *     source: 'crm'|'env'|null,
     *     name: string|null,
     *     can_disconnect: bool,
     *     signup_config_id: string|null,
     * }
     */
    public function estadoDe(string $channel): array
    {
        $cuenta = MetaAccount::query()->where('channel', $channel)->first();

        if ($cuenta !== null && $cuenta->isConnected()) {
            return [
                'channel'          => $channel,
                'connected'        => true,
                'source'           => 'crm',
                'name'             => $cuenta->display_name,
                'can_disconnect'   => true,
                'signup_config_id' => $this->signupConfigId($channel),
            ];
        }

        if ($this->conectadoPorEnv($channel)) {
            return [
                'channel'          => $channel,
                'connected'        => true,
                'source'           => 'env',
                'name'             => null,
                'can_disconnect'   => false,
                'signup_config_id' => $this->signupConfigId($channel),
            ];
        }

        return [
            'channel'          => $channel,
            'connected'        => false,
            'source'           => null,
            'name'             => null,
            'can_disconnect'   => false,
            'signup_config_id' => $this->signupConfigId($channel),
        ];
    }

    /**
     * @return list<array{channel: string, connected: bool, source: 'crm'|'env'|null, name: string|null, can_disconnect: bool, signup_config_id: string|null}>
     */
    public function estadoDeTodos(): array
    {
        return [
            $this->estadoDe(MetaAccount::CHANNEL_FACEBOOK),
            $this->estadoDe(MetaAccount::CHANNEL_INSTAGRAM),
            $this->estadoDe(MetaAccount::CHANNEL_WHATSAPP),
        ];
    }

    private function conectadoPorEnv(string $channel): bool
    {
        return match ($channel) {
            MetaAccount::CHANNEL_FACEBOOK => $this->credentials->tiene('facebook.page_access_token'),
            MetaAccount::CHANNEL_INSTAGRAM => $this->credentials->tiene('instagram_account_id')
                && $this->credentials->tiene('access_token'),
            MetaAccount::CHANNEL_WHATSAPP => $this->credentials->tiene('whatsapp_phone_number_id')
                && $this->credentials->tiene('access_token'),
            default => false,
        };
    }

    private function signupConfigId(string $channel): ?string
    {
        $clave = match ($channel) {
            MetaAccount::CHANNEL_FACEBOOK => 'signup.facebook_config_id',
            MetaAccount::CHANNEL_INSTAGRAM => 'signup.instagram_config_id',
            MetaAccount::CHANNEL_WHATSAPP => 'signup.whatsapp_config_id',
            default => null,
        };

        if ($clave === null || ! $this->credentials->tiene($clave)) {
            return null;
        }

        return $this->credentials->obtener($clave);
    }
}
