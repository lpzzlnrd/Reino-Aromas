<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\InstagramAutomation;
use App\Models\InstagramDmReply;
use App\Models\InstagramDmSetting;
use App\Models\InstagramQuickReplyMenu;
use App\Models\Message;
use App\Models\Template;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Meta\InstagramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Respuesta automática a quien escribe un DM a la cuenta de Instagram.
 *
 * Lo que se prueba acá es lo que de verdad puede romperse, que casi todo son
 * los CORTES —las razones para callarse—:
 *
 *  - Que responda al primer mensaje, que es para lo que existe.
 *  - Que NO responda al segundo, que es lo que evita que el robot pise una
 *    conversación en curso.
 *  - Que NO responda si un agente ya tomó el caso: la peor cara posible de
 *    esto es el menú cayendo en medio de un diálogo humano.
 *  - El cooldown, que evita cinco menús a quien manda "hola", "hola?",
 *    "buenas".
 *  - Que un fallo de la automatización no tumbe el webhook: si lo hiciera,
 *    Meta reintentaría y el mensaje del cliente se duplicaría en la bandeja.
 */
class InstagramDmAutoReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.meta.instagram_account_id', '17841407844220949');
        config()->set('services.meta.instagram_access_token', 'token-de-prueba');

        // Los fakes NO se registran acá: Http::fake() acumula stubs y gana el
        // primero que matchea, así que un stub puesto en el setUp le gana a
        // cualquiera que un test registre después. Cada test llama a
        // metaResponde() —o registra el suyo— y así puede hacer fallar el
        // envío cuando eso es justo lo que quiere probar.
    }

    /**
     * Meta acepta el envío y devuelve el perfil del remitente.
     *
     * Son dos endpoints distintos bajo el mismo host: el envío va a
     * `{ig-id}/messages` y el perfil al nodo del IGSID. Se distinguen para que
     * un test pueda hacer fallar SOLO el envío.
     *
     * Ojo con el orden: Http::fake() acumula stubs entre llamadas y gana el
     * primero que matchea, así que el patrón específico va antes del comodín
     * —y un test que quiera otro comportamiento tiene que registrar el suyo
     * ANTES de llamar acá, no después.
     */
    private function metaResponde(): void
    {
        Http::fake([
            'graph.instagram.com/*/messages' => Http::response([
                'message_id'   => 'mid.respuesta',
                'recipient_id' => '9988776655',
            ], 200),
            'graph.instagram.com/*' => Http::response([
                'username' => 'clienta.nueva',
                'name'     => 'Clienta Nueva',
            ], 200),
        ]);
    }

    /**
     * Un mensaje entrante tal como lo manda Meta.
     *
     * @param  array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function webhook(array $message = [], string $senderId = '9988776655'): array
    {
        return [
            'object' => 'instagram',
            'entry'  => [[
                'id'        => '17841407844220949',
                'time'      => 1757500000,
                'messaging' => [[
                    'sender'    => ['id' => $senderId],
                    'recipient' => ['id' => '17841407844220949'],
                    'timestamp' => 1757500000,
                    'message'   => array_merge([
                        'mid'  => 'mid.' . uniqid(),
                        'text' => 'hola, informacion',
                    ], $message),
                ]],
            ]],
        ];
    }

    private function procesar(array $payload): void
    {
        app(InstagramService::class)->processWebhookPayload($payload);
    }

    private function activarConTexto(string $texto = '¡Hola! Gracias por escribirnos 🌿'): InstagramDmSetting
    {
        $config = InstagramDmSetting::actual();

        $config->update([
            'is_active'     => true,
            'response_type' => InstagramDmSetting::RESPONSE_TEXT,
            'response_text' => $texto,
        ]);

        return $config;
    }

    /** Los mensajes salientes de la conversación del contacto. */
    private function salientes(): \Illuminate\Database\Eloquent\Collection
    {
        return Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->get();
    }

    // -----------------------------------------------------------------
    // Lo que tiene que pasar
    // -----------------------------------------------------------------

    public function test_responde_al_primer_mensaje(): void
    {
        $this->metaResponde();

        $this->activarConTexto('¡Hola! Gracias por escribirnos 🌿');

        $this->procesar($this->webhook());

        $salientes = $this->salientes();

        $this->assertCount(1, $salientes);
        $this->assertSame('¡Hola! Gracias por escribirnos 🌿', $salientes->first()->body);

        $registro = InstagramDmReply::query()->first();

        $this->assertNotNull($registro);
        $this->assertSame(InstagramDmReply::STATUS_SENT, $registro->status);
        $this->assertSame('9988776655', $registro->sender_igsid);
    }

    public function test_apagada_no_responde_nada(): void
    {
        $this->metaResponde();

        // is_active queda en false, que es el default.
        InstagramDmSetting::actual();

        $this->procesar($this->webhook());

        $this->assertCount(0, $this->salientes());
        $this->assertSame(0, InstagramDmReply::query()->count());
    }

    public function test_envia_el_menu_con_sus_burbujas(): void
    {
        $this->metaResponde();

        $menu = InstagramQuickReplyMenu::create([
            'name'      => 'INFO CURSOS',
            'body'      => '¿Qué te gustaría saber?',
            'is_active' => true,
        ]);

        $plantilla = Template::create([
            'name'      => 'Precio Valencia',
            'body'      => 'El curso de Valencia cuesta...',
            'is_active' => true,
        ]);

        $menu->opciones()->create([
            'kind'          => InstagramAutomation::KIND_QUICK_REPLY,
            'title'         => 'Curso Valencia',
            'payload'       => 'QR_CURSO_VALENCIA',
            'response_type' => 'template',
            'template_id'   => $plantilla->id,
            'position'      => 1,
            'is_active'     => true,
        ]);

        InstagramDmSetting::actual()->update([
            'is_active'           => true,
            'response_type'       => InstagramDmSetting::RESPONSE_MENU,
            'quick_reply_menu_id' => $menu->id,
        ]);

        $this->procesar($this->webhook());

        // Las burbujas viajan en el mismo mensaje. Y va por recipient.id (y
        // no por comment_id): acá la ventana la abre el propio DM entrante.
        Http::assertSent(function ($request): bool {
            $cuerpo = $request->data();

            return isset($cuerpo['message']['quick_replies'])
                && ($cuerpo['recipient']['id'] ?? null) === '9988776655'
                && ! isset($cuerpo['recipient']['comment_id'])
                && ($cuerpo['message']['quick_replies'][0]['title'] ?? null) === 'Curso Valencia';
        });

        $this->assertSame(1, $menu->fresh()->sends);
    }

    // -----------------------------------------------------------------
    // Los cortes: cuándo NO tiene que responder
    // -----------------------------------------------------------------

    public function test_no_responde_al_segundo_mensaje_de_la_misma_conversacion(): void
    {
        $this->metaResponde();

        $this->activarConTexto();

        $this->procesar($this->webhook(['mid' => 'mid.primero']));
        $this->procesar($this->webhook(['mid' => 'mid.segundo']));

        // Una sola respuesta, la del primer mensaje. El segundo entra a la
        // bandeja sin que el robot lo toque.
        $this->assertCount(1, $this->salientes());
    }

    public function test_en_modo_always_responde_tambien_al_segundo(): void
    {
        $this->metaResponde();

        $this->activarConTexto();

        InstagramDmSetting::actual()->update([
            'trigger_mode'     => InstagramDmSetting::TRIGGER_ALWAYS,
            // Sin cooldown, o el segundo se cortaría por ahí y el test no
            // probaría lo que dice probar.
            'cooldown_minutes' => 0,
        ]);

        $this->procesar($this->webhook(['mid' => 'mid.primero']));
        $this->procesar($this->webhook(['mid' => 'mid.segundo']));

        $this->assertCount(2, $this->salientes());
    }

    public function test_no_responde_si_un_agente_ya_tomo_el_caso(): void
    {
        $this->metaResponde();

        $this->activarConTexto();

        $agente = User::factory()->create();

        // Modo 'always' y sin cooldown a propósito: así el ÚNICO corte que
        // puede actuar sobre el segundo mensaje es el del agente asignado, y
        // el test prueba lo que dice probar. Con 'first' pasaría igual, pero
        // por el corte equivocado.
        InstagramDmSetting::actual()->update([
            'trigger_mode'     => InstagramDmSetting::TRIGGER_ALWAYS,
            'cooldown_minutes' => 0,
        ]);

        // Primer mensaje: crea contacto, conversación y ticket, y responde.
        $this->procesar($this->webhook(['mid' => 'mid.primero']));

        $this->assertCount(1, $this->salientes());

        Ticket::query()->firstOrFail()->update(['assigned_user_id' => $agente->id]);

        $this->procesar($this->webhook(['mid' => 'mid.segundo']));

        // Sigue habiendo una sola: el robot se apartó al ver al agente.
        $this->assertCount(1, $this->salientes());

        $registro = InstagramDmReply::query()
            ->where('status', InstagramDmReply::STATUS_SKIPPED)
            ->latest()
            ->first();

        $this->assertNotNull($registro);
        $this->assertStringContainsString('agente', $registro->skip_reason);
    }

    public function test_respeta_el_cooldown(): void
    {
        $this->metaResponde();

        $this->activarConTexto();

        InstagramDmSetting::actual()->update([
            'trigger_mode'     => InstagramDmSetting::TRIGGER_ALWAYS,
            'cooldown_minutes' => 60,
        ]);

        $this->procesar($this->webhook(['mid' => 'mid.primero']));
        $this->procesar($this->webhook(['mid' => 'mid.segundo']));

        // El segundo cae dentro de la hora de silencio.
        $this->assertCount(1, $this->salientes());
    }

    public function test_responde_de_nuevo_pasado_el_cooldown(): void
    {
        $this->metaResponde();

        $this->activarConTexto();

        InstagramDmSetting::actual()->update([
            'trigger_mode'     => InstagramDmSetting::TRIGGER_ALWAYS,
            'cooldown_minutes' => 60,
        ]);

        $this->procesar($this->webhook(['mid' => 'mid.primero']));

        $this->travel(61)->minutes();

        $this->procesar($this->webhook(['mid' => 'mid.segundo']));

        $this->assertCount(2, $this->salientes());
    }

    public function test_filtra_por_palabras_clave(): void
    {
        $this->metaResponde();

        $this->activarConTexto();

        InstagramDmSetting::actual()->update(['keywords' => ['precio', 'curso']]);

        $this->procesar($this->webhook(['text' => 'hola buenas tardes']));

        $this->assertCount(0, $this->salientes());
    }

    public function test_las_palabras_clave_ignoran_acentos_y_mayusculas(): void
    {
        $this->metaResponde();

        $this->activarConTexto();

        InstagramDmSetting::actual()->update(['keywords' => ['precio']]);

        $this->procesar($this->webhook(['text' => '¿Cuál es el PRECÍO?']));

        $this->assertCount(1, $this->salientes());
    }

    public function test_no_responde_a_un_mensaje_sin_texto(): void
    {
        $this->metaResponde();

        $this->activarConTexto();

        // Una foto: no se puede filtrar por palabras clave ni se responde bien
        // con un menú de cursos. Queda para un agente.
        $this->procesar($this->webhook([
            'text'        => null,
            'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://x/y.jpg']]],
        ]));

        $this->assertCount(0, $this->salientes());
    }

    public function test_respeta_el_tope_diario(): void
    {
        $this->metaResponde();

        $this->activarConTexto();

        InstagramDmSetting::actual()->update([
            'trigger_mode'     => InstagramDmSetting::TRIGGER_ALWAYS,
            'cooldown_minutes' => 0,
            'daily_limit'      => 1,
        ]);

        $this->procesar($this->webhook(['mid' => 'mid.primero']));
        $this->procesar($this->webhook(['mid' => 'mid.segundo']));

        $this->assertCount(1, $this->salientes());

        $registro = InstagramDmReply::query()
            ->where('status', InstagramDmReply::STATUS_SKIPPED)
            ->latest()
            ->first();

        $this->assertNotNull($registro);
        $this->assertStringContainsString('tope', $registro->skip_reason);
    }

    public function test_no_responde_si_la_plantilla_fue_desactivada(): void
    {
        $this->metaResponde();

        $plantilla = Template::create([
            'name'      => 'Bienvenida',
            'body'      => 'Hola!',
            'is_active' => false,
        ]);

        InstagramDmSetting::actual()->update([
            'is_active'     => true,
            'response_type' => InstagramDmSetting::RESPONSE_TEMPLATE,
            'template_id'   => $plantilla->id,
        ]);

        $this->procesar($this->webhook());

        $this->assertCount(0, $this->salientes());

        $registro = InstagramDmReply::query()->first();

        $this->assertNotNull($registro);
        $this->assertSame(InstagramDmReply::STATUS_SKIPPED, $registro->status);
    }

    /**
     * El corte más importante: la automatización puede fallar, el webhook no.
     *
     * Si una excepción subiera, el job fallaría, Meta reintentaría y el
     * mensaje del cliente se guardaría dos veces en la bandeja.
     */
    public function test_un_fallo_de_la_automatizacion_no_tumba_el_webhook(): void
    {
        $menu = InstagramQuickReplyMenu::create([
            'name'      => 'ROTO',
            'body'      => 'Hola',
            'is_active' => true,
        ]);

        $menu->opciones()->create([
            'kind'          => InstagramAutomation::KIND_QUICK_REPLY,
            'title'         => 'Opción',
            'payload'       => 'QR_X',
            'response_type' => 'text',
            'response_text' => 'algo',
            'position'      => 1,
            'is_active'     => true,
        ]);

        InstagramDmSetting::actual()->update([
            'is_active'           => true,
            'response_type'       => InstagramDmSetting::RESPONSE_MENU,
            'quick_reply_menu_id' => $menu->id,
        ]);

        // Meta rechaza el envío del menú.
        //
        // Se usa una closure y no un patrón porque Http::fake() ACUMULA: los
        // stubs que registró metaResponde() en el setUp siguen ahí y el
        // primero que matchea gana, así que un patrón nuevo por específico
        // que fuera nunca llegaría a usarse. La closure se evalúa para cada
        // petición y decide mirando la URL.
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/messages')) {
                return Http::response(
                    ['error' => ['message' => 'Invalid recipient', 'code' => 100]],
                    400,
                );
            }

            return Http::response(['username' => 'clienta.nueva'], 200);
        });

        $this->procesar($this->webhook());

        // El mensaje del cliente entró igual: eso es lo que no se puede perder.
        $this->assertSame(
            1,
            Message::query()->where('direction', Message::DIRECTION_INBOUND)->count(),
        );

        $registro = InstagramDmReply::query()->first();

        $this->assertNotNull($registro);
        $this->assertSame(InstagramDmReply::STATUS_FAILED, $registro->status);
    }

    // -----------------------------------------------------------------
    // La API de configuración
    // -----------------------------------------------------------------

    public function test_se_puede_activar_un_menu_completo(): void
    {
        $menu = InstagramQuickReplyMenu::create([
            'name'      => 'INFO CURSOS',
            'body'      => '¿Qué te gustaría saber?',
            'is_active' => true,
        ]);

        $menu->opciones()->create([
            'kind'          => InstagramAutomation::KIND_QUICK_REPLY,
            'title'         => 'Curso Valencia',
            'payload'       => 'QR_CURSO_VALENCIA',
            'response_type' => 'text',
            'response_text' => 'Info del curso',
            'position'      => 1,
            'is_active'     => true,
        ]);

        $this->actingAs(User::factory()->create());

        // Es el 422 que costó una sesión entera en el DM por comentario: la
        // validación simulaba la config sin copiar quick_reply_menu_id, así
        // que activar cualquier menú se rechazaba por completo que estuviera.
        $this->patchJson('/api/instagram/dm-settings', [
            'is_active'           => true,
            'response_type'       => InstagramDmSetting::RESPONSE_MENU,
            'quick_reply_menu_id' => $menu->id,
        ])->assertOk();

        $this->assertTrue(InstagramDmSetting::actual()->is_active);
    }

    public function test_no_se_puede_activar_sin_nada_que_enviar(): void
    {
        $this->actingAs(User::factory()->create());

        $this->patchJson('/api/instagram/dm-settings', [
            'is_active'     => true,
            'response_type' => InstagramDmSetting::RESPONSE_TEXT,
            'response_text' => '',
        ])->assertStatus(422);
    }

    public function test_show_devuelve_configuracion_y_metricas(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/instagram/dm-settings')
            ->assertOk()
            ->assertJsonStructure([
                'settings' => ['is_active', 'response_type', 'trigger_mode', 'cooldown_minutes'],
                'stats'    => ['sent_today', 'sent_total', 'skipped_today', 'failed_today'],
                'recent',
            ]);
    }
}
