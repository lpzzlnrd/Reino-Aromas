<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Métricas del panel de control.
 *
 * Regresión: el dashboard mostraba números inventados. dashboard.cities.vue
 * traía 400/205/182 clientes escritos a mano y solo 3 de las 5 sedes; los KPIs
 * leían un estado que nadie llenaba, así que salían en cero; y la tabla de
 * urgentes tenía una fila de ejemplo ('Nombre cliente', '#RE-0000').
 */
class DashboardReportsTest extends TestCase
{
    use RefreshDatabase;

    private function agente(): User
    {
        return User::factory()->create(['role' => 'administrador', 'is_active' => true]);
    }

    private function contactoConTicket(
        string $canal,
        ?string $ciudad,
        ?string $estadoTicket = null,
    ): Contact {
        $contact = Contact::create([
            'channel'       => $canal,
            'channel_id'    => 'ext-' . uniqid(),
            'display_name'  => 'Cliente ' . uniqid(),
            'city'          => $ciudad,
            'first_seen_at' => now(),
            'last_seen_at'  => now(),
        ]);

        $conversation = $contact->conversations()->create([
            'status'            => Conversation::STATUS_OPEN,
            'within_24h_window' => true,
            'last_message_at'   => now(),
        ]);

        if ($estadoTicket !== null) {
            Ticket::create([
                'conversation_id' => $conversation->id,
                'status'          => $estadoTicket,
                'priority'        => Ticket::PRIORITY_MEDIA,
                'city'            => $ciudad,
            ]);
        }

        return $contact;
    }

    public function test_el_resumen_trae_los_cuatro_bloques(): void
    {
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'caracas', Ticket::STATUS_NUEVO);

