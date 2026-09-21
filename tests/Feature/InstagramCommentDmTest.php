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
 * Respuesta automática a quien comenta un post de Instagram: el comentario
 * público debajo del suyo y el DM privado.
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

    /**
     * Meta acepta los dos envíos.
     *
     * Son endpoints distintos y hay que distinguirlos: el DM va a
     * `{ig-id}/messages` y el comentario público a `{comment-id}/replies`. Un
     * fake único los confundiría y el test pasaría sin probar nada.
     */
    private function metaResponde(): void
    {
        Http::fake([
            'graph.instagram.com/*/replies' => Http::response(['id' => 'reply-publicada'], 200),
            'graph.instagram.com/*' => Http::response([
                'recipient_id' => '9988776655',
                'message_id'   => 'mid.enviado',
            ], 200),
        ]);
    }

    /** Activa también el aviso público. */
    private function activarAvisoPublico(string $texto = 'Te escribimos al privado 💌'): void
    {
        InstagramCommentSetting::actual()->update([
            'public_reply_active' => true,
            'public_reply_text'   => $texto,
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

    /**
     * Activar un menu completo tiene que dejarse guardar.
     *
     * Regresion: la simulacion que valida "hay algo que enviar" copiaba
     * response_text y template_id pero NO quick_reply_menu_id, asi que
     * respuesta() no encontraba el menu y devolvia null. Resultado: activar
     * CUALQUIER menu se rechazaba con "No se puede activar sin un mensaje
     * valido", por completo que estuviera. Se vio en produccion.
     */
    public function test_se_puede_activar_un_menu_de_opciones_completo(): void
    {
        $usuario = User::factory()->create(['role' => 'administrador', 'is_active' => true]);

        $plantilla = Template::create([
            'name'      => 'Curso Valencia',
            'body'      => 'Info del curso de Valencia',
            'channel'   => 'instagram',
            'is_active' => true,
        ]);

        $menu = \App\Models\InstagramQuickReplyMenu::create([
            'name'      => 'INFO CURSOS',
            'body'      => 'Toca una opcion',
            'is_active' => true,
        ]);

        $menu->opciones()->create([
            'kind'          => \App\Models\InstagramAutomation::KIND_QUICK_REPLY,
            'title'         => 'Curso Valencia',
            'payload'       => 'QR_CURSO_VALENCIA_TEST01',
            'response_type' => \App\Models\InstagramAutomation::RESPONSE_TEMPLATE,
            'template_id'   => $plantilla->id,
            'position'      => 0,
            'is_active'     => true,
        ]);

        $this->actingAs($usuario)
            ->patchJson('/api/instagram/comment-settings', [
                'is_active'           => true,
                'response_type'       => 'menu',
                'quick_reply_menu_id' => $menu->id,
                'template_id'         => null,
                'response_text'       => null,
            ])
            ->assertOk()
            ->assertJsonPath('settings.response_type', 'menu')
            ->assertJsonPath('settings.quick_reply_menu_id', $menu->id);

        $config = InstagramCommentSetting::actual();

        $this->assertTrue($config->is_active);
        // Lo que de verdad importa: que el DM salga con burbujas.
        $this->assertCount(1, $config->opcionesDeMenu());
    }

    public function test_publica_el_aviso_publico_y_manda_el_dm(): void
    {
        $this->activarConTexto('Mensaje privado de prueba');
        $this->activarAvisoPublico('¡Gracias! Te escribimos al privado 💌');
        $this->metaResponde();

        $this->procesar($this->webhook());

        // El aviso público va al nodo del COMENTARIO, con el texto en `message`.
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/comment-1/replies')
                && ($request->data()['message'] ?? null) === '¡Gracias! Te escribimos al privado 💌';
        });

        // Y el DM sigue yendo por su propio endpoint.
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/messages')
                && ($request->data()['recipient']['comment_id'] ?? null) === 'comment-1';
        });

        $registro = InstagramCommentReply::query()->first();
        $this->assertSame('reply-publicada', $registro->public_reply_id);
        $this->assertNull($registro->public_reply_error);
        $this->assertSame(InstagramCommentReply::STATUS_SENT, $registro->status);
    }

    public function test_el_aviso_publico_funciona_sin_el_dm(): void
    {
        // Solo el aviso: el privado queda apagado. Son independientes.
        $this->activarAvisoPublico();
        $this->metaResponde();

        $this->procesar($this->webhook());

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/replies'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/messages'));

        $registro = InstagramCommentReply::query()->first();
        $this->assertSame('reply-publicada', $registro->public_reply_id);
        $this->assertSame(InstagramCommentReply::STATUS_SKIPPED, $registro->status);
        $this->assertStringContainsString('privado está desactivado', (string) $registro->skip_reason);
    }

    public function test_el_aviso_publico_sale_aunque_se_alcance_el_tope_del_dm(): void
    {
        // La persona comentó: merece una señal aunque el privado no salga por
        // un límite NUESTRO. Es la razón de que el aviso vaya primero.
        $config = $this->activarConTexto();
        $config->update(['daily_limit' => 1]);
        $this->activarAvisoPublico();
        $this->metaResponde();

        $this->procesar($this->webhook(['id' => 'comment-a']));
        $this->procesar($this->webhook(['id' => 'comment-b']));

        $segundo = InstagramCommentReply::query()->where('comment_id', 'comment-b')->first();

        $this->assertSame('reply-publicada', $segundo->public_reply_id);
        $this->assertSame(InstagramCommentReply::STATUS_SKIPPED, $segundo->status);
        $this->assertStringContainsString('tope diario', (string) $segundo->skip_reason);
    }

    public function test_un_fallo_del_aviso_publico_no_frena_el_dm(): void
    {
        $this->activarConTexto();
        $this->activarAvisoPublico();

        Http::fake([
            'graph.instagram.com/*/replies' => Http::response([
                'error' => ['message' => 'Cannot reply to a hidden comment.'],
            ], 400),
            'graph.instagram.com/*' => Http::response(['message_id' => 'mid.enviado'], 200),
        ]);

        $this->procesar($this->webhook());

        $registro = InstagramCommentReply::query()->first();

        // El aviso guarda su error en su propia columna...
        $this->assertNull($registro->public_reply_id);
        $this->assertStringContainsString('hidden comment', (string) $registro->public_reply_error);

        // ...y el DM salió igual: son independientes.
        $this->assertSame(InstagramCommentReply::STATUS_SENT, $registro->status);
        $this->assertSame(1, Message::query()->count());
    }

    public function test_no_comenta_dos_veces_el_mismo_post(): void
    {
        $this->activarConTexto();
        $this->activarAvisoPublico();
        $this->metaResponde();

        // Meta reintenta el webhook: el aviso duplicado quedaría visible debajo
        // del post, que es el error más vergonzoso de todos.
        $this->procesar($this->webhook());
        $this->procesar($this->webhook());

        Http::assertSentCount(2); // un /replies y un /messages, una sola vez cada uno
        $this->assertSame(1, InstagramCommentReply::query()->count());
    }

    public function test_no_responde_en_publico_a_su_propia_cuenta(): void
    {
        $this->activarAvisoPublico();
        Http::fake();

        // Sin este corte la cuenta se respondería a sí misma debajo del post,
        // en un bucle visible para todo el mundo.
        $this->procesar($this->webhook([
            'from' => ['id' => '17841407844220949', 'username' => 'reinoaromas'],
        ]));

        Http::assertNothingSent();
    }

    public function test_no_se_puede_activar_el_aviso_sin_texto(): void
    {
        $usuario = User::factory()->create(['role' => 'administrador', 'is_active' => true]);

        $this->actingAs($usuario)
            ->patchJson('/api/instagram/comment-settings', ['public_reply_active' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['public_reply_text']);
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
