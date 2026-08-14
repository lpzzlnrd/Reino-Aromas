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
 * Catálogo de etiquetas.
 *
 * GET /api/tags faltaba: PUT /api/tickets/{id}/tags ya asignaba etiquetas a un
 * ticket pero recibe tag_ids, y no había endpoint que dijera qué etiquetas
 * existen. El selector del panel del chat no se podía terminar.
 */
class TagCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function agente(): User
    {
        return User::factory()->create([
            'role'      => User::ROLE_ADMINISTRADOR,
            'is_active' => true,
        ]);
    }

    private function ticket(): Ticket
    {
        $contact = Contact::create([
            'channel'       => Contact::CHANNEL_WHATSAPP,
            'channel_id'    => 'ext-' . uniqid(),
            'display_name'  => 'Cliente Prueba',
            'city'          => 'caracas',
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
            'status'          => Ticket::STATUS_NUEVO,
            'priority'        => Ticket::PRIORITY_MEDIA,
            'city'            => 'caracas',
        ]);
    }

    public function test_devuelve_el_catalogo_ordenado_por_nombre(): void
    {
        Tag::create(['name' => 'VIP', 'color' => '#C9922A']);
        Tag::create(['name' => 'Ancla', 'color' => '#10B981']);
        Tag::create(['name' => 'Media', 'color' => '#3B82F6']);

        $tags = $this->actingAs($this->agente())
            ->getJson('/api/tags')
            ->assertOk()
            ->json();

        $this->assertSame(['Ancla', 'Media', 'VIP'], array_column($tags, 'name'));

        // La forma que consume el selector del chat.
        foreach (['id', 'name', 'color', 'tickets'] as $campo) {
            $this->assertArrayHasKey($campo, $tags[0]);
        }
    }

    /** El conteo permite ordenar el selector por las más usadas. */
    public function test_cuenta_los_tickets_de_cada_etiqueta(): void
    {
        $usada = Tag::create(['name' => 'Usada', 'color' => '#EF4444']);
        Tag::create(['name' => 'Sin usar', 'color' => '#6B7280']);

        $this->ticket()->tags()->attach($usada->id);
        $this->ticket()->tags()->attach($usada->id);

        $porNombre = collect(
            $this->actingAs($this->agente())->getJson('/api/tags')->json()
        )->keyBy('name');

        $this->assertSame(2, $porNombre['Usada']['tickets']);
        $this->assertSame(0, $porNombre['Sin usar']['tickets'], 'Las no usadas vienen en cero, no ausentes.');
    }

    public function test_sin_etiquetas_devuelve_lista_vacia(): void
    {
        $this->actingAs($this->agente())
            ->getJson('/api/tags')
            ->assertOk()
            ->assertExactJson([]);
    }

    /**
     * El par completo: se lee el catálogo y se asignan esos ids al ticket.
     * Es el flujo que hace el panel del chat.
     */
    public function test_los_ids_del_catalogo_sirven_para_asignar(): void
    {
        Tag::create(['name' => 'Interesado velas', 'color' => '#F59E0B']);
        Tag::create(['name' => 'VIP', 'color' => '#C9922A']);

        $agente = $this->agente();
        $ticket = $this->ticket();

        $ids = array_column(
            $this->actingAs($agente)->getJson('/api/tags')->json(),
            'id',
        );

        $this->actingAs($agente)
            ->putJson("/api/tickets/{$ticket->id}/tags", ['tag_ids' => $ids])
            ->assertOk()
            ->assertJsonCount(2, 'tags');

        $this->assertCount(2, $ticket->refresh()->tags);
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->getJson('/api/tags')->assertStatus(401);
    }
}
