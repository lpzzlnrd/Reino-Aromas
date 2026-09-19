<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InstagramAutomation;
use App\Models\InstagramCommentSetting;
use App\Models\InstagramQuickReplyMenu;
use App\Models\Template;
use App\Models\User;
use App\Services\Meta\InstagramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Menús de opciones de Instagram (Quick Replies).
 *
 * Es lo más cercano a un WhatsApp Flow que permite Instagram: un mensaje con
 * hasta 13 burbujas; al tocar una, Meta dispara el mismo webhook de postback
 * que los Ice Breakers y handlePostback() responde. Por eso el motor de
 * respuesta NO se reimplementó — lo que se prueba acá es el envío y la
 * configuración.
 *
 * Lo que se prueba es lo que de verdad puede romperse:
 *
 *  - Que las burbujas viajen en el MISMO mensaje que el texto. Meta permite un
 *    solo DM por comentario: mandarlas aparte las perdería para siempre.
 *  - Que un menú sin opciones activas no se pueda elegir ni enviar, por lo
 *    mismo.
 *  - Que el payload de cada opción sea único, porque handlePostback() lo
 *    resuelve sin saber de qué menú viene.
 *  - Que se respete el tope de 13, que en Meta es duro: la burbuja 14 hace que
 *    se rechace el mensaje entero.
 */
class InstagramQuickReplyMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.meta.instagram_account_id', '17841407844220949');
        config()->set('services.meta.instagram_access_token', 'token-de-prueba');
    }

    private function usuario(): User
    {
        return User::create([
            'name'     => 'Admin Prueba',
            'email'    => 'admin' . uniqid() . '@reinoaromas.test',
            'password' => bcrypt('secreto123'),
        ]);
    }

    private function menuConOpciones(int $cuantas = 2, bool $activas = true): InstagramQuickReplyMenu
    {
        $menu = InstagramQuickReplyMenu::create([
            'name'      => 'Menú de bienvenida',
            'body'      => '¡Hola! ¿Qué te gustaría saber?',
            'is_active' => true,
        ]);

        for ($i = 1; $i <= $cuantas; $i++) {
            $menu->opciones()->create([
                'kind'          => InstagramAutomation::KIND_QUICK_REPLY,
                'title'         => "Opción {$i}",
                'payload'       => "QR_OPCION_{$i}",
                'response_type' => InstagramAutomation::RESPONSE_TEXT,
                'response_text' => "Respuesta {$i}",
                'position'      => $i,
                'is_active'     => $activas,
            ]);
        }

        return $menu->fresh(['opciones']);
    }

    /*
    |-------------------------------------------------------------------------
    | El envío
    |-------------------------------------------------------------------------
    */

    public function test_las_burbujas_viajan_en_el_mismo_mensaje_que_el_texto(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'mid.123'], 200)]);

        $menu = $this->menuConOpciones(3);

        $resultado = app(InstagramService::class)->sendQuickReplies(
            '1234567890',
            $menu->body,
            $menu->opcionesParaMeta(),
        );

        $this->assertTrue($resultado['success']);

        // UNA sola llamada: si el texto y las burbujas fueran dos mensajes, el
        // segundo se rechazaría en el flujo de comentarios, donde Meta permite
        // un único DM.
        Http::assertSentCount(1);

        Http::assertSent(function ($request): bool {
            $mensaje = $request->data()['message'];

            return isset($mensaje['text'], $mensaje['quick_replies'])
                && count($mensaje['quick_replies']) === 3
                && $mensaje['quick_replies'][0]['content_type'] === 'text';
        });
    }

    public function test_no_envia_un_menu_sin_opciones(): void
    {
        Http::fake();

        $resultado = app(InstagramService::class)->sendQuickReplies('1234567890', 'Hola', []);

        // Sin burbujas esto sería un texto suelto disfrazado de menú: la
        // persona no tendría nada que tocar.
        $this->assertFalse($resultado['success']);
        Http::assertNothingSent();
    }

    public function test_recorta_en_trece_opciones_en_vez_de_fallar(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'mid.123'], 200)]);

        $opciones = [];
        for ($i = 1; $i <= 20; $i++) {
            $opciones[] = ['content_type' => 'text', 'title' => "Op {$i}", 'payload' => "QR_{$i}"];
        }

        app(InstagramService::class)->sendQuickReplies('1234567890', 'Hola', $opciones);

        // Meta rechaza el mensaje ENTERO si sobra una burbuja. Recortar deja un
        // menú de 13 en vez de ninguno.
        Http::assertSent(fn ($request): bool => count($request->data()['message']['quick_replies'])
            === InstagramQuickReplyMenu::MAX_OPCIONES);
    }

    public function test_el_menu_por_comentario_se_manda_al_comment_id(): void
    {
        Http::fake(['*' => Http::response(['message_id' => 'mid.123'], 200)]);

        $menu = $this->menuConOpciones();

        app(InstagramService::class)->sendQuickReplies(
            '1234567890',
            $menu->body,
            $menu->opcionesParaMeta(),
            commentId: '17851234567890',
        );

        // Por comment_id y NO por IGSID: la ventana la abre el comentario
        // público, y enviar al id fallaría para quien nunca escribió.
        Http::assertSent(fn ($request): bool => ($request->data()['recipient']['comment_id'] ?? null)
            === '17851234567890');
    }

    public function test_respeta_el_limite_de_caracteres_del_texto(): void
    {
        Http::fake();

        $menu = $this->menuConOpciones();

        $resultado = app(InstagramService::class)->sendQuickReplies(
            '1234567890',
            str_repeat('a', InstagramService::MAX_CARACTERES_DM + 1),
            $menu->opcionesParaMeta(),
        );

        $this->assertFalse($resultado['success']);
        Http::assertNothingSent();
    }

    /*
    |-------------------------------------------------------------------------
    | El modelo
    |-------------------------------------------------------------------------
    */

    public function test_un_menu_sin_opciones_activas_no_esta_completo(): void
    {
        $menu = $this->menuConOpciones(2, activas: false);

        // Las opciones existen pero están apagadas: enviarlo dejaría al cliente
        // con un texto y ningún botón.
        $this->assertFalse($menu->estaCompleto());
        $this->assertSame([], $menu->opcionesParaMeta());
    }

    public function test_solo_las_opciones_activas_llegan_a_meta(): void
    {
        $menu = $this->menuConOpciones(3);
        $menu->opciones->first()->update(['is_active' => false]);

        $opciones = $menu->fresh(['opciones'])->opcionesParaMeta();

        $this->assertCount(2, $opciones);
        $this->assertNotContains('Opción 1', array_column($opciones, 'title'));
    }

    public function test_una_opcion_con_plantilla_desactivada_sale_como_rota(): void
    {
        $plantilla = Template::create([
            'name'      => 'Precios',
            'body'      => 'Nuestros cursos cuestan...',
            'is_active' => false,
        ]);

        $menu = $this->menuConOpciones(1);
        $menu->opciones->first()->update([
            'response_type' => InstagramAutomation::RESPONSE_TEMPLATE,
            'template_id'   => $plantilla->id,
            'response_text' => null,
        ]);

        // La burbuja aparece, la persona la toca y no recibe nada. La UI lo
        // avisa en rojo porque el caso queda esperando a un agente que no sabe
        // que existe.
        $this->assertTrue($menu->fresh(['opciones'])->opciones->first()->estaRota());
        $this->assertCount(1, $menu->fresh(['opciones'])->opcionesRotas());
    }

    public function test_handoff_no_cuenta_como_rota(): void
    {
        $menu = $this->menuConOpciones(1);
        $menu->opciones->first()->update([
            'response_type' => InstagramAutomation::RESPONSE_HANDOFF,
            'response_text' => null,
        ]);

        // En handoff no responder es justamente lo configurado.
        $this->assertFalse($menu->fresh(['opciones'])->opciones->first()->estaRota());
    }

    /*
    |-------------------------------------------------------------------------
    | El CRUD
    |-------------------------------------------------------------------------
    */

    public function test_crear_una_opcion_genera_un_payload_unico(): void
    {
        $usuario = $this->usuario();
        $menu = $this->menuConOpciones(0);

        $this->actingAs($usuario)
            ->postJson("/api/instagram/quick-reply-menus/{$menu->id}/options", [
                'title'         => 'Ver precios',
                'response_type' => 'text',
                'response_text' => 'Cuestan...',
            ])
            ->assertCreated();

        $this->actingAs($usuario)
            ->postJson("/api/instagram/quick-reply-menus/{$menu->id}/options", [
                'title'         => 'Ver precios',
                'response_type' => 'text',
                'response_text' => 'Cuestan...',
            ])
            ->assertCreated();

        $payloads = InstagramAutomation::where('menu_group_id', $menu->id)
            ->pluck('payload')
            ->all();

        // Dos opciones de título idéntico son razonables en menús distintos,
        // pero el payload debe diferenciarlas: handlePostback() lo resuelve sin
        // saber de qué menú viene, y un duplicado haría que la respuesta
        // dependiera del orden de la consulta.
        $this->assertCount(2, array_unique($payloads));
    }

    public function test_no_deja_pasar_de_trece_opciones(): void
    {
        $menu = $this->menuConOpciones(InstagramQuickReplyMenu::MAX_OPCIONES);

        $this->actingAs($this->usuario())
            ->postJson("/api/instagram/quick-reply-menus/{$menu->id}/options", [
                'title'         => 'Una más',
                'response_type' => 'text',
                'response_text' => 'Hola',
            ])
            ->assertStatus(422);
    }

    public function test_una_opcion_de_tipo_plantilla_exige_plantilla(): void
    {
        $menu = $this->menuConOpciones(0);

        $this->actingAs($this->usuario())
            ->postJson("/api/instagram/quick-reply-menus/{$menu->id}/options", [
                'title'         => 'Ver precios',
                'response_type' => 'template',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('template_id');
    }

    public function test_borrar_un_menu_no_borra_sus_opciones(): void
    {
        $menu = $this->menuConOpciones(2);
        $ids = $menu->opciones->pluck('id')->all();

        $this->actingAs($this->usuario())
            ->deleteJson("/api/instagram/quick-reply-menus/{$menu->id}")
            ->assertOk();

        // Las opciones ya pueden haberse enviado, y sus payloads siguen
        // llegando por webhook: borrarlas dejaría postbacks que el CRM no sabe
        // resolver.
        $this->assertSame(2, InstagramAutomation::whereIn('id', $ids)->count());
    }

    /*
    |-------------------------------------------------------------------------
    | El DM por comentario
    |-------------------------------------------------------------------------
    */

    public function test_no_deja_elegir_un_menu_vacio_para_el_dm(): void
    {
        $menu = $this->menuConOpciones(0);

        $this->actingAs($this->usuario())
            ->patchJson('/api/instagram/comment-settings', [
                'response_type'       => 'menu',
                'quick_reply_menu_id' => $menu->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quick_reply_menu_id');
    }

    public function test_la_configuracion_devuelve_las_opciones_del_menu_elegido(): void
    {
        $menu = $this->menuConOpciones(3);

        $config = InstagramCommentSetting::actual();
        $config->update([
            'response_type'       => InstagramCommentSetting::RESPONSE_MENU,
            'quick_reply_menu_id' => $menu->id,
        ]);

        $this->assertCount(3, $config->fresh()->opcionesDeMenu());
        $this->assertSame($menu->body, $config->fresh()->respuesta());
    }

    public function test_si_el_menu_se_vacia_la_configuracion_no_devuelve_opciones(): void
    {
        $menu = $this->menuConOpciones(2);

        $config = InstagramCommentSetting::actual();
        $config->update([
            'response_type'       => InstagramCommentSetting::RESPONSE_MENU,
            'quick_reply_menu_id' => $menu->id,
        ]);

        $menu->opciones()->update(['is_active' => false]);

        // Sin opciones no hay menú que mandar: el servicio lo trata como "nada
        // que enviar" en vez de mandar el texto sin las burbujas que lo hacían
        // útil.
        $this->assertSame([], $config->fresh()->opcionesDeMenu());
        $this->assertNull($config->fresh()->respuesta());
    }
}
