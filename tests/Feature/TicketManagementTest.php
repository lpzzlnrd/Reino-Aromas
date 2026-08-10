<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tickets: filtros, contadores y auditoría.
 *
 * Los cambios de estado, prioridad y asignación deben quedar en activity_logs
 * — el historial de quién movió qué es requisito del negocio.
 */
class TicketManagementTest extends TestCase
{
    use RefreshDatabase;

    private function agente(): User
    {
        return User::factory()->create(['role' => 'administrador', 'is_active' => true]);
    }

    private function ticket(
        string $estado = Ticket::STATUS_NUEVO,
        string $prioridad = Ticket::PRIORITY_MEDIA,
        string $ciudad = 'caracas',
    ): Ticket {
        $contact = Contact::create([
            'channel'       => Contact::CHANNEL_WHATSAPP,
            'channel_id'    => 'ext-' . uniqid(),
            'display_name'  => 'Cliente Prueba',
            'city'          => $ciudad,
            'first_seen_at' => now(),
            'last_seen_at'  => now(),
        ]);

        $conversation = $contact->conversations()->create([
            'status'            => Conversation::STATUS_OPEN,
            'within_24h_window' => true,
            'last_message_at'   => now(),
        ]);

        return Ticket::create([
            'conversation_id' => $conversation->id,
            'status'          => $estado,
            'priority'        => $prioridad,
            'city'            => $ciudad,
        ]);
    }

    /** Los contadores usan las etiquetas del enum CaseStatus.ts del front. */
    public function test_los_contadores_usan_las_etiquetas_del_front(): void
    {
        $this->ticket(Ticket::STATUS_NUEVO);
        $this->ticket(Ticket::STATUS_NUEVO);
        $this->ticket(Ticket::STATUS_ALTA_PRIORIDAD);

        $this->actingAs($this->agente())
            ->getJson('/api/tickets/counts')
            ->assertOk()
            ->assertJsonPath('Nuevo', 2)
            ->assertJsonPath('Urgente', 1)
            // Los estados en cero deben venir igual: el front pinta una
            // columna por estado y necesita la clave presente.
            ->assertJsonPath('Reservado', 0)
            ->assertJsonPath('Cerrado', 0)
            ->assertJsonCount(6);
    }

    public function test_lista_los_tickets_con_su_etiqueta(): void
    {
        $ticket = $this->ticket(Ticket::STATUS_INTERESADO);

        $this->actingAs($this->agente())
            ->getJson('/api/tickets')
            ->assertOk()
            ->assertJsonPath('0.id', $ticket->id)
            ->assertJsonPath('0.status', 'interesado')
            ->assertJsonPath('0.status_label', 'Interesado')
            ->assertJsonPath('0.contact.display_name', 'Cliente Prueba');
    }

    public function test_filtra_por_estado_y_ciudad(): void
    {
        $urgenteCaracas = $this->ticket(Ticket::STATUS_ALTA_PRIORIDAD, Ticket::PRIORITY_ALTA, 'caracas');
        $this->ticket(Ticket::STATUS_NUEVO, Ticket::PRIORITY_MEDIA, 'valencia');

        $agente = $this->agente();

        $this->actingAs($agente)
            ->getJson('/api/tickets?status=alta_prioridad')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $urgenteCaracas->id);

        $this->actingAs($agente)
            ->getJson('/api/tickets?city=valencia')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_cambiar_el_estado_queda_auditado(): void
    {
        $ticket = $this->ticket(Ticket::STATUS_NUEVO);
        $agente = $this->agente();

        $this->actingAs($agente)
            ->patchJson("/api/tickets/{$ticket->id}", ['status' => Ticket::STATUS_RESERVADO])
            ->assertOk();

        $ticket->refresh();
        $this->assertSame(Ticket::STATUS_RESERVADO, $ticket->status);
        // reserved_at lo llena TicketService, no el cliente.
        $this->assertNotNull($ticket->reserved_at, 'reserved_at debe llenarse al reservar.');

        $this->assertDatabaseHas('activity_logs', [
            'causer_id'   => $agente->id,
            'target_type' => Ticket::class,
            'target_id'   => $ticket->id,
            'action'      => 'ticket.status_changed',
        ]);
    }

