<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MetaAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vinculación de cuentas de Meta (/app/settings/accounts).
 *
 * La vista mostraba "No vinculado" escrito a mano y los botones no tenían
 * @click. Ahora el estado sale de la API, que une dos fuentes: la tabla
 * meta_accounts (lo vinculado desde el CRM) y el .env (lo configurado a mano en
 * el servidor). Estos tests fijan esa precedencia y el flujo del popup.
 */
class MetaAccountLinkingTest extends TestCase
{
    use RefreshDatabase;

    private function agente(): User
    {
        return User::factory()->create([
            'role'      => User::ROLE_ADMINISTRADOR,
            'is_active' => true,
        ]);
    }

    /** Deja el .env de Meta vacío: el estado real del proyecto hoy. */
    private function sinCredenciales(): void
    {
        config([
            'services.meta.app_id'                     => null,
            'services.meta.app_secret'                 => null,
            'services.meta.access_token'               => null,
            'services.meta.whatsapp_phone_number_id'   => null,
            'services.meta.instagram_account_id'       => null,
            'services.meta.facebook.page_id'           => null,
            'services.meta.facebook.page_access_token' => null,
        ]);
    }

    public function test_el_listado_devuelve_los_tres_canales(): void
    {
        $this->sinCredenciales();

        $response = $this->actingAs($this->agente())->getJson('/api/meta/accounts');

        $response->assertOk();
        $this->assertCount(3, $response->json('accounts'));

        $canales = array_column($response->json('accounts'), 'channel');
        $this->assertEqualsCanonicalizing(['whatsapp', 'instagram', 'facebook'], $canales);
    }

    /**
     * Sin app_id no hay popup posible, y la UI necesita saberlo para deshabilitar
     * el botón en vez de abrir una ventana rota.
     */
    public function test_sin_app_id_los_canales_no_se_pueden_conectar(): void
    {
        $this->sinCredenciales();

        $response = $this->actingAs($this->agente())->getJson('/api/meta/accounts');

        $this->assertNull($response->json('config.app_id'));

        foreach ($response->json('accounts') as $cuenta) {
            $this->assertFalse($cuenta['can_connect']);
            $this->assertFalse($cuenta['connected']);
        }
    }

    /**
     * El caso REAL del proyecto: las META_* se cargan a mano y no hay ninguna
     * fila en la tabla. Si la UI solo mirara la tabla diría "No vinculado" con
     * WhatsApp funcionando.
     */
    public function test_un_canal_configurado_solo_en_el_env_cuenta_como_conectado(): void
    {
        $this->sinCredenciales();
        config([
            'services.meta.access_token'             => 'token-de-prueba',
            'services.meta.whatsapp_phone_number_id' => '123456789',
        ]);

        $response = $this->actingAs($this->agente())->getJson('/api/meta/accounts');

        $whatsapp = collect($response->json('accounts'))->firstWhere('channel', 'whatsapp');

        $this->assertTrue($whatsapp['connected']);
        $this->assertSame('env', $whatsapp['source']);
        $this->assertSame('123456789', $whatsapp['external_id']);

        // Instagram comparte el access_token pero le falta su propio id.
        $instagram = collect($response->json('accounts'))->firstWhere('channel', 'instagram');
        $this->assertFalse($instagram['connected']);
        $this->assertContains('META_INSTAGRAM_ACCOUNT_ID', $instagram['missing_env']);
    }

    /** La tabla gana sobre el .env: es más reciente y más específica. */
    public function test_la_tabla_tiene_precedencia_sobre_el_env(): void
    {
        $this->sinCredenciales();
        config([
            'services.meta.access_token'             => 'token-del-env',
            'services.meta.whatsapp_phone_number_id' => '999',
        ]);

        MetaAccount::create([
            'channel'      => MetaAccount::CHANNEL_WHATSAPP,
            'display_name' => 'Reino Aromas',
            'external_id'  => '111',
            'access_token' => 'token-del-crm',
            'status'       => MetaAccount::STATUS_CONNECTED,
        ]);

        $response = $this->actingAs($this->agente())->getJson('/api/meta/accounts');
        $whatsapp = collect($response->json('accounts'))->firstWhere('channel', 'whatsapp');

        $this->assertSame('crm', $whatsapp['source']);
        $this->assertSame('111', $whatsapp['external_id']);
        $this->assertSame('Reino Aromas', $whatsapp['display_name']);
    }

