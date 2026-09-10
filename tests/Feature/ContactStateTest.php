<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Estado del país en contactos, tickets y reportes.
 *
 * `state` es un corte distinto de `city`, no un reemplazo: `city` son las cinco
 * sedes donde Reino Aromas da cursos y `state` de dónde escribe el cliente. Un
 * cliente de Táchira atendido desde la sede de Valencia cuenta en las dos, y
 * las dos cosas son ciertas.
 *
 * La columna es VARCHAR y no ENUM (un ENUM de 24 valores obliga a un ALTER
 * TABLE por cada cambio), así que lo único que restringe los valores es la
 * validación contra Contact::states(). De ahí que estos tests cubran el
 * rechazo: sin ellos, la BD aceptaría cualquier cadena de 40 caracteres.
 */
class ContactStateTest extends TestCase
{
    use RefreshDatabase;

    private function agente(): User
    {
        return User::factory()->create(['role' => 'administrador', 'is_active' => true]);
    }

    private function contacto(?string $estado = null, ?string $ciudad = 'caracas'): Contact
    {
        return Contact::create([
            'channel'       => Contact::CHANNEL_WHATSAPP,
            'channel_id'    => 'ext-' . uniqid(),
            'display_name'  => 'Cliente Prueba',
            'city'          => $ciudad,
            'state'         => $estado,
            'first_seen_at' => now()->subDays(3),
            'last_seen_at'  => now(),
        ]);
    }