    public function test_asignar_un_agente_queda_auditado(): void
    {
        $ticket = $this->ticket();
        $agente = $this->agente();

        $this->actingAs($agente)
            ->patchJson("/api/tickets/{$ticket->id}", ['assigned_user_id' => $agente->id])
            ->assertOk();

        $this->assertSame($agente->id, $ticket->fresh()->assigned_user_id);
        $this->assertDatabaseHas('activity_logs', [
            'target_id' => $ticket->id,
            'action'    => 'ticket.assigned',
        ]);
    }

    /**
     * Los estados en inglés son justo el bug que rompía la bandeja: no existen
     * en el enum y deben rechazarse.
     */
    public function test_rechaza_estados_en_ingles(): void
    {
        $ticket = $this->ticket();
        $agente = $this->agente();

        foreach (['interested', 'high_priority', 'following', 'closed'] as $estadoIngles) {
            $this->actingAs($agente)
                ->patchJson("/api/tickets/{$ticket->id}", ['status' => $estadoIngles])
                ->assertStatus(422);
        }

        $this->assertSame(Ticket::STATUS_NUEVO, $ticket->fresh()->status);
    }

    public function test_rechaza_prioridad_y_ciudad_invalidas(): void
    {
        $ticket = $this->ticket();
        $agente = $this->agente();

        $this->actingAs($agente)
            ->patchJson("/api/tickets/{$ticket->id}", ['priority' => 'urgentisima'])
            ->assertStatus(422);

        $this->actingAs($agente)
            ->patchJson("/api/tickets/{$ticket->id}", ['city' => 'madrid'])
            ->assertStatus(422);

        $this->actingAs($agente)
            ->patchJson("/api/tickets/{$ticket->id}", ['assigned_user_id' => 999999])
            ->assertStatus(422);
    }

    /** Actualización parcial: tocar las notas no debe alterar el estado. */
    public function test_actualiza_solo_los_campos_enviados(): void
    {
        $ticket = $this->ticket(Ticket::STATUS_INTERESADO, Ticket::PRIORITY_ALTA);

        $this->actingAs($this->agente())
            ->patchJson("/api/tickets/{$ticket->id}", [
                'notes'           => 'Llamar el lunes',
                'course_interest' => 'Jabones artesanales',
            ])
            ->assertOk();

        $ticket->refresh();
        $this->assertSame('Llamar el lunes', $ticket->notes);
        $this->assertSame('Jabones artesanales', $ticket->course_interest);
        $this->assertSame(Ticket::STATUS_INTERESADO, $ticket->status, 'El estado no debe cambiar.');
        $this->assertSame(Ticket::PRIORITY_ALTA, $ticket->priority, 'La prioridad no debe cambiar.');
    }

    public function test_sincroniza_las_etiquetas(): void
    {
        $ticket = $this->ticket();
        $tagA = Tag::create(['name' => 'Curso velas', 'color' => '#ff0000']);
        $tagB = Tag::create(['name' => 'Mayorista', 'color' => '#00ff00']);

        $this->actingAs($this->agente())
            ->putJson("/api/tickets/{$ticket->id}/tags", ['tag_ids' => [$tagA->id, $tagB->id]])
            ->assertOk()
            ->assertJsonCount(2, 'tags');

        $this->assertCount(2, $ticket->fresh()->tags);

        // Enviar una lista vacía quita todas.
        $this->actingAs($this->agente())
            ->putJson("/api/tickets/{$ticket->id}/tags", ['tag_ids' => []])
            ->assertOk();

        $this->assertCount(0, $ticket->fresh()->tags);
    }

    public function test_el_detalle_incluye_el_historial(): void
    {
        $ticket = $this->ticket();
        $agente = $this->agente();

        $this->actingAs($agente)
            ->patchJson("/api/tickets/{$ticket->id}", ['status' => Ticket::STATUS_INTERESADO])
            ->assertOk();

        $this->actingAs($agente)
            ->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonStructure(['activity' => [['id', 'action', 'metadata', 'created_at']]]);
    }

    /** /tickets/counts no debe resolverse como /tickets/{id}. */
    public function test_counts_no_choca_con_el_route_binding(): void
    {
        $this->ticket();

        $this->actingAs($this->agente())
            ->getJson('/api/tickets/counts')
            ->assertOk()
            ->assertJsonPath('Nuevo', 1);
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->getJson('/api/tickets')->assertStatus(401);
        $this->getJson('/api/tickets/counts')->assertStatus(401);
    }
}