    /**
     * Un token caducado NO cuenta como conectado aunque el status diga
     * 'connected': Meta va a rechazar cada envío.
     */
    public function test_un_token_caducado_no_cuenta_como_conectado(): void
    {
        $this->sinCredenciales();

        MetaAccount::create([
            'channel'          => MetaAccount::CHANNEL_FACEBOOK,
            'access_token'     => 'token-viejo',
            'token_expires_at' => now()->subDay(),
            'status'           => MetaAccount::STATUS_CONNECTED,
        ]);

        $response = $this->actingAs($this->agente())->getJson('/api/meta/accounts');
        $facebook = collect($response->json('accounts'))->firstWhere('channel', 'facebook');

        $this->assertFalse($facebook['connected']);
    }

    /** El token NUNCA debe salir en una respuesta de la API. */
    public function test_el_access_token_no_se_expone_en_la_api(): void
    {
        $this->sinCredenciales();

        MetaAccount::create([
            'channel'      => MetaAccount::CHANNEL_WHATSAPP,
            'access_token' => 'SECRETO-QUE-NO-DEBE-SALIR',
            'status'       => MetaAccount::STATUS_CONNECTED,
        ]);

        $response = $this->actingAs($this->agente())->getJson('/api/meta/accounts');

        $response->assertOk();
        $response->assertDontSee('SECRETO-QUE-NO-DEBE-SALIR');
    }

    /** El token va cifrado en reposo: la APP_KEY lo protege en la base. */
    public function test_el_token_se_guarda_cifrado(): void
    {
        $cuenta = MetaAccount::create([
            'channel'      => MetaAccount::CHANNEL_WHATSAPP,
            'access_token' => 'token-en-claro',
            'status'       => MetaAccount::STATUS_CONNECTED,
        ]);

        $crudo = \DB::table('meta_accounts')->where('id', $cuenta->id)->value('access_token');

        $this->assertNotSame('token-en-claro', $crudo);
        // Pero el modelo lo descifra de forma transparente.
        $this->assertSame('token-en-claro', $cuenta->fresh()->access_token);
    }