        $this->actingAs($this->agente())
            ->getJson('/api/reports/summary')
            ->assertOk()
            ->assertJsonStructure([
                'by_status',
                'by_city'    => [['city', 'label', 'clients', 'tickets', 'percentage']],
                'by_channel' => [['channel', 'label', 'contacts', 'percentage']],
                'totals'     => [
                    'contacts', 'open_conversations', 'tickets',
                    'urgent_tickets', 'unassigned_tickets',
                ],
            ]);
    }

    /** Los conteos usan las etiquetas del enum CaseStatus.ts, no los slugs. */
    public function test_los_conteos_por_estado_usan_las_etiquetas_del_front(): void
    {
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'caracas', Ticket::STATUS_ALTA_PRIORIDAD);
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'caracas', Ticket::STATUS_ALTA_PRIORIDAD);
        $this->contactoConTicket(Contact::CHANNEL_INSTAGRAM, 'valencia', Ticket::STATUS_NUEVO);

        $this->actingAs($this->agente())
            ->getJson('/api/reports/summary')
            ->assertOk()
            ->assertJsonPath('by_status.Urgente', 2)
            ->assertJsonPath('by_status.Nuevo', 1)
            // Los estados sin tickets vienen en cero, no ausentes: el panel
            // pinta una tarjeta por estado.
            ->assertJsonPath('by_status.Reservado', 0)
            ->assertJsonCount(6, 'by_status');
    }

    /**
     * Las CINCO sedes, aunque estén vacías. Antes solo se veían tres y parecía
     * que Maracay y Margarita no existían.
     */
    public function test_devuelve_las_cinco_sedes_aunque_esten_vacias(): void
    {
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'caracas', Ticket::STATUS_NUEVO);

        $response = $this->actingAs($this->agente())
            ->getJson('/api/reports/summary')
            ->assertOk()
            ->assertJsonCount(5, 'by_city');

        $ciudades = collect($response->json('by_city'))->pluck('city')->all();

        foreach (['caracas', 'valencia', 'barquisimeto', 'maracay', 'margarita'] as $sede) {
            $this->assertContains($sede, $ciudades, "Falta la sede {$sede}.");
        }
    }

    public function test_calcula_los_porcentajes_por_ciudad(): void
    {
        // 3 en Caracas, 1 en Valencia → 75% / 25%
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'caracas');
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'caracas');
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'caracas');
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'valencia');

        $porCiudad = collect(
            $this->actingAs($this->agente())->getJson('/api/reports/summary')->json('by_city')
        )->keyBy('city');

        // Comparación numérica y no assertSame: un porcentaje exacto como 75.0
        // se serializa a JSON como 75 (int), y eso no es un error del cálculo.
        $this->assertSame(3, $porCiudad['caracas']['clients']);
        $this->assertEqualsWithDelta(75.0, (float) $porCiudad['caracas']['percentage'], 0.05);
        $this->assertSame(1, $porCiudad['valencia']['clients']);
        $this->assertEqualsWithDelta(25.0, (float) $porCiudad['valencia']['percentage'], 0.05);
    }

    /** La sede con más clientes va primero: el panel la pinta arriba. */
    public function test_ordena_las_ciudades_de_mayor_a_menor(): void
    {
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'maracay');
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'maracay');
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'caracas');

        $primera = $this->actingAs($this->agente())
            ->getJson('/api/reports/summary')
            ->json('by_city.0');

        $this->assertSame('maracay', $primera['city']);
        $this->assertSame(2, $primera['clients']);
    }

    /** Sin datos no debe haber división por cero. */
    public function test_sin_contactos_los_porcentajes_son_cero(): void
    {
        $response = $this->actingAs($this->agente())
            ->getJson('/api/reports/summary')
            ->assertOk();

        foreach ($response->json('by_city') as $ciudad) {
            $this->assertSame(0, $ciudad['clients']);
            $this->assertSame(0.0, (float) $ciudad['percentage']);
        }

        $this->assertSame(0, $response->json('totals.contacts'));
    }

    public function test_distribucion_por_canal_incluye_los_tres(): void
    {
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'caracas');
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'caracas');
        $this->contactoConTicket(Contact::CHANNEL_INSTAGRAM, 'valencia');

        $porCanal = collect(
            $this->actingAs($this->agente())->getJson('/api/reports/summary')->json('by_channel')
        )->keyBy('channel');

        $this->assertCount(3, $porCanal);
        $this->assertSame(2, $porCanal['whatsapp']['contacts']);
        $this->assertSame(1, $porCanal['instagram']['contacts']);
        // Facebook sin contactos debe venir en cero, no ausente.
        $this->assertSame(0, $porCanal['facebook']['contacts']);
    }

    public function test_los_totales_cuentan_urgentes_y_sin_asignar(): void
    {
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'caracas', Ticket::STATUS_ALTA_PRIORIDAD);
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'caracas', Ticket::STATUS_NUEVO);
        // Un cerrado no debe contar como "sin asignar" pendiente.
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'caracas', Ticket::STATUS_CERRADO);

        $totals = $this->actingAs($this->agente())
            ->getJson('/api/reports/summary')
            ->json('totals');

        $this->assertSame(3, $totals['contacts']);
        $this->assertSame(3, $totals['tickets']);
        $this->assertSame(1, $totals['urgent_tickets']);
        $this->assertSame(2, $totals['unassigned_tickets'], 'El cerrado no debe contar.');
    }

    public function test_by_city_responde_igual_que_el_bloque_del_resumen(): void
    {
        $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'caracas', Ticket::STATUS_NUEVO);

        $agente = $this->agente();
        $delResumen = $this->actingAs($agente)->getJson('/api/reports/summary')->json('by_city');
        $delEndpoint = $this->actingAs($agente)->getJson('/api/reports/by-city')->json();

        $this->assertSame($delResumen, $delEndpoint);
    }

    /**
     * La actividad describe cada evento en texto legible y marca los que
     * originó una automatización.
     */
    public function test_la_actividad_describe_los_eventos(): void
    {
        $contact = $this->contactoConTicket(Contact::CHANNEL_WHATSAPP, 'caracas', Ticket::STATUS_NUEVO);
        $ticket = $contact->conversations->first()->ticket;
        $agente = $this->agente();

        // Un cambio hecho por una persona.
        app(\App\Services\TicketService::class)->changeStatus($ticket, Ticket::STATUS_INTERESADO, $agente);

        // Y uno hecho por una automatización (causer nulo).
        app(\App\Services\ActivityLogService::class)->log(
            causerType: null,
            causerId: null,
            targetType: Ticket::class,
            targetId: $ticket->id,
            action: 'ticket.qualified_automatically',
            metadata: ['origen' => 'whatsapp_flow', 'to' => ['status' => Ticket::STATUS_INTERESADO]],
        );

        $items = collect(
            $this->actingAs($agente)->getJson('/api/reports/activity')->assertOk()->json()
        );

        $humano = $items->firstWhere('action', 'ticket.status_changed');
        $this->assertNotNull($humano);
        $this->assertFalse($humano['automatic']);
        $this->assertSame($agente->name, $humano['actor']);
        // La descripción usa la etiqueta del front, no el slug de la BD.
        $this->assertStringContainsString('Interesado', $humano['description']);

        $automatico = $items->firstWhere('action', 'ticket.qualified_automatically');
        $this->assertNotNull($automatico);
        $this->assertTrue($automatico['automatic'], 'Sin causer debe marcarse como automático.');
        $this->assertNull($automatico['actor']);
    }

    /** Una acción desconocida no debe romper la vista. */
    public function test_una_accion_desconocida_no_rompe_la_actividad(): void
    {
        app(\App\Services\ActivityLogService::class)->log(
            causerType: null,
            causerId: null,
            targetType: Ticket::class,
            targetId: 1,
            action: 'accion.que.no.existe',
            metadata: [],
        );

        $this->actingAs($this->agente())
            ->getJson('/api/reports/activity')
            ->assertOk()
            ->assertJsonPath('0.description', 'accion.que.no.existe');
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->getJson('/api/reports/summary')->assertStatus(401);
        $this->getJson('/api/reports/by-city')->assertStatus(401);
        $this->getJson('/api/reports/activity')->assertStatus(401);
    }

    /*
    |-------------------------------------------------------------------------
    | Bloques añadidos para la vista de Reportes: prioridad, curso y rango
    |-------------------------------------------------------------------------
    */

    /** Las cuatro prioridades, aunque estén vacías, y de mayor a menor. */
    public function test_la_distribucion_por_prioridad_trae_las_cuatro_en_orden(): void
    {
        $this->ticketCon(['priority' => Ticket::PRIORITY_MUY_ALTA]);
        $this->ticketCon(['priority' => Ticket::PRIORITY_MEDIA]);
        $this->ticketCon(['priority' => Ticket::PRIORITY_MEDIA]);

        $porPrioridad = $this->actingAs($this->agente())
            ->getJson('/api/reports/summary')
            ->assertOk()
            ->json('by_priority');

        $this->assertCount(4, $porPrioridad);
        $this->assertSame(
            ['muy_alta', 'alta', 'media', 'baja'],
            array_column($porPrioridad, 'priority'),
            'La vista las pinta en este orden.',
        );

        $porClave = collect($porPrioridad)->keyBy('priority');
        $this->assertSame(2, $porClave['media']['tickets']);
        $this->assertSame(1, $porClave['muy_alta']['tickets']);
        $this->assertSame(0, $porClave['alta']['tickets'], 'Las vacías vienen en cero, no ausentes.');
        $this->assertEqualsWithDelta(66.7, (float) $porClave['media']['percentage'], 0.05);
    }

    /**
     * El curso es texto libre: "Velas" y "velas" son el mismo curso y deben
     * contarse juntos. Se agrupa en PHP justamente porque SQLite distingue
     * mayúsculas y MySQL no.
     */
    public function test_los_cursos_se_agrupan_sin_distinguir_mayusculas(): void
    {
        $this->ticketCon(['course_interest' => 'Velas']);
        $this->ticketCon(['course_interest' => 'velas']);
        $this->ticketCon(['course_interest' => '  VELAS  ']);
        $this->ticketCon(['course_interest' => 'Jabones']);
        // Sin curso: no debe aparecer como una fila vacía.
        $this->ticketCon(['course_interest' => null]);

        $porCurso = $this->actingAs($this->agente())
            ->getJson('/api/reports/summary')
            ->assertOk()
            ->json('by_course');

        $this->assertCount(2, $porCurso, 'Velas (x3) y Jabones (x1).');

        // El más pedido va primero: es la pregunta que responde el reporte.
        $this->assertSame('velas', $porCurso[0]['course']);
        $this->assertSame(3, $porCurso[0]['tickets']);
        $this->assertEqualsWithDelta(75.0, (float) $porCurso[0]['percentage'], 0.05);

        $this->assertSame(1, $porCurso[1]['tickets']);
    }

    public function test_sin_cursos_registrados_devuelve_lista_vacia(): void
    {
        $this->ticketCon(['course_interest' => null]);

        $this->actingAs($this->agente())
            ->getJson('/api/reports/summary')
            ->assertOk()
            ->assertJsonCount(0, 'by_course');
    }

    /** El rango filtra los tickets por fecha de creación. */
    public function test_el_rango_de_fechas_filtra_los_tickets(): void
    {
        $this->ticketCon(['status' => Ticket::STATUS_NUEVO], creadoEn: now()->subDays(40));
        $this->ticketCon(['status' => Ticket::STATUS_NUEVO], creadoEn: now()->subDays(3));
        $this->ticketCon(['status' => Ticket::STATUS_NUEVO], creadoEn: now()->subDay());

        $agente = $this->agente();

        $historico = $this->actingAs($agente)->getJson('/api/reports/summary')->json('totals.tickets');
        $this->assertSame(3, $historico, 'Sin rango se cuenta todo, como antes.');

        $ultimaSemana = $this->actingAs($agente)
            ->getJson('/api/reports/summary?from=' . now()->subDays(7)->toDateString())
            ->json('totals.tickets');

        $this->assertSame(2, $ultimaSemana, 'El de hace 40 días queda fuera.');
    }

    /**
     * `to` debe incluir el día completo. Con ?to=hoy, un ticket creado hoy a
     * media tarde tiene que contar — cortar a medianoche lo dejaría fuera.
     */
    public function test_el_hasta_incluye_el_dia_completo(): void
    {
        $this->ticketCon([], creadoEn: now()->setTime(15, 30));

        $total = $this->actingAs($this->agente())
            ->getJson('/api/reports/summary?to=' . now()->toDateString())
            ->json('totals.tickets');

        $this->assertSame(1, $total, 'Un ticket de esta tarde debe entrar con to=hoy.');
    }

    /** Un rango al revés se corrige en vez de devolver un reporte vacío. */
    public function test_un_rango_invertido_se_corrige(): void
    {
        $this->ticketCon([], creadoEn: now()->subDays(3));

        $total = $this->actingAs($this->agente())
            ->getJson('/api/reports/summary?from=' . now()->toDateString()
                . '&to=' . now()->subDays(7)->toDateString())
            ->json('totals.tickets');

        $this->assertSame(1, $total, 'Invertido debe comportarse como el rango correcto.');
    }

    /** Una fecha basura se ignora: mejor el histórico que un 500. */
    public function test_una_fecha_invalida_no_rompe_el_reporte(): void
    {
        $this->ticketCon([]);

        $this->actingAs($this->agente())
            ->getJson('/api/reports/summary?from=no-es-una-fecha')
            ->assertOk()
            ->assertJsonPath('totals.tickets', 1);
    }

    /** El rango aplicado se devuelve para que la vista lo muestre al pie. */
    public function test_el_resumen_devuelve_el_rango_aplicado(): void
    {
        $agente = $this->agente();

        $this->actingAs($agente)
            ->getJson('/api/reports/summary?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('range.from', '2026-08-01')
            ->assertJsonPath('range.to', '2026-08-31');

        // Sin rango, ambos nulos: el front lo lee como "todo el histórico".
        $this->actingAs($agente)
            ->getJson('/api/reports/summary')
            ->assertJsonPath('range.from', null)
            ->assertJsonPath('range.to', null);
    }

    /**
     * Los cerrados van por closed_at, no por created_at: importa cuándo se
     * cerró el ticket, no cuándo nació.
     */
    public function test_los_cerrados_cuentan_por_fecha_de_cierre(): void
    {
        // Nació hace 40 días pero se cerró ayer: cuenta en la última semana.
        $ticket = $this->ticketCon(
            ['status' => Ticket::STATUS_CERRADO],
            creadoEn: now()->subDays(40),
        );
        $ticket->forceFill(['closed_at' => now()->subDay()])->save();

        $cerrados = $this->actingAs($this->agente())
            ->getJson('/api/reports/summary?from=' . now()->subDays(7)->toDateString())
            ->json('totals.closed_tickets');

        $this->assertSame(1, $cerrados);
    }

    /**
     * open_conversations y unassigned_tickets son fotos del AHORA: filtrarlas
     * por rango daría un número sin significado.
     */
    public function test_los_totales_de_estado_actual_ignoran_el_rango(): void
    {
        $this->ticketCon(['status' => Ticket::STATUS_NUEVO], creadoEn: now()->subDays(40));

        $totals = $this->actingAs($this->agente())
            ->getJson('/api/reports/summary?from=' . now()->subDay()->toDateString())
            ->json('totals');

        $this->assertSame(0, $totals['tickets'], 'El ticket viejo queda fuera del rango.');
        $this->assertSame(1, $totals['open_conversations'], 'Pero el chat sigue abierto hoy.');
        $this->assertSame(1, $totals['unassigned_tickets'], 'Y sigue sin asignar hoy.');
    }

    public function test_la_actividad_respeta_el_limite(): void
    {
        foreach (range(1, 20) as $i) {
            app(\App\Services\ActivityLogService::class)->log(
                causerType: null,
                causerId: null,
                targetType: Ticket::class,
                targetId: $i,
                action: 'ticket.created',
                metadata: [],
            );
        }

        $agente = $this->agente();

        $this->assertCount(
            15,
            $this->actingAs($agente)->getJson('/api/reports/activity')->assertOk()->json(),
            'El default sigue siendo 15, como lo espera el dashboard.',
        );

        $this->assertCount(
            5,
            $this->actingAs($agente)->getJson('/api/reports/activity?limit=5')->assertOk()->json(),
        );

        // Un límite absurdo se topa en 100, no trae la tabla entera.
        $this->assertCount(
            20,
            $this->actingAs($agente)->getJson('/api/reports/activity?limit=99999')->assertOk()->json(),
        );
    }

    /**
     * Crea un ticket con su contacto y conversación, para los casos donde solo
     * importan los campos del ticket.
     *
     * @param array<string, mixed> $atributos
     */
    private function ticketCon(array $atributos, ?\DateTimeInterface $creadoEn = null): Ticket
    {
        $contact = Contact::create([
            'channel'       => Contact::CHANNEL_WHATSAPP,
            'channel_id'    => 'ext-' . uniqid(),
            'display_name'  => 'Cliente ' . uniqid(),
            'city'          => 'caracas',
            'first_seen_at' => $creadoEn ?? now(),
            'last_seen_at'  => now(),
        ]);

        $conversation = $contact->conversations()->create([
            'status'            => Conversation::STATUS_OPEN,
            'within_24h_window' => true,
            'last_message_at'   => now(),
        ]);

        $ticket = Ticket::create(array_merge([
            'conversation_id' => $conversation->id,
            'status'          => Ticket::STATUS_NUEVO,
            'priority'        => Ticket::PRIORITY_MEDIA,
            'city'            => 'caracas',
        ], $atributos));

        // created_at se fuerza aparte: Eloquent lo pisa con la hora actual al
        // insertar, así que pasarlo en el create() no serviría.
        if ($creadoEn !== null) {
            $ticket->forceFill(['created_at' => $creadoEn])->save();
        }

        return $ticket->refresh();
    }
}
