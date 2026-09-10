<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Template;
use App\Models\User;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Respuestas rápidas: las plantillas que el chat pinta como botones sobre la
 * barra de escritura y que se envían con un clic.
 *
 * No son una entidad nueva sino un subconjunto del catálogo de plantillas
 * marcado con `is_quick_reply`. Por eso lo que hay que probar aquí no es el
 * render (ya lo cubre TemplateService) sino que el subconjunto se elija bien:
 * mismos filtros de ciudad y canal que el desplegable, el orden que puso el
 * negocio y no el de más usadas, y el tope de botones.
 */
class QuickReplyTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function agente(): User
    {
        return User::factory()->create(['role' => 'administrador', 'is_active' => true]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function plantilla(string $nombre, array $extra = []): Template
    {
        return Template::create([
            'name'           => $nombre,
            'body'           => 'Hola {{nombre}}, gracias por escribir.',
            'is_active'      => true,
            'is_quick_reply' => true,
            'sort_order'     => 0,
            ...$extra,
        ]);
    }

    private function conversacion(
        string $canal = Contact::CHANNEL_WHATSAPP,
        ?string $ciudad = 'caracas',
        string $nombre = 'María Fernanda',
    ): Conversation {
        $contacto = Contact::create([
            'channel'       => $canal,
            'channel_id'    => 'ext-' . uniqid(),
            'display_name'  => $nombre,
            'city'          => $ciudad,
            'first_seen_at' => now()->subDay(),
            'last_seen_at'  => now(),
        ]);

        return Conversation::create([
            'contact_id'      => $contacto->id,
            'status'          => Conversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);
    }

    /*
    |-------------------------------------------------------------------------
    | El endpoint del chat
    |-------------------------------------------------------------------------
    */

    public function test_devuelve_solo_las_marcadas_de_acceso_rapido(): void
    {
        $this->plantilla('Saludo');
        $this->plantilla('Precios');
        $this->plantilla('Texto largo del catálogo', ['is_quick_reply' => false]);

        $conversacion = $this->conversacion();

        $nombres = collect(
            $this->actingAs($this->agente())
                ->getJson("/api/conversations/{$conversacion->id}/quick-replies")
                ->assertOk()
                ->json()
        )->pluck('name');

        $this->assertCount(2, $nombres);
        $this->assertFalse($nombres->contains('Texto largo del catálogo'));
    }

    /**
     * Un botón inactivo no se pinta: el envío fallaría o mandaría un texto que
     * el negocio ya retiró.
     */
    public function test_omite_las_plantillas_inactivas(): void
    {
        $this->plantilla('Vigente');
        $this->plantilla('De temporada', ['is_active' => false]);

        $conversacion = $this->conversacion();

        $this->actingAs($this->agente())
            ->getJson("/api/conversations/{$conversacion->id}/quick-replies")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Vigente');
    }

    /**
     * El cuerpo llega ya renderizado con los datos del contacto: el botón
     * envía sin paso intermedio, así que el front no tiene dónde sustituir
     * las variables.
     */
    public function test_el_cuerpo_llega_renderizado_con_el_nombre_del_contacto(): void
    {
        $this->plantilla('Saludo');

        $conversacion = $this->conversacion(nombre: 'María Fernanda González');

        $this->actingAs($this->agente())
            ->getJson("/api/conversations/{$conversacion->id}/quick-replies")
            ->assertOk()
            // Solo el primer nombre: "Hola María" suena natural, el nombre
            // completo suena a carta del banco.
            ->assertJsonPath('0.rendered_body', 'Hola María, gracias por escribir.')
            // El cuerpo crudo también viaja, para que el gestor pueda mostrarlo.
            ->assertJsonPath('0.body', 'Hola {{nombre}}, gracias por escribir.');
    }

    /*
    |-------------------------------------------------------------------------
    | Los mismos filtros que el desplegable
    |-------------------------------------------------------------------------
    */

    /**
     * Un botón de un canal concreto no sale en otro: un texto que dice
     * "responde a este mensaje" no tiene sentido en Instagram, igual que no
     * lo tiene en el desplegable.
     */
    public function test_respeta_el_filtro_por_canal(): void
    {
        $this->plantilla('Solo WhatsApp', ['channel' => 'whatsapp']);
        $this->plantilla('Solo Instagram', ['channel' => 'instagram']);
        $this->plantilla('Para todos');

        $conversacion = $this->conversacion(canal: Contact::CHANNEL_INSTAGRAM);

        $nombres = collect(
            $this->actingAs($this->agente())
                ->getJson("/api/conversations/{$conversacion->id}/quick-replies")
                ->assertOk()
                ->json()
        )->pluck('name');

        $this->assertTrue($nombres->contains('Solo Instagram'));
        // NULL significa "sirve para cualquier canal".
        $this->assertTrue($nombres->contains('Para todos'));
        $this->assertFalse($nombres->contains('Solo WhatsApp'));
    }

    public function test_respeta_el_filtro_por_ciudad(): void
    {
        $this->plantilla('Precio Caracas', ['city' => 'caracas']);
        $this->plantilla('Precio Valencia', ['city' => 'valencia']);
        $this->plantilla('Sin ciudad');

        $conversacion = $this->conversacion(ciudad: 'valencia');

        $nombres = collect(
            $this->actingAs($this->agente())
                ->getJson("/api/conversations/{$conversacion->id}/quick-replies")
                ->assertOk()
                ->json()
        )->pluck('name');

        $this->assertTrue($nombres->contains('Precio Valencia'));
        $this->assertTrue($nombres->contains('Sin ciudad'));
        $this->assertFalse($nombres->contains('Precio Caracas'));
    }

    /*
    |-------------------------------------------------------------------------
    | Orden y tope
    |-------------------------------------------------------------------------
    */

    /**
     * El orden lo pone el negocio con `sort_order`, NO el contador de uso.
     *
     * Es la diferencia con el desplegable, que ordena por más usadas: la barra
     * sigue el guion de atención (saludar, precios, horarios, despedir), así
     * que el saludo va primero aunque los precios se manden más veces.
     */
    public function test_ordena_por_sort_order_y_no_por_uso(): void
    {
        $this->plantilla('Despedida', ['sort_order' => 3, 'usage_count' => 500]);
        $this->plantilla('Saludo', ['sort_order' => 1, 'usage_count' => 1]);
        $this->plantilla('Precios', ['sort_order' => 2, 'usage_count' => 900]);

        $conversacion = $this->conversacion();

        $this->actingAs($this->agente())
            ->getJson("/api/conversations/{$conversacion->id}/quick-replies")
            ->assertOk()
            ->assertJsonPath('0.name', 'Saludo')
            ->assertJsonPath('1.name', 'Precios')
            ->assertJsonPath('2.name', 'Despedida');
    }

    /**
     * Con el mismo sort_order desempata el nombre, para que dos botones no
     * bailen de posición entre cargas.
     */
    public function test_desempata_por_nombre_con_el_mismo_orden(): void
    {
        $this->plantilla('Zeta', ['sort_order' => 1]);
        $this->plantilla('Alfa', ['sort_order' => 1]);

        $conversacion = $this->conversacion();

        $this->actingAs($this->agente())
            ->getJson("/api/conversations/{$conversacion->id}/quick-replies")
            ->assertOk()
            ->assertJsonPath('0.name', 'Alfa')
            ->assertJsonPath('1.name', 'Zeta');
    }

    /**
     * La barra es una fila horizontal sobre el input: pasado el tope empuja el
     * campo de escritura fuera de la pantalla en móvil. Lo que sobra sigue
     * accesible en el desplegable completo.
     */
    public function test_limita_la_cantidad_de_botones(): void
    {
        for ($i = 1; $i <= TemplateService::MAXIMO_BOTONES + 4; $i++) {
            $this->plantilla("Boton {$i}", ['sort_order' => $i]);
        }

        $conversacion = $this->conversacion();

        $this->actingAs($this->agente())
            ->getJson("/api/conversations/{$conversacion->id}/quick-replies")
            ->assertOk()
            ->assertJsonCount(TemplateService::MAXIMO_BOTONES);
    }

    public function test_sin_respuestas_rapidas_devuelve_una_lista_vacia(): void
    {
        $this->plantilla('Solo catálogo', ['is_quick_reply' => false]);

        $conversacion = $this->conversacion();

        $this->actingAs($this->agente())
            ->getJson("/api/conversations/{$conversacion->id}/quick-replies")
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_el_endpoint_exige_sesion(): void
    {
        $conversacion = $this->conversacion();

        $this->getJson("/api/conversations/{$conversacion->id}/quick-replies")
            ->assertUnauthorized();
    }

    /*
    |-------------------------------------------------------------------------
    | El gestor
    |-------------------------------------------------------------------------
    */

    public function test_crea_una_plantilla_como_respuesta_rapida(): void
    {
        $this->actingAs($this->agente())
            ->postJson('/api/templates', [
                'name'           => 'Saludo inicial',
                'body'           => 'Hola {{nombre}}!',
                'is_active'      => true,
                'is_quick_reply' => true,
                'sort_order'     => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('is_quick_reply', true)
            ->assertJsonPath('sort_order', 5);

        $this->assertTrue(Template::where('name', 'Saludo inicial')->first()->is_quick_reply);
    }

    /**
     * Por defecto NO es botón: al crear una plantilla sin decir nada, la barra
     * del chat no crece sola. Quién merece un botón es decisión del negocio.
     */
    public function test_una_plantilla_nueva_no_es_boton_por_defecto(): void
    {
        $this->actingAs($this->agente())
            ->postJson('/api/templates', [
                'name' => 'Texto del catálogo',
                'body' => 'Un texto largo cualquiera.',
            ])
            ->assertCreated()
            ->assertJsonPath('is_quick_reply', false)
            ->assertJsonPath('sort_order', 0);
    }

    public function test_convierte_una_plantilla_existente_en_boton(): void
    {
        $plantilla = $this->plantilla('Ya existía', ['is_quick_reply' => false]);

        $this->actingAs($this->agente())
            ->putJson("/api/templates/{$plantilla->id}", [
                'name'           => 'Ya existía',
                'body'           => $plantilla->body,
                'is_active'      => true,
                'is_quick_reply' => true,
                'sort_order'     => 2,
            ])
            ->assertOk()
            ->assertJsonPath('is_quick_reply', true)
            ->assertJsonPath('sort_order', 2);

        $this->assertTrue($plantilla->fresh()->is_quick_reply);
    }

    public function test_rechaza_un_orden_fuera_de_rango(): void
    {
        $this->actingAs($this->agente())
            ->postJson('/api/templates', [
                'name'           => 'Orden imposible',
                'body'           => 'Texto.',
                'is_quick_reply' => true,
                'sort_order'     => 5000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort_order');
    }

    /**
     * El gestor necesita poder listar solo los botones para mostrarlos juntos
     * y en su orden, que es cómo se decide si la barra tiene sentido.
     */
    public function test_el_listado_filtra_por_respuesta_rapida(): void
    {
        $this->plantilla('Boton A');
        $this->plantilla('Del catálogo', ['is_quick_reply' => false]);

        $nombres = collect(
            $this->actingAs($this->agente())
                ->getJson('/api/templates?quick=1')
                ->assertOk()
                ->json('templates')
        )->pluck('name');

        $this->assertCount(1, $nombres);
        $this->assertSame('Boton A', $nombres->first());
    }

    /**
     * El listado completo tiene que seguir trayendo los dos campos: la tarjeta
     * pinta la insignia "Botón" y el editor precarga el orden.
     */
    public function test_el_listado_completo_sirve_los_campos_nuevos(): void
    {
        $this->plantilla('Con boton', ['sort_order' => 4]);

        $this->actingAs($this->agente())
            ->getJson('/api/templates')
            ->assertOk()
            ->assertJsonPath('templates.0.is_quick_reply', true)
            ->assertJsonPath('templates.0.sort_order', 4);
    }
}