    public function test_el_canje_del_code_guarda_la_cuenta(): void
    {
        config([
            'services.meta.app_id'     => 'app-123',
            'services.meta.app_secret' => 'secreto',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'access_token' => 'token-largo',
                'expires_in'   => 5184000,
            ]),
        ]);

        $response = $this->actingAs($this->agente())
            ->postJson('/api/meta/accounts/whatsapp/exchange', [
                'code'            => 'code-del-popup',
                'waba_id'         => 'waba-777',
                'phone_number_id' => '15550001111',
            ]);

        $response->assertOk();
        $response->assertJsonPath('account.connected', true);

        $this->assertDatabaseHas('meta_accounts', [
            'channel'     => 'whatsapp',
            'waba_id'     => 'waba-777',
            'external_id' => '15550001111',
            'status'      => 'connected',
        ]);

        // Y el token quedó guardado con su caducidad.
        $this->assertNotNull(MetaAccount::first()->token_expires_at);
    }

    /**
     * Sin app_secret el intercambio es imposible y el error de Meta sería
     * opaco. Se corta antes con un 503 que dice qué falta.
     */
    public function test_el_canje_falla_claro_sin_credenciales(): void
    {
        $this->sinCredenciales();
        Http::fake();

        $response = $this->actingAs($this->agente())
            ->postJson('/api/meta/accounts/whatsapp/exchange', ['code' => 'x']);

        $response->assertStatus(503);
        $this->assertStringContainsString('META_APP_ID', $response->json('message'));

        Http::assertNothingSent();
    }

    /** Un code expirado (viven 30s) tiene que dar un mensaje que lo explique. */
    public function test_un_code_rechazado_por_meta_devuelve_502(): void
    {
        config([
            'services.meta.app_id'     => 'app-123',
            'services.meta.app_secret' => 'secreto',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(
                ['error' => ['message' => 'This authorization code has expired.']],
                400,
            ),
        ]);

        $response = $this->actingAs($this->agente())
            ->postJson('/api/meta/accounts/whatsapp/exchange', ['code' => 'viejo']);

        $response->assertStatus(502);
        $this->assertStringContainsString('30 segundos', $response->json('message'));
        $this->assertDatabaseCount('meta_accounts', 0);
    }

    public function test_un_canal_invalido_se_rechaza(): void
    {
        $response = $this->actingAs($this->agente())
            ->postJson('/api/meta/accounts/tiktok/exchange', ['code' => 'x']);

        $response->assertStatus(422);
    }

    /**
     * Desvincular NO borra la fila: conserva el rastro de quién la conectó, que
     * es lo que se quiere saber cuando dejan de entrar mensajes.
     */
    public function test_desvincular_conserva_la_fila_y_limpia_el_token(): void
    {
        $cuenta = MetaAccount::create([
            'channel'      => MetaAccount::CHANNEL_INSTAGRAM,
            'access_token' => 'token',
            'status'       => MetaAccount::STATUS_CONNECTED,
        ]);

        $response = $this->actingAs($this->agente())
            ->deleteJson('/api/meta/accounts/instagram');

        $response->assertOk();

        $this->assertDatabaseHas('meta_accounts', [
            'id'     => $cuenta->id,
            'status' => 'disconnected',
        ]);
        $this->assertNull($cuenta->fresh()->access_token);
    }

    public function test_verificar_marca_error_si_meta_rechaza_el_token(): void
    {
        MetaAccount::create([
            'channel'      => MetaAccount::CHANNEL_WHATSAPP,
            'access_token' => 'token-revocado',
            'status'       => MetaAccount::STATUS_CONNECTED,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(
                ['error' => ['message' => 'Invalid OAuth access token.']],
                401,
            ),
        ]);

        $response = $this->actingAs($this->agente())
            ->postJson('/api/meta/accounts/whatsapp/verify');

        $response->assertStatus(502);

        // El error se persiste para que la UI lo muestre sin repetir la llamada.
        $this->assertDatabaseHas('meta_accounts', [
            'channel'       => 'whatsapp',
            'status'        => 'error',
            'error_message' => 'Invalid OAuth access token.',
        ]);
    }

    public function test_verificar_actualiza_el_nombre_que_devuelve_meta(): void
    {
        MetaAccount::create([
            'channel'      => MetaAccount::CHANNEL_FACEBOOK,
            'display_name' => 'nombre viejo',
            'access_token' => 'token-bueno',
            'status'       => MetaAccount::STATUS_CONNECTED,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['id' => '42', 'name' => 'Reino Aromas Oficial']),
        ]);

        $response = $this->actingAs($this->agente())
            ->postJson('/api/meta/accounts/facebook/verify');

        $response->assertOk();
        $response->assertJsonPath('verified', true);

        $this->assertDatabaseHas('meta_accounts', [
            'channel'      => 'facebook',
            'display_name' => 'Reino Aromas Oficial',
        ]);
        $this->assertNotNull(MetaAccount::first()->verified_at);
    }

    /** Las rutas van bajo auth: son datos de configuración del negocio. */
    public function test_las_rutas_exigen_sesion(): void
    {
        $this->getJson('/api/meta/accounts')->assertUnauthorized();
        $this->postJson('/api/meta/accounts/whatsapp/verify')->assertUnauthorized();
        $this->deleteJson('/api/meta/accounts/whatsapp')->assertUnauthorized();
    }
}
