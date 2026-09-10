<?php

namespace App\Services\Meta;

use App\Models\InstagramAutomation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza los Ice Breakers y el Persistent Menu del CRM con Instagram.
 *
 * Instagram no tiene Flows; estos dos mecanismos son lo más cercano. Se
 * configuran en `/me/messenger_profile` sobre graph.instagram.com (NO
 * graph.facebook.com: ver MetaCredentials::urlGraphInstagram()).
 *
 * La sincronización es de reemplazo total, no incremental: Meta no permite
 * editar un botón suelto, hay que mandar el conjunto completo. Por eso
 * `sincronizar()` siempre envía todos los activos de ese tipo.
 */
class InstagramAutomationService
{
    public function __construct(private readonly MetaCredentials $credentials) {}

    /**
     * Sube los Ice Breakers activos a Meta.
     *
     * @return array{success: bool, error?: mixed}
     */
    public function sincronizarIceBreakers(): array
    {
        $botones = InstagramAutomation::query()
            ->active()
            ->ofKind(InstagramAutomation::KIND_ICE_BREAKER)
            ->ordered()
            ->limit(InstagramAutomation::MAX_ICE_BREAKERS)
            ->get();

        // Sin botones activos se BORRA la configuración en Meta. Si solo se
        // omitiera la llamada, quedarían vivos en Instagram los que el CRM ya
        // no conoce, y al tocarlos llegaría un postback huérfano.
        if ($botones->isEmpty()) {
            return $this->borrarCampo('ice_breakers');
        }

        $payload = [
            'platform'     => 'instagram',
            'ice_breakers' => [[
                'locale'          => 'default',
                'call_to_actions' => $botones->map(fn (InstagramAutomation $b): array => [
                    'question' => $b->title,
                    'payload'  => $b->payload,
                ])->all(),
            ]],
        ];

        $resultado = $this->enviar($payload);

        if ($resultado['success']) {
            $this->marcarSincronizados($botones->pluck('id')->all());
        }

        return $resultado;
    }

    /**
     * Sube el Persistent Menu activo a Meta.
     *
     * @return array{success: bool, error?: mixed}
     */
    public function sincronizarMenu(): array
    {
        $items = InstagramAutomation::query()
            ->active()
            ->ofKind(InstagramAutomation::KIND_MENU_ITEM)
            ->ordered()
            ->limit(InstagramAutomation::MAX_MENU_ITEMS)
            ->get();

        if ($items->isEmpty()) {
            return $this->borrarCampo('persistent_menu');
        }

        $payload = [
            'platform'        => 'instagram',
            'persistent_menu' => [[
                'locale' => 'default',
                // composer_input_disabled no está disponible en Instagram
                // (sí en Messenger). Se manda en false por claridad.
                'composer_input_disabled' => false,
                'call_to_actions'         => $items->map(function (InstagramAutomation $i): array {
                    // Un item con url es un enlace; sin url, un postback que
                    // llega al webhook.
                    if ($i->url !== null && $i->url !== '') {
                        return [
                            'type'  => 'web_url',
                            'title' => $i->title,
                            'url'   => $i->url,
                        ];
                    }

                    return [
                        'type'    => 'postback',
                        'title'   => $i->title,
                        'payload' => $i->payload,
                    ];
                })->all(),
            ]],
        ];

        $resultado = $this->enviar($payload);

        if ($resultado['success']) {
            $this->marcarSincronizados($items->pluck('id')->all());
        }

        return $resultado;
    }

    /**
     * Lo que Meta tiene configurado ahora mismo.
     *
     * Sirve para detectar divergencias: alguien pudo configurar botones por
     * fuera del CRM, o quedar restos de una sincronización a medias.
     *
     * @return array{success: bool, ice_breakers?: array<mixed>, persistent_menu?: array<mixed>, error?: mixed}
     */
    public function estadoEnMeta(): array
    {
        $token = $this->token();

        if ($token === null) {
            return ['success' => false, 'error' => 'Falta META_INSTAGRAM_ACCESS_TOKEN.'];
        }

        try {
            $response = Http::timeout(20)
                ->withToken($token)
                ->get($this->credentials->urlGraphInstagram('me/messenger_profile'), [
                    'platform' => 'instagram',
                    'fields'   => 'ice_breakers,persistent_menu',
                ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if ($response->failed()) {
            return ['success' => false, 'error' => $response->json('error.message')];
        }

        // Meta devuelve los campos dentro de un array `data` con un elemento.
        $data = $response->json('data.0', []);

        return [
            'success'         => true,
            'ice_breakers'    => $data['ice_breakers'] ?? [],
            'persistent_menu' => $data['persistent_menu'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed> $payload
     * @return array{success: bool, error?: mixed}
     */
    private function enviar(array $payload): array
    {
        $token = $this->token();

        if ($token === null) {
            return ['success' => false, 'error' => 'Falta META_INSTAGRAM_ACCESS_TOKEN.'];
        }

        try {
            $response = Http::timeout(30)
                ->withToken($token)
                ->post($this->credentials->urlGraphInstagram('me/messenger_profile'), $payload);
        } catch (\Throwable $e) {
            Log::error('[Instagram] Falló la sincronización de automatizaciones', [
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }

        if ($response->failed()) {
            Log::error('[Instagram] Meta rechazó la configuración', [
                'error'  => $response->json('error'),
                'campos' => array_keys($payload),
            ]);

            return ['success' => false, 'error' => $response->json('error.message')];
        }

        Log::info('[Instagram] Automatizaciones sincronizadas', [
            'campos' => array_keys($payload),
        ]);

        return ['success' => true];
    }

    /**
     * @return array{success: bool, error?: mixed}
     */
    private function borrarCampo(string $campo): array
    {
        $token = $this->token();

        if ($token === null) {
            return ['success' => false, 'error' => 'Falta META_INSTAGRAM_ACCESS_TOKEN.'];
        }

        try {
            $response = Http::timeout(20)
                ->withToken($token)
                ->delete($this->credentials->urlGraphInstagram('me/messenger_profile'), [
                    'fields' => [$campo],
                ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        // Borrar algo que no existe no es un fallo: el estado final es el
        // deseado igual.
        if ($response->failed()) {
            Log::info('[Instagram] No se pudo borrar la configuración (puede que no existiera)', [
                'campo' => $campo,
                'error' => $response->json('error.message'),
            ]);
        }

        return ['success' => true];
    }

    /**
     * @param  list<int> $ids
     */
    private function marcarSincronizados(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        // update() directo y no save() por modelo: no hay que disparar eventos
        // ni tocar updated_at, que es justo lo que se compara para saber si
        // hay cambios pendientes.
        InstagramAutomation::query()
            ->whereIn('id', $ids)
            ->update(['synced_at' => now()]);
    }

    private function token(): ?string
    {
        $token = $this->credentials->obtener('instagram_access_token')
            ?: $this->credentials->obtener('access_token');

        return $token !== null && $token !== '' ? (string) $token : null;
    }
}
