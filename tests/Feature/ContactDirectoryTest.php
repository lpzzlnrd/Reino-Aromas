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
 * Directorio de clientes.
 *
 * Alimenta /app/clients. Los filtros se aplican en el BACKEND y no en el
 * cliente porque el listado viene paginado: filtrar solo la página actual
 * daría resultados incompletos.
 */
class ContactDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function agente(): User
    {
        return User::factory()->create(['role' => 'administrador', 'is_active' => true]);
    }

    private function contacto(
        string $canal = Contact::CHANNEL_WHATSAPP,
        ?string $ciudad = 'caracas',
        string $nombre = 'Cliente Prueba',
        ?string $telefono = null,
        ?string $handle = null,
    ): Contact {
        return Contact::create([
            'channel'          => $canal,
            'channel_id'       => 'ext-' . uniqid(),
            'display_name'     => $nombre,
            'city'             => $ciudad,
            'phone'            => $telefono,
            'instagram_handle' => $handle,
            'first_seen_at'    => now()->subDays(3),
            'last_seen_at'     => now(),
        ]);
    }

    public function test_lista_los_contactos_paginados(): void
    {
        $this->contacto();
        $this->contacto();

        $this->actingAs($this->agente())
            ->getJson('/api/contacts')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'display_name', 'profile_picture_url', 'channel',
                    'channel_id', 'city', 'phone', 'instagram_handle',
                    'first_seen_at', 'last_seen_at',
                ]],
                'meta' => ['current_page', 'last_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 2);
    }

    public function test_respeta_per_page_y_pagina(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->contacto();
        }

        $agente = $this->agente();

        $this->actingAs($agente)
            ->getJson('/api/contacts?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5);

        $this->actingAs($agente)
            ->getJson('/api/contacts?per_page=2&page=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 3);
    }

    public function test_filtra_por_canal(): void
    {
        $this->contacto(Contact::CHANNEL_WHATSAPP);
        $this->contacto(Contact::CHANNEL_INSTAGRAM);
        $this->contacto(Contact::CHANNEL_INSTAGRAM);

        $this->actingAs($this->agente())
            ->getJson('/api/contacts?channel=instagram')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.channel', 'instagram');
    }

    public function test_filtra_por_ciudad(): void
    {
        $this->contacto(ciudad: 'caracas');
        $this->contacto(ciudad: 'valencia');

        $this->actingAs($this->agente())
            ->getJson('/api/contacts?city=valencia')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.city', 'valencia');
    }

    /** La búsqueda cubre nombre, teléfono y handle de Instagram. */
    public function test_busca_por_nombre_telefono_y_handle(): void
    {
        $this->contacto(nombre: 'Maria Fernanda Perez', telefono: '+584121112233');
        $this->contacto(nombre: 'Jose Rodriguez', telefono: '+584149998877');
        $this->contacto(
            canal: Contact::CHANNEL_INSTAGRAM,
            nombre: 'Ana Lopez',
            handle: 'ana.velas',
        );

        $agente = $this->agente();

        // Por nombre parcial
        $this->actingAs($agente)
            ->getJson('/api/contacts?search=Fernanda')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_name', 'Maria Fernanda Perez');

        // Por fragmento de teléfono
        $this->actingAs($agente)
            ->getJson('/api/contacts?search=9998877')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_name', 'Jose Rodriguez');

        // Por handle de Instagram
        $this->actingAs($agente)
            ->getJson('/api/contacts?search=ana.velas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_name', 'Ana Lopez');
    }

    public function test_los_filtros_se_combinan(): void
    {
        $this->contacto(Contact::CHANNEL_WHATSAPP, 'caracas', 'Maria Caracas');
        $this->contacto(Contact::CHANNEL_WHATSAPP, 'valencia', 'Maria Valencia');
        $this->contacto(Contact::CHANNEL_INSTAGRAM, 'caracas', 'Maria Instagram');

        $this->actingAs($this->agente())
            ->getJson('/api/contacts?channel=whatsapp&city=caracas&search=Maria')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_name', 'Maria Caracas');
    }

    public function test_una_busqueda_sin_coincidencias_devuelve_vacio(): void
    {
        $this->contacto(nombre: 'Maria Perez');

        $this->actingAs($this->agente())
            ->getJson('/api/contacts?search=NoExisteEsteCliente')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    /** El detalle incluye el historial de conversaciones con su ticket. */
    public function test_el_detalle_incluye_el_historial(): void
    {
        $contact = $this->contacto();

        $conversation = $contact->conversations()->create([
            'status'            => Conversation::STATUS_OPEN,
            'within_24h_window' => true,
            'last_message_at'   => now(),
        ]);

        Ticket::create([
            'conversation_id' => $conversation->id,
            'status'          => Ticket::STATUS_RESERVADO,
            'priority'        => Ticket::PRIORITY_ALTA,
            'city'            => 'caracas',
        ]);

        $this->actingAs($this->agente())
            ->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('display_name', 'Cliente Prueba')
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.status', 'open')
            // La etiqueta del ticket es la que pinta el badge de la ficha.
            ->assertJsonPath('conversations.0.ticket.status_label', 'Reservado');
    }

    public function test_un_contacto_sin_conversaciones_devuelve_lista_vacia(): void
    {
        $contact = $this->contacto();

        $this->actingAs($this->agente())
            ->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonCount(0, 'conversations');
    }

    public function test_actualiza_nombre_ciudad_y_telefono(): void
    {
        $contact = $this->contacto(ciudad: 'caracas', nombre: 'Nombre Viejo');

        $this->actingAs($this->agente())
            ->patchJson("/api/contacts/{$contact->id}", [
                'display_name' => 'Nombre Corregido',
                'city'         => 'maracay',
                'phone'        => '+584125556677',
            ])
            ->assertOk();

        $contact->refresh();
        $this->assertSame('Nombre Corregido', $contact->display_name);
        $this->assertSame('maracay', $contact->city);
        $this->assertSame('+584125556677', $contact->phone);
    }

    /** La ficha permite dejar la ciudad vacía. */
    public function test_permite_limpiar_la_ciudad(): void
    {
        $contact = $this->contacto(ciudad: 'caracas');

        $this->actingAs($this->agente())
            ->patchJson("/api/contacts/{$contact->id}", ['city' => null])
            ->assertOk();

        $this->assertNull($contact->fresh()->city);
    }

    /**
     * El canal y el channel_id NO se pueden cambiar: los asigna Meta y
     * alterarlos rompería la correlación de los webhooks entrantes.
     */
    public function test_no_permite_cambiar_el_canal_ni_el_identificador(): void
    {
        $contact = $this->contacto(Contact::CHANNEL_WHATSAPP);
        $canalOriginal = $contact->channel;
        $idOriginal = $contact->channel_id;

        $this->actingAs($this->agente())
            ->patchJson("/api/contacts/{$contact->id}", [
                'channel'    => 'facebook',
                'channel_id' => 'otro-id-cualquiera',
            ])
            ->assertOk();

        $contact->refresh();
        $this->assertSame($canalOriginal, $contact->channel);
        $this->assertSame($idOriginal, $contact->channel_id);
    }

    public function test_rechaza_una_ciudad_fuera_del_enum(): void
    {
        $contact = $this->contacto();

        $this->actingAs($this->agente())
            ->patchJson("/api/contacts/{$contact->id}", ['city' => 'madrid'])
            ->assertStatus(422);
    }

    /** No hay POST: los contactos nacen de los webhooks de Meta. */
    public function test_no_se_pueden_crear_contactos_a_mano(): void
    {
        $this->actingAs($this->agente())
            ->postJson('/api/contacts', [
                'channel'      => 'whatsapp',
                'channel_id'   => 'inventado',
                'display_name' => 'Cliente Inventado',
            ])
            ->assertStatus(405);

        $this->assertDatabaseMissing('contacts', ['display_name' => 'Cliente Inventado']);
    }

    public function test_sin_sesion_responde_401(): void
    {
        $contact = $this->contacto();

        $this->getJson('/api/contacts')->assertStatus(401);
        $this->getJson("/api/contacts/{$contact->id}")->assertStatus(401);
        $this->patchJson("/api/contacts/{$contact->id}", ['display_name' => 'X'])->assertStatus(401);
    }
}
