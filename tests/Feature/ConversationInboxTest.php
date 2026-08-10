<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresión: la bandeja mostraba TODOS los chats como "Nuevo".
 *
 * El closure de GET /api/meta/chats hacía un match contra los estados en
 * inglés ('interested', 'high_priority', 'following'), pero el enum de la
 * columna tickets.status está en español ('interesado', 'alta_prioridad'…) y
 * TicketService escribe esos valores. Ningún caso coincidía nunca, así que
 * todos los chats caían en el default 'Nuevo' — el agente no distinguía un
 * lead urgente de uno recién llegado.
 *
 * Fix: la traducción vive en Ticket::statusLabels(), junto a las constantes.
 */
class ConversationInboxTest extends TestCase
{
    use RefreshDatabase;

    private function agente(): User
    {
        return User::factory()->create(['role' => 'administrador', 'is_active' => true]);
    }

    private function chatConTicket(string $estado, string $canal = Contact::CHANNEL_WHATSAPP): Conversation
    {
        $contact = Contact::create([
            'channel'       => $canal,
            'channel_id'    => 'ext-' . $canal . '-' . $estado,
            'display_name'  => 'Maria Fernanda Perez',
            'city'          => 'valencia',
            'phone'         => '+584121234567',
            'first_seen_at' => now(),
            'last_seen_at'  => now(),
        ]);

        $conversation = $contact->conversations()->create([
            'status'            => Conversation::STATUS_OPEN,
            'within_24h_window' => true,
            'last_message_at'   => now(),
        ]);

        Ticket::create([
            'conversation_id' => $conversation->id,
            'status'          => $estado,
            'priority'        => Ticket::PRIORITY_MEDIA,
            'city'            => 'valencia',
        ]);

        return $conversation;
    }

    /**
     * Cada estado de la BD debe llegar al front con su etiqueta del enum
     * CaseStatus.ts.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function estados(): array
    {
        return [
            'nuevo'          => [Ticket::STATUS_NUEVO, 'Nuevo'],
            'interesado'     => [Ticket::STATUS_INTERESADO, 'Interesado'],
            'alta_prioridad' => [Ticket::STATUS_ALTA_PRIORIDAD, 'Urgente'],
            'en_seguimiento' => [Ticket::STATUS_EN_SEGUIMIENTO, 'En seguimiento'],
            'reservado'      => [Ticket::STATUS_RESERVADO, 'Reservado'],
            'cerrado'        => [Ticket::STATUS_CERRADO, 'Cerrado'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('estados')]
    public function test_el_estado_del_ticket_llega_traducido_a_la_bandeja(string $estado, string $etiqueta): void
    {
        $conversation = $this->chatConTicket($estado);

        $this->actingAs($this->agente())
            ->getJson('/api/meta/chats?status=all')
            ->assertOk()
            ->assertJsonPath('0.id', $conversation->id)
            ->assertJsonPath('0.case_status', $etiqueta);
    }

    /** Sin ticket el chat es 'Nuevo': es un lead que nadie ha calificado. */
    public function test_un_chat_sin_ticket_es_nuevo(): void
    {
        $contact = Contact::create([
            'channel'       => Contact::CHANNEL_INSTAGRAM,
            'channel_id'    => 'ext-ig-sin-ticket',
            'display_name'  => 'Sin ticket',
            'first_seen_at' => now(),
            'last_seen_at'  => now(),
        ]);
        $conversation = $contact->conversations()->create([
            'status'            => Conversation::STATUS_OPEN,
            'within_24h_window' => true,
            'last_message_at'   => now(),
        ]);

        $this->actingAs($this->agente())
            ->getJson('/api/meta/chats')
            ->assertOk()
            ->assertJsonPath('0.id', $conversation->id)
            ->assertJsonPath('0.case_status', 'Nuevo');
    }