    private function conversacionCon(Contact $contact): Conversation
    {
        return Conversation::create([
            'contact_id'      => $contact->id,
            'status'          => Conversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);
    }

    /*
    |-------------------------------------------------------------------------
    | Catálogo
    |-------------------------------------------------------------------------
    */

    public function test_el_catalogo_trae_los_24_estados(): void
    {
        $this->actingAs($this->agente())
            ->getJson('/api/states')
            ->assertOk()
            ->assertJsonCount(24)
            ->assertJsonStructure([['value', 'label']]);
    }

    /**
     * El front lee las etiquetas de aquí en vez de tener su propia copia: dos
     * copias de los mismos 24 slugs se desincronizan en el primer cambio.
     */
    public function test_el_catalogo_sirve_las_etiquetas_con_acentos(): void
    {
        $respuesta = $this->actingAs($this->agente())
            ->getJson('/api/states')
            ->assertOk()
            ->json();

        $porSlug = collect($respuesta)->pluck('label', 'value');

        $this->assertSame('Distrito Capital', $porSlug['distrito_capital']);
        $this->assertSame('Táchira', $porSlug['tachira']);
        $this->assertSame('Anzoátegui', $porSlug['anzoategui']);
    }

    public function test_el_catalogo_exige_sesion(): void
    {
        $this->getJson('/api/states')->assertUnauthorized();
    }

    /*
    |-------------------------------------------------------------------------
    | Contacto
    |-------------------------------------------------------------------------
    */

    public function test_asigna_el_estado_a_un_contacto(): void
    {
        $contacto = $this->contacto();

        $this->actingAs($this->agente())
            ->patchJson("/api/contacts/{$contacto->id}", ['state' => 'zulia'])
            ->assertOk()
            ->assertJsonPath('contact.state', 'zulia')
            // La etiqueta va servida para que la vista no derive el nombre.
            ->assertJsonPath('contact.state_label', 'Zulia');

        $this->assertSame('zulia', $contacto->fresh()->state);
    }

    public function test_rechaza_un_estado_que_no_existe(): void
    {
        $contacto = $this->contacto();

        $this->actingAs($this->agente())
            ->patchJson("/api/contacts/{$contacto->id}", ['state' => 'florida'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('state');

        $this->assertNull($contacto->fresh()->state);
    }

    /**
     * Poner el estado en null es una operación válida: el agente pudo haberse
     * equivocado y volver a "sin determinar" tiene que ser posible.
     */
    public function test_permite_limpiar_el_estado(): void
    {
        $contacto = $this->contacto('miranda');

        $this->actingAs($this->agente())
            ->patchJson("/api/contacts/{$contacto->id}", ['state' => null])
            ->assertOk()
            ->assertJsonPath('contact.state', null)
            ->assertJsonPath('contact.state_label', null);

        $this->assertNull($contacto->fresh()->state);
    }

    /**
     * El estado no toca la sede. Son dos preguntas distintas y confundirlas
     * borraría el dato de a qué sede pertenece el cliente.
     */
    public function test_el_estado_no_pisa_la_ciudad(): void
    {
        $contacto = $this->contacto(null, 'valencia');

        $this->actingAs($this->agente())
            ->patchJson("/api/contacts/{$contacto->id}", ['state' => 'tachira'])
            ->assertOk();

        $fresco = $contacto->fresh();

        $this->assertSame('tachira', $fresco->state);
        $this->assertSame('valencia', $fresco->city);
    }

    public function test_filtra_contactos_por_estado(): void
    {
        $this->contacto('zulia');
        $this->contacto('zulia');
        $this->contacto('merida');
        $this->contacto(null);

        $this->actingAs($this->agente())
            ->getJson('/api/contacts?state=zulia')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    /*
    |-------------------------------------------------------------------------
    | Ticket
    |-------------------------------------------------------------------------
    */

    /**
     * El ticket copia el estado del contacto al nacer.
     *
     * Es la misma decisión que ya existía con `city`: el reporte por estado no
     * necesita un JOIN, y el ticket conserva de dónde venía el cliente aunque
     * después se corrija su ficha.
     */
    public function test_el_ticket_copia_el_estado_del_contacto_al_crearse(): void
    {
        $contacto     = $this->contacto('lara');
        $conversacion = $this->conversacionCon($contacto);

        $ticket = app(TicketService::class)->ensureTicketExists($conversacion);

        $this->assertSame('lara', $ticket->state);
    }

    /**
     * La copia es histórica: corregir la ficha del cliente NO reescribe los
     * tickets viejos. Si lo hiciera, un reporte de hace tres meses cambiaría
     * retroactivamente cada vez que alguien edita un contacto.
     */
    public function test_corregir_el_contacto_no_reescribe_el_ticket_viejo(): void
    {
        $contacto     = $this->contacto('lara');
        $conversacion = $this->conversacionCon($contacto);
        $ticket       = app(TicketService::class)->ensureTicketExists($conversacion);

        $this->actingAs($this->agente())
            ->patchJson("/api/contacts/{$contacto->id}", ['state' => 'zulia'])
            ->assertOk();

        $this->assertSame('lara', $ticket->fresh()->state);
    }

    /**
     * El PATCH del ticket tiene una lista EXPLÍCITA de campos que se escriben
     * directo ($directos). Un campo validado pero ausente de esa lista se
     * acepta con 200 y se descarta en silencio: el fallo más engañoso que hay,
     * porque la respuesta dice que todo fue bien.
     */
    public function test_el_patch_del_ticket_guarda_el_estado(): void
    {
        $contacto     = $this->contacto();
        $conversacion = $this->conversacionCon($contacto);
        $ticket       = app(TicketService::class)->ensureTicketExists($conversacion);

        $this->actingAs($this->agente())
            ->patchJson("/api/tickets/{$ticket->id}", ['state' => 'monagas'])
            ->assertOk()
            ->assertJsonPath('ticket.state', 'monagas')
            ->assertJsonPath('ticket.state_label', 'Monagas');

        $this->assertSame('monagas', $ticket->fresh()->state);
    }

    public function test_el_patch_del_ticket_rechaza_un_estado_invalido(): void
    {
        $contacto     = $this->contacto();
        $conversacion = $this->conversacionCon($contacto);
        $ticket       = app(TicketService::class)->ensureTicketExists($conversacion);

        $this->actingAs($this->agente())
            ->patchJson("/api/tickets/{$ticket->id}", ['state' => 'no_existe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('state');
    }

    /*
    |-------------------------------------------------------------------------
    | El chat
    |-------------------------------------------------------------------------
    */

    /**
     * El panel lateral del chat es donde el agente asigna el estado, así que
     * el detalle de la conversación tiene que traerlo: sin él, el desplegable
     * no puede mostrar el valor actual.
     */
    public function test_el_detalle_del_chat_trae_el_estado_del_contacto(): void
    {
        $contacto     = $this->contacto('falcon');
        $conversacion = $this->conversacionCon($contacto);

        $this->actingAs($this->agente())
            ->getJson("/api/meta/conversations/{$conversacion->id}")
            ->assertOk()
            ->assertJsonPath('contact.state', 'falcon')
            ->assertJsonPath('contact.state_label', 'Falcón');
    }

    /*
    |-------------------------------------------------------------------------
    | Reportes
    |-------------------------------------------------------------------------
    */

    public function test_el_reporte_agrupa_los_clientes_por_estado(): void
    {
        $this->contacto('zulia');
        $this->contacto('zulia');
        $this->contacto('merida');

        $porEstado = collect(
            $this->actingAs($this->agente())
                ->getJson('/api/reports/summary')
                ->assertOk()
                ->json('by_state')
        );

        // Mayor primero: el estado más activo arriba.
        $this->assertSame('zulia', $porEstado->first()['state']);
        $this->assertSame(2, $porEstado->first()['clients']);
        $this->assertSame('Zulia', $porEstado->first()['label']);

        $merida = $porEstado->firstWhere('state', 'merida');
        $this->assertSame(1, $merida['clients']);
    }

    /**
     * A diferencia de las sedes, NO se devuelven los 24 estados con cero:
     * veintitrés barras vacías para ver una con datos no informan de nada.
     */
    public function test_el_reporte_omite_los_estados_sin_clientes(): void
    {
        $this->contacto('sucre');

        $porEstado = collect(
            $this->actingAs($this->agente())
                ->getJson('/api/reports/summary')
                ->assertOk()
                ->json('by_state')
        );

        $this->assertCount(1, $porEstado);
        $this->assertSame('sucre', $porEstado->first()['state']);
    }

    /**
     * Los sin clasificar aparecen como una fila agregada con state=null.
     *
     * Es el número que de verdad importa al principio, porque mide cuánto
     * queda por clasificar. Se cuenta con su propia consulta y no leyendo la
     * clave NULL de un pluck: una clave NULL en una Collection se normaliza a
     * cadena vacía y el valor sería inalcanzable según el driver.
     */
    public function test_el_reporte_incluye_una_fila_de_sin_determinar(): void
    {
        $this->contacto('bolivar');
        $this->contacto(null);
        $this->contacto(null);

        $porEstado = collect(
            $this->actingAs($this->agente())
                ->getJson('/api/reports/summary')
                ->assertOk()
                ->json('by_state')
        );

        $sinEstado = $porEstado->firstWhere('state', null);

        $this->assertNotNull($sinEstado, 'Falta la fila agregada de sin determinar.');
        $this->assertSame(2, $sinEstado['clients']);
        $this->assertSame('Sin determinar', $sinEstado['label']);
    }

    /**
     * Los porcentajes se calculan sobre el total INCLUYENDO los sin clasificar.
     *
     * Si se calcularan solo sobre los clasificados sumarían 100% entre ellos y
     * "sin determinar" se leería como si no existiera: justo el número que hay
     * que ver.
     */
    public function test_los_porcentajes_cuentan_a_los_sin_determinar(): void
    {
        $this->contacto('aragua');
        $this->contacto(null);

        $porEstado = collect(
            $this->actingAs($this->agente())
                ->getJson('/api/reports/summary')
                ->assertOk()
                ->json('by_state')
        );

        // assertEquals y no assertSame: round() devuelve 50.0 pero al pasar por
        // JSON un flotante sin parte decimal vuelve como int 50, así que
        // comparar el tipo fallaría por un detalle de serialización.
        $this->assertEquals(50, $porEstado->firstWhere('state', 'aragua')['percentage']);
        $this->assertEquals(50, $porEstado->firstWhere('state', null)['percentage']);
    }

    /**
     * La fila de "sin determinar" solo aparece si hay algo por clasificar: con
     * todo asignado, una fila de cero sería ruido.
     */
    public function test_sin_pendientes_no_hay_fila_de_sin_determinar(): void
    {
        $this->contacto('carabobo');

        $porEstado = collect(
            $this->actingAs($this->agente())
                ->getJson('/api/reports/summary')
                ->assertOk()
                ->json('by_state')
        );

        $this->assertNull($porEstado->firstWhere('state', null));
    }

    public function test_el_reporte_por_estado_cuenta_tickets_ademas_de_clientes(): void
    {
        $contacto     = $this->contacto('portuguesa');
        $conversacion = $this->conversacionCon($contacto);

        app(TicketService::class)->ensureTicketExists($conversacion);

        $porEstado = collect(
            $this->actingAs($this->agente())
                ->getJson('/api/reports/summary')
                ->assertOk()
                ->json('by_state')
        );

        $fila = $porEstado->firstWhere('state', 'portuguesa');

        $this->assertSame(1, $fila['clients']);
        $this->assertSame(1, $fila['tickets']);
    }

    /**
     * El reporte respeta el rango de fechas, y los contactos se filtran por
     * `first_seen_at` (cuándo entró el cliente al CRM) no por `created_at`.
     */
    public function test_el_reporte_por_estado_respeta_el_rango(): void
    {
        $viejo = $this->contacto('trujillo');
        $viejo->forceFill(['first_seen_at' => now()->subMonths(6)])->save();

        $this->contacto('yaracuy');

        $porEstado = collect(
            $this->actingAs($this->agente())
                ->getJson('/api/reports/summary?from=' . now()->subDays(7)->toDateString())
                ->assertOk()
                ->json('by_state')
        );

        $this->assertNull($porEstado->firstWhere('state', 'trujillo'));
        $this->assertNotNull($porEstado->firstWhere('state', 'yaracuy'));
    }

    /**
     * Un slug que no está en el catálogo (dato viejo, o escrito a mano directo
     * en la BD) se muestra tal cual en vez de desaparecer del reporte: un
     * total que no cuadra es peor que un nombre feo.
     */
    public function test_un_slug_desconocido_no_desaparece_del_reporte(): void
    {
        $contacto = $this->contacto();
        // Se salta la validación a propósito: simula el dato ya en la BD.
        $contacto->forceFill(['state' => 'estado_raro'])->save();

        $porEstado = collect(
            $this->actingAs($this->agente())
                ->getJson('/api/reports/summary')
                ->assertOk()
                ->json('by_state')
        );

        $fila = $porEstado->firstWhere('state', 'estado_raro');

        $this->assertNotNull($fila);
        $this->assertSame('estado_raro', $fila['label']);
    }
}
