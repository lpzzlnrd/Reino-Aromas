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
}
