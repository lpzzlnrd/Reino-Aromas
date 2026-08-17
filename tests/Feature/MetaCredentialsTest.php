<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Meta\FacebookMessagingService;
use App\Services\Meta\InstagramService;
use App\Services\Meta\MetaCredentials;
use App\Services\Meta\WhatsAppService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Credenciales de Meta ausentes.
 *
 * Antes, una variable que faltaba en el .env no se notaba hasta que Meta
 * devolvía un error: sendMessage() armaba la URL
 * `graph.facebook.com/v21.0//messages` con el id vacío y el 404 resultante
 * parecía un problema de la API. Con tres reintentos, el mismo error confuso
 * tres veces.
 *
 * Estos tests fijan el comportamiento nuevo: fallar temprano nombrando la
 * variable, y no verificar firmas con una clave vacía.
 */
class MetaCredentialsTest extends TestCase
{
    /** Deja las credenciales en un estado conocido: ausentes. */
    private function sinCredenciales(): void
    {
        config([
            'services.meta.app_secret'                 => null,
            'services.meta.access_token'               => null,
            'services.meta.whatsapp_phone_number_id'   => null,
            'services.meta.instagram_account_id'       => null,
            'services.meta.facebook.page_id'           => null,
            'services.meta.facebook.page_access_token' => null,
        ]);
    }

    public function test_obtener_lanza_nombrando_la_variable_de_entorno(): void
    {
        $this->sinCredenciales();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/META_ACCESS_TOKEN/');

        (new MetaCredentials())->obtener('access_token');
    }

    /**
     * Una variable declarada pero vacía (META_ACCESS_TOKEN=) llega como '' y es
     * igual de inservible que si no estuviera. Este era el caso que se colaba.
     */
    public function test_una_credencial_vacia_cuenta_como_ausente(): void
    {
        config(['services.meta.access_token' => '   ']);

        $credentials = new MetaCredentials();

        $this->assertFalse($credentials->tiene('access_token'));
        $this->expectException(RuntimeException::class);
        $credentials->obtener('access_token');
    }

    public function test_la_url_de_graph_usa_la_version_configurada(): void
    {
        config(['services.meta.graph_api_version' => 'v22.0']);

        $this->assertSame(
            'https://graph.facebook.com/v22.0/123/messages',
            (new MetaCredentials())->urlGraph('123/messages'),
        );
    }

    /** Sin versión en el .env sigue funcionando: v21.0 está vigente. */
    public function test_la_version_tiene_un_default(): void
    {
        config(['services.meta.graph_api_version' => null]);

        $this->assertSame('v21.0', (new MetaCredentials())->versionApi());
    }

    public function test_faltantes_devuelve_los_nombres_de_las_variables(): void
    {
        $this->sinCredenciales();

        $faltan = (new MetaCredentials())->faltantes(['access_token', 'app_secret']);

        $this->assertSame(['META_ACCESS_TOKEN', 'META_APP_SECRET'], $faltan);
    }

    /**
     * Lo que de verdad importa: NO se manda un request a Meta con la URL rota.
     */
    public function test_whatsapp_no_llama_a_meta_sin_credenciales(): void
    {
        $this->sinCredenciales();
        Http::fake();

        try {
            app(WhatsAppService::class)->sendMessage('584121234567', 'hola');
            $this->fail('Se esperaba una RuntimeException por credenciales ausentes.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('META_WHATSAPP_PHONE_NUMBER_ID', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_instagram_no_llama_a_meta_sin_credenciales(): void
    {
        $this->sinCredenciales();
        Http::fake();

        try {
            app(InstagramService::class)->sendMessage('17841400000000000', 'hola');
            $this->fail('Se esperaba una RuntimeException por credenciales ausentes.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('META_INSTAGRAM_ACCOUNT_ID', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * El agujero de seguridad: hash_hmac con una clave vacía es reproducible
     * por cualquiera, así que un atacante que supiera que el secreto falta
     * podría firmar un payload y hacerse pasar por Meta. Debe rechazarse.
     */
    public function test_la_firma_se_rechaza_sin_app_secret(): void
    {
        $this->sinCredenciales();

        $body = '{"object":"whatsapp_business_account"}';
        // Firma que un atacante calcularía sabiendo que el secreto está vacío.
        $forjada = 'sha256=' . hash_hmac('sha256', $body, '');

        $this->assertFalse(
            app(WhatsAppService::class)->verifySignature($body, $forjada),
            'Un HMAC con clave vacía no puede aceptarse como firma válida.',
        );

        $this->assertFalse(
            app(InstagramService::class)->verifySignature($body, $forjada),
        );
    }

    /** Con el secreto puesto, una firma legítima sí pasa. */
    public function test_la_firma_valida_se_acepta(): void
    {
        config(['services.meta.app_secret' => 'secreto-de-prueba']);

        $body  = '{"object":"whatsapp_business_account"}';
        $firma = 'sha256=' . hash_hmac('sha256', $body, 'secreto-de-prueba');

        $this->assertTrue(app(WhatsAppService::class)->verifySignature($body, $firma));
        $this->assertFalse(app(WhatsAppService::class)->verifySignature($body, 'sha256=otra-cosa'));
    }

    /**
     * Facebook degrada en vez de lanzar: su contrato es devolver el array de
     * resultado. Pero el error tiene que nombrar la variable que falta.
     */
    public function test_facebook_degrada_nombrando_la_variable(): void
    {
        $this->sinCredenciales();
        Http::fake();

        $resultado = (new FacebookMessagingService())->sendTextMessage('123', 'hola');

        $this->assertFalse($resultado['success']);
        $this->assertStringContainsString('META_FACEBOOK_PAGE_ID', $resultado['error']);
    }

    public function test_el_comando_meta_check_informa_lo_que_falta(): void
    {
        $this->sinCredenciales();

        $this->artisan('meta:check')
            ->expectsOutputToContain('META_ACCESS_TOKEN')
            ->assertSuccessful();
    }
}
