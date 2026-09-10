<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\InstagramCommentReply;
use App\Models\InstagramCommentSetting;
use App\Models\Message;
use App\Models\Template;
use App\Models\User;
use App\Services\Meta\InstagramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DM de bienvenida a quien comenta un post de Instagram.
 *
 * Lo que se prueba acá es lo que de verdad puede romperse:
 *
 *  - Que el webhook de comentarios llegue al servicio. Los comentarios entran
 *    por `entry.changes`, no por `entry.messaging`, y ese camino no existía:
 *    es el mismo agujero que tuvieron los postbacks antes de handlePostback().
 *  - Que NO se envíe dos veces. Meta reintenta el webhook y solo permite un DM
 *    por comentario.
 *  - Que el DM vaya por `recipient.comment_id` y no por el IGSID: enviar al id
 *    fallaría para alguien que nunca escribió al negocio.
 *  - Cada motivo de omisión, porque cada uno es un caso real que ya se vio o se
 *    va a ver (la cuenta propia comentando, los hilos, las palabras clave).
 */
class InstagramCommentDmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El servicio los lee para armar la URL y el token. Sin esto la llamada
        // se armaría con un id vacío y el fake no la reconocería.
        config()->set('services.meta.instagram_account_id', '17841407844220949');
        config()->set('services.meta.instagram_access_token', 'token-de-prueba');
    }

    /**
     * Un cambio `comments` tal como lo manda Meta.
     *
     * @param  array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function webhook(array $value = []): array
    {
        return [
            'object' => 'instagram',
            'entry'  => [[
                'id'      => '17841407844220949',
                'time'    => 1757500000,
                'changes' => [[
                    'field' => 'comments',
                    'value' => array_merge([
                        'id'    => 'comment-1',
                        'text'  => 'Me interesa el precio',
                        'from'  => ['id' => '9988776655', 'username' => 'clienta.nueva'],
                        'media' => ['id' => 'media-1'],
                    ], $value),
                ]],
            ]],
        ];
    }

    private function activarConTexto(string $texto = '¡Gracias por comentar!'): InstagramCommentSetting
    {
        $config = InstagramCommentSetting::actual();

        $config->update([
            'is_active'     => true,
            'response_type' => InstagramCommentSetting::RESPONSE_TEXT,
            'response_text' => $texto,
        ]);

        return $config;
    }

    /** Meta acepta el envío. */
    private function metaResponde(): void
    {
        Http::fake([
            'graph.instagram.com/*' => Http::response([
                'recipient_id' => '9988776655',
                'message_id'   => 'mid.enviado',
            ], 200),
        ]);
    }

    private function procesar(array $payload): void
    {
        app(InstagramService::class)->processWebhookPayload($payload);
    }

    public function test_envia_el_dm_y_lo_refleja_en_el_crm(): void
    {
        $this->activarConTexto('¡Hola! Gracias por comentar 🌿');
        $this->metaResponde();

        $this->procesar($this->webhook());

        // El DM tiene que ir por comment_id: es lo que abre la ventana. Con
        // recipient.id Meta responde "no messaging window" para alguien que
        // nunca escribió al negocio.
        Http::assertSent(function ($request): bool {
            $cuerpo = $request->data();

            return str_contains($request->url(), 'graph.instagram.com')
                && ($cuerpo['recipient']['comment_id'] ?? null) === 'comment-1'
                && ! isset($cuerpo['recipient']['id'])
                && ($cuerpo['message']['text'] ?? null) === '¡Hola! Gracias por comentar 🌿';
        });

        $registro = InstagramCommentReply::query()->where('comment_id', 'comment-1')->first();

        $this->assertNotNull($registro);
        $this->assertSame(InstagramCommentReply::STATUS_SENT, $registro->status);
        $this->assertSame('clienta.nueva', $registro->commenter_username);

        // Se refleja en la bandeja: si no, el agente no ve el hilo y responde
        // como si el cliente hubiera escrito primero.
        $contacto = Contact::query()->where('channel_id', '9988776655')->first();
        $this->assertNotNull($contacto);
        $this->assertSame('instagram', $contacto->channel);

        $mensaje = Message::query()->where('external_id', 'mid.enviado')->first();
        $this->assertNotNull($mensaje);
        $this->assertSame(Message::DIRECTION_OUTBOUND, $mensaje->direction);
        $this->assertSame('¡Hola! Gracias por comentar 🌿', $mensaje->body);
        $this->assertSame('instagram_comment', $mensaje->meta_payload['trigger'] ?? null);
        $this->assertSame($mensaje->id, $registro->fresh()->message_id);
    }

    public function test_no_envia_dos_veces_el_mismo_comentario(): void
    {
        $this->activarConTexto();
        $this->metaResponde();

        // Meta reintenta el webhook si no recibe un 200 a tiempo. El segundo
        // envío lo rechazaría la API, pero el cliente ya habría recibido dos.
        $this->procesar($this->webhook());
        $this->procesar($this->webhook());

        Http::assertSentCount(1);
        $this->assertSame(1, InstagramCommentReply::query()->count());
    }

    public function test_ignora_los_comentarios_de_la_propia_cuenta(): void
    {
        $this->activarConTexto();
        Http::fake();

        // Sin este corte, cada vez que un agente responde un comentario el CRM
        // le manda un DM de bienvenida a la propia empresa.
        $this->procesar($this->webhook([
            'from' => ['id' => '17841407844220949', 'username' => 'reinoaromas'],
        ]));

        Http::assertNothingSent();

        $registro = InstagramCommentReply::query()->where('comment_id', 'comment-1')->first();
        $this->assertSame(InstagramCommentReply::STATUS_SKIPPED, $registro->status);
        $this->assertStringContainsString('propia cuenta', (string) $registro->skip_reason);
    }

    public function test_ignora_las_respuestas_dentro_de_un_hilo(): void
    {
        $this->activarConTexto();
        Http::fake();

        $this->procesar($this->webhook(['parent_id' => 'comment-padre']));

        Http::assertNothingSent();
        $this->assertSame(
            InstagramCommentReply::STATUS_SKIPPED,
            InstagramCommentReply::query()->first()->status,
        );
    }

    public function test_no_envia_nada_si_esta_desactivado(): void
    {
        // Por defecto viene apagado: nadie quiere que empiece a mandar DM a
        // 25k seguidores en el deploy sin haber revisado el texto.
        Http::fake();

        $this->procesar($this->webhook());

        Http::assertNothingSent();
        $this->assertSame(
            InstagramCommentReply::STATUS_SKIPPED,
            InstagramCommentReply::query()->first()->status,
        );
    }

    public function test_respeta_las_palabras_clave(): void
    {
        $config = $this->activarConTexto();
        $config->update(['keywords' => ['precio', 'info']]);
        $this->metaResponde();

        // Sin coincidencia: no se responde.
        $this->procesar($this->webhook([
            'id'   => 'comment-sin-clave',
            'text' => 'Qué lindo todo',
        ]));

        Http::assertNothingSent();

        // Con coincidencia, y con acento y mayúsculas de por medio: la gente
        // comenta "PRECIO", "precio" y "precío" indistintamente, y un filtro
        // que falla por un acento se lee como que la automatización no sirve.
        $this->procesar($this->webhook([
            'id'   => 'comment-con-clave',
            'text' => '¿Cuál es el PRECÍO del curso?',
        ]));

        Http::assertSentCount(1);

        $this->assertSame(
            InstagramCommentReply::STATUS_SENT,
            InstagramCommentReply::query()->where('comment_id', 'comment-con-clave')->first()->status,
        );
    }

    public function test_respeta_el_tope_diario(): void
    {
        $config = $this->activarConTexto();
        $config->update(['daily_limit' => 1]);
        $this->metaResponde();

        $this->procesar($this->webhook(['id' => 'comment-a']));
        $this->procesar($this->webhook(['id' => 'comment-b']));

        Http::assertSentCount(1);

        $segundo = InstagramCommentReply::query()->where('comment_id', 'comment-b')->first();
        $this->assertSame(InstagramCommentReply::STATUS_SKIPPED, $segundo->status);
        $this->assertStringContainsString('tope diario', (string) $segundo->skip_reason);
    }

    public function test_registra_el_fallo_cuando_meta_rechaza(): void
    {
        $this->activarConTexto();

        Http::fake([
            'graph.instagram.com/*' => Http::response([
                'error' => ['message' => 'This comment has already been replied to.'],
            ], 400),
        ]);

        $this->procesar($this->webhook());

        $registro = InstagramCommentReply::query()->first();
        $this->assertSame(InstagramCommentReply::STATUS_FAILED, $registro->status);
        $this->assertStringContainsString('already been replied', (string) $registro->skip_reason);

        // Un envío fallido NO debe dejar un chat fantasma: el orden es enviar
        // primero y tocar el CRM después, justamente por esto.
        $this->assertSame(0, Message::query()->count());
        $this->assertSame(0, Contact::query()->count());
    }

    public function test_una_plantilla_desactivada_no_envia_nada(): void
    {
        $plantilla = Template::create([
            'name'      => 'Bienvenida IG',
            'body'      => 'Hola, gracias por comentar',
            'channel'   => 'instagram',
            'is_active' => false,
        ]);

        InstagramCommentSetting::actual()->update([
            'is_active'     => true,
            'response_type' => InstagramCommentSetting::RESPONSE_TEMPLATE,
            'template_id'   => $plantilla->id,
        ]);

        Http::fake();

        $this->procesar($this->webhook());

        Http::assertNothingSent();
        $this->assertStringContainsString(
            'No hay texto configurado',
            (string) InstagramCommentReply::query()->first()->skip_reason,
        );
    }

    public function test_no_se_puede_activar_sin_mensaje(): void
    {
        $usuario = User::factory()->create(['role' => 'administrador', 'is_active' => true]);

        InstagramCommentSetting::actual()->update([
            'response_type' => InstagramCommentSetting::RESPONSE_TEXT,
            'response_text' => null,
        ]);

        // Activar sin nada que enviar dejaría la automatización encendida y
        // muda, y el negocio creería que Meta no manda los webhooks.
        $this->actingAs($usuario)
            ->patchJson('/api/instagram/comment-settings', ['is_active' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['response_text']);
    }

    public function test_el_endpoint_guarda_y_devuelve_la_configuracion(): void
    {
        $usuario = User::factory()->create(['role' => 'administrador', 'is_active' => true]);

        $this->actingAs($usuario)
            ->patchJson('/api/instagram/comment-settings', [
                'is_active'     => true,
                'response_type' => 'text',
                'response_text' => 'Bienvenida nueva',
                'keywords'      => ['precio'],
                'daily_limit'   => 50,
            ])
            ->assertOk()
            ->assertJsonPath('settings.is_active', true)
            ->assertJsonPath('settings.response_text', 'Bienvenida nueva')
            ->assertJsonPath('settings.keywords.0', 'precio')
            ->assertJsonPath('settings.daily_limit', 50);

        $this->assertTrue(InstagramCommentSetting::actual()->is_active);
    }

    public function test_los_mensajes_normales_siguen_funcionando(): void
    {
        // El cambio tocó processWebhookPayload(), que es el camino de TODOS los
        // eventos de Instagram: si `changes` rompiera `messaging`, dejarían de
        // llegar los mensajes reales de los clientes.
        Http::fake([
            'graph.instagram.com/*' => Http::response(['username' => 'clienta'], 200),
        ]);

        $this->procesar([
            'object' => 'instagram',
            'entry'  => [[
                'id'        => '17841407844220949',
                'messaging' => [[
                    'sender'    => ['id' => '5544332211'],
                    'recipient' => ['id' => '17841407844220949'],
                    'timestamp' => 1757500000,
                    'message'   => ['mid' => 'mid.entrante', 'text' => 'Hola, quiero info'],
                ]],
            ]],
        ]);

        $mensaje = Message::query()->where('external_id', 'mid.entrante')->first();

        $this->assertNotNull($mensaje);
        $this->assertSame(Message::DIRECTION_INBOUND, $mensaje->direction);
        $this->assertSame('Hola, quiero info', $mensaje->body);

        // Y no se creó ningún registro de comentario por un mensaje normal.
        $this->assertSame(0, InstagramCommentReply::query()->count());
    }
}