    /** El contrato con el tipo MetaApiChat de useMetaData.ts. */
    public function test_la_respuesta_respeta_el_contrato_del_front(): void
    {
        $conversation = $this->chatConTicket(Ticket::STATUS_INTERESADO);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => Message::DIRECTION_INBOUND,
            'channel'         => Contact::CHANNEL_WHATSAPP,
            'type'            => Message::TYPE_TEXT,
            'body'            => 'Hola, quiero info',
            'status'          => Message::STATUS_DELIVERED,
            'created_at'      => now(),
        ]);

        $this->actingAs($this->agente())
            ->getJson('/api/meta/chats')
            ->assertOk()
            ->assertJsonStructure([
                '*' => [
                    'id', 'contact_name', 'contact_avatar', 'last_message',
                    'message_time', 'location', 'case_status', 'channel',
                ],
            ])
            ->assertJsonPath('0.contact_name', 'Maria Fernanda Perez')
            ->assertJsonPath('0.last_message', 'Hola, quiero info')
            ->assertJsonPath('0.location', 'valencia')
            ->assertJsonPath('0.channel', 'whatsapp');
    }

    public function test_filtra_por_canal(): void
    {
        $wa = $this->chatConTicket(Ticket::STATUS_NUEVO, Contact::CHANNEL_WHATSAPP);
        $this->chatConTicket(Ticket::STATUS_NUEVO, Contact::CHANNEL_FACEBOOK);

        $this->actingAs($this->agente())
            ->getJson('/api/meta/chats?channel=whatsapp')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $wa->id);
    }

    public function test_filtra_por_estado_del_ticket(): void
    {
        $urgente = $this->chatConTicket(Ticket::STATUS_ALTA_PRIORIDAD, Contact::CHANNEL_WHATSAPP);
        $this->chatConTicket(Ticket::STATUS_NUEVO, Contact::CHANNEL_INSTAGRAM);

        $this->actingAs($this->agente())
            ->getJson('/api/meta/chats?case=alta_prioridad')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $urgente->id);
    }

    public function test_busca_por_nombre_del_contacto(): void
    {
        $conversation = $this->chatConTicket(Ticket::STATUS_NUEVO);

        $this->actingAs($this->agente())
            ->getJson('/api/meta/chats?search=Fernanda')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $conversation->id);

        $this->actingAs($this->agente())
            ->getJson('/api/meta/chats?search=NoExiste')
            ->assertOk()
            ->assertJsonCount(0);
    }

    /** Por defecto la bandeja solo muestra chats abiertos. */
    public function test_por_defecto_solo_muestra_abiertos(): void
    {
        $cerrada = $this->chatConTicket(Ticket::STATUS_CERRADO);
        $cerrada->update(['status' => Conversation::STATUS_CLOSED]);

        $this->actingAs($this->agente())
            ->getJson('/api/meta/chats')
            ->assertOk()
            ->assertJsonCount(0);

        $this->actingAs($this->agente())
            ->getJson('/api/meta/chats?status=all')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_el_detalle_trae_contacto_ticket_y_mensajes(): void
    {
        $conversation = $this->chatConTicket(Ticket::STATUS_EN_SEGUIMIENTO);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => Message::DIRECTION_INBOUND,
            'channel'         => Contact::CHANNEL_WHATSAPP,
            'type'            => Message::TYPE_TEXT,
            'body'            => 'Primer mensaje',
            'status'          => Message::STATUS_DELIVERED,
            'created_at'      => now(),
        ]);

        $this->actingAs($this->agente())
            ->getJson("/api/meta/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('contact.display_name', 'Maria Fernanda Perez')
            ->assertJsonPath('ticket.status', 'en_seguimiento')
            // El front necesita ambos: el crudo para los <select>, la etiqueta
            // para el badge de color.
            ->assertJsonPath('ticket.status_label', 'En seguimiento')
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'Primer mensaje');
    }

    /**
     * Cerrar el chat no cierra el ticket: son ciclos de vida distintos, el
     * ticket puede seguir en seguimiento después de terminar la conversación.
     */
    public function test_cerrar_la_conversacion_no_cierra_el_ticket(): void
    {
        $conversation = $this->chatConTicket(Ticket::STATUS_EN_SEGUIMIENTO);

        $this->actingAs($this->agente())
            ->patchJson("/api/meta/conversations/{$conversation->id}/close")
            ->assertOk();

        $this->assertSame(Conversation::STATUS_CLOSED, $conversation->fresh()->status);
        $this->assertSame(
            Ticket::STATUS_EN_SEGUIMIENTO,
            $conversation->fresh()->ticket->status,
            'El ticket no debe cerrarse junto con el chat.'
        );

        $this->actingAs($this->agente())
            ->patchJson("/api/meta/conversations/{$conversation->id}/reopen")
            ->assertOk();

        $this->assertSame(Conversation::STATUS_OPEN, $conversation->fresh()->status);
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->getJson('/api/meta/chats')->assertStatus(401);
    }
}
