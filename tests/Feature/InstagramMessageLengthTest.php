<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendInstagramMessageJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Meta\InstagramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El tope de 1000 caracteres que Instagram impone a los DM.
 *
 * El caso real: una plantilla de más de 1000 caracteres se guardaba sin
 * problema (el editor permite 4000, que es el tope cómodo de WhatsApp) y el
 * fallo aparecía recién en Meta, con el error 100 / subcódigo 2534038
 * **traducido al chino** — el agente veía un muro de caracteres ilegibles y el
 * cliente no recibía nada.
 *
 * Lo que se prueba:
 *
 *  - Que el mensaje largo NI SIQUIERA se envíe: gastar la llamada para que Meta
 *    la rechace no aporta nada, y en el DM por comentario además quema el único
 *    intento permitido.
 *  - Que el error que vuelve esté en español y diga cuánto sobra.
 *  - Que los emojis y las tildes cuenten como UN carácter. Con strlen() un
 *    mensaje de 600 caracteres lleno de emojis se rechazaría creyendo que pasa
 *    de 1000, porque cada emoji ocupa 4 bytes.
 *  - Que un error de Meta con ese subcódigo se traduzca aunque llegue en chino.
 */
class InstagramMessageLengthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.meta.instagram_account_id', '17841407844220949');
        config()->set('services.meta.instagram_access_token', 'token-de-prueba');
    }

    private function servicio(): InstagramService
    {
        return app(InstagramService::class);
    }

    public function test_no_envia_un_dm_de_mas_de_mil_caracteres(): void
    {
        Http::fake();

        $resultado = $this->servicio()->sendMessage(
            '1234567890',
            str_repeat('a', InstagramService::MAX_CARACTERES_DM + 1),
        );

        $this->assertFalse($resultado['success']);

        // Lo importante no es solo que falle, sino que NO se haya llamado a
        // Meta: el punto del fix es no gastar el intento.
        Http::assertNothingSent();
    }

    public function test_el_error_explica_el_limite_en_espanol(): void
    {
        Http::fake();

        $largo = InstagramService::MAX_CARACTERES_DM + 250;

        $resultado = $this->servicio()->sendMessage('1234567890', str_repeat('a', $largo));

        $mensaje = $resultado['error']['message'];

        // El agente tiene que poder leerlo y saber cuánto acortar.
        $this->assertStringContainsString((string) $largo, $mensaje);
        $this->assertStringContainsString('1000', $mensaje);
        $this->assertStringContainsString('Instagram', $mensaje);
    }

    public function test_envia_un_mensaje_que_cabe_justo_en_el_limite(): void
    {
        Http::fake([
            '*' => Http::response(['message_id' => 'mid.123'], 200),
        ]);

        $resultado = $this->servicio()->sendMessage(
            '1234567890',
            str_repeat('a', InstagramService::MAX_CARACTERES_DM),
        );

        // El límite es inclusivo: exactamente 1000 es válido para Meta.
        $this->assertTrue($resultado['success']);
        Http::assertSentCount(1);
    }

    public function test_los_emojis_cuentan_como_un_caracter_y_no_como_cuatro(): void
    {
        Http::fake([
            '*' => Http::response(['message_id' => 'mid.123'], 200),
        ]);

        // 600 emojis: 600 caracteres para Meta, pero 2400 bytes para strlen().
        // Con strlen() este envío se rechazaría por error.
        $resultado = $this->servicio()->sendMessage('1234567890', str_repeat('🌿', 600));

        $this->assertTrue($resultado['success']);
        Http::assertSentCount(1);
    }

    public function test_el_dm_por_comentario_tambien_respeta_el_limite(): void
    {
        Http::fake();

        $resultado = $this->servicio()->sendCommentReply(
            '17851234567890',
            str_repeat('a', InstagramService::MAX_CARACTERES_DM + 1),
        );

        $this->assertFalse($resultado['success']);

        // Acá pesa más que en sendMessage(): Meta permite UN DM por comentario,
        // así que un rechazo por largo dejaría a esa persona sin nada para
        // siempre.
        Http::assertNothingSent();
    }

    public function test_traduce_el_error_de_meta_aunque_llegue_en_chino(): void
    {
        // La respuesta real que reportó el usuario, con el mensaje en chino.
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message'       => '所发消息的长度超过 1000 个字符',
                    'type'          => 'IGApiException',
                    'code'          => 100,
                    'error_subcode' => 2534038,
                    'fbtrace_id'    => 'Aw13w7XCb8ZKKs6ZDv4Mh5Z',
                ],
            ], 400),
        ]);

        // Un texto corto: así el rechazo viene de Meta y no de la validación
        // previa, que es justo el camino que se quiere probar.
        $resultado = $this->servicio()->sendMessage('1234567890', 'Hola');

        $this->assertFalse($resultado['success']);
        $this->assertStringContainsString('Instagram', $resultado['error']['message']);

        // El error original se conserva: sin él, diagnosticar con el soporte de
        // Meta es imposible porque se pierde el fbtrace_id.
        $this->assertSame(2534038, $resultado['error']['meta_error']['error_subcode']);
    }

    /**
     * Crea un mensaje saliente de Instagram listo para que el Job lo procese.
     */
    private function mensajeSaliente(string $cuerpo): Message
    {
        $contacto = Contact::create([
            'channel'       => Contact::CHANNEL_INSTAGRAM,
            'channel_id'    => '2653308841814122',
            'display_name'  => 'Cliente Prueba',
            'first_seen_at' => now(),
            'last_seen_at'  => now(),
        ]);

        $conversacion = $contacto->conversations()->create([
            'status'            => Conversation::STATUS_OPEN,
            'within_24h_window' => true,
            'last_message_at'   => now(),
        ]);

        return Message::create([
            'conversation_id' => $conversacion->id,
            'direction'       => Message::DIRECTION_OUTBOUND,
            'channel'         => 'instagram',
            'type'            => Message::TYPE_TEXT,
            'body'            => $cuerpo,
            'status'          => Message::STATUS_PENDING,
        ]);
    }

    public function test_el_job_no_reintenta_un_mensaje_demasiado_largo(): void
    {
        Http::fake();

        $mensaje = $this->mensajeSaliente(
            str_repeat('a', InstagramService::MAX_CARACTERES_DM + 500)
        );

        // Sin excepción: el Job marca el mensaje como fallido y vuelve. Si
        // lanzara, la cola lo reintentaría 3 veces con backoff 10+30+120 —
        // 2,7 minutos de worker ocupado y tres stacktraces de 35 líneas en el
        // log, para terminar exactamente igual. Eso es lo que tenía el VPS
        // arrastrándose.
        (new SendInstagramMessageJob($mensaje->id))->handle(app(InstagramService::class));

        $mensaje->refresh();

        $this->assertSame(Message::STATUS_FAILED, $mensaje->status);
        $this->assertStringContainsString('1000', $mensaje->failed_reason);
        Http::assertNothingSent();
    }

    public function test_el_job_si_reintenta_un_error_pasajero(): void
    {
        // Un 500 de Meta: esto sí se arregla solo, y descartarlo perdería un
        // mensaje real del negocio.
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'Internal error', 'code' => 2]], 500),
        ]);

        $mensaje = $this->mensajeSaliente('Hola, ¿te cuento sobre los cursos?');

        $this->expectException(\RuntimeException::class);

        (new SendInstagramMessageJob($mensaje->id))->handle(app(InstagramService::class));
    }

    public function test_un_error_distinto_de_meta_se_devuelve_tal_cual(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message'       => 'Invalid OAuth access token',
                    'type'          => 'OAuthException',
                    'code'          => 190,
                    'error_subcode' => 463,
                ],
            ], 400),
        ]);

        $resultado = $this->servicio()->sendMessage('1234567890', 'Hola');

        // Inventar un texto amable para un fallo que no entendemos escondería
        // la causa real: un token vencido debe seguir diciendo que es un token
        // vencido.
        $this->assertSame('Invalid OAuth access token', $resultado['error']['message']);
        $this->assertArrayNotHasKey('meta_error', $resultado['error']);
    }
}
