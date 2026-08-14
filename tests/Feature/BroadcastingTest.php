<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\MessageCreated;
use App\Events\MessageStatusChanged;
use App\Events\TicketUpdated;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OutboundMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Difusión en tiempo real (Semana 4).
 *
 * Los eventos se enganchan en los MODELOS y no en los servicios porque los
 * mensajes nacen en tres sitios distintos y el estado lo mueven tres Jobs. Los
 * tests de abajo comprueban justamente eso: que da igual por qué camino se
 * cree o actualice el mensaje, el evento sale.
 */
class BroadcastingTest extends TestCase
{
    use RefreshDatabase;

    private function agente(bool $activo = true): User
    {
        return User::factory()->create([
            'role'      => User::ROLE_ADMINISTRADOR,
            'is_active' => $activo,
        ]);
    }

    private function conversacion(string $canal = Contact::CHANNEL_WHATSAPP): Conversation
    {
        $contact = Contact::create([
            'channel'       => $canal,
            'channel_id'    => 'ext-' . uniqid(),
            'display_name'  => 'Cliente Prueba',
            'phone'         => '584121234567',
            'city'          => 'caracas',
            'first_seen_at' => now(),
            'last_seen_at'  => now(),
        ]);

        return $contact->conversations()->create([
            'status'            => Conversation::STATUS_OPEN,
            'within_24h_window' => true,
            'last_message_at'   => now(),
        ]);
    }

    /*
    |-------------------------------------------------------------------------
    | MessageCreated
    |-------------------------------------------------------------------------
    */

    public function test_crear_un_mensaje_difunde_el_evento(): void
    {
        Event::fake([MessageCreated::class]);

        $conversation = $this->conversacion();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => Message::DIRECTION_INBOUND,
            'channel'         => 'whatsapp',
            'type'            => Message::TYPE_TEXT,
            'body'            => 'Hola, me interesa el curso',
            'status'          => Message::STATUS_DELIVERED,
        ]);

        Event::assertDispatched(
            MessageCreated::class,
            fn (MessageCreated $e): bool => $e->message->id === $message->id,
        );
    }

    /**
     * El camino real del envío: OutboundMessageService también debe difundir,
     * sin que el servicio sepa nada de broadcasting.
     */
    public function test_el_envio_por_el_servicio_tambien_difunde(): void
    {
        Queue::fake();
        Event::fake([MessageCreated::class]);

        $conversation = $this->conversacion();

        app(OutboundMessageService::class)->queueTextMessage(
            conversation: $conversation,
            body: 'Respuesta del agente',
            sender: $this->agente(),
        );

        Event::assertDispatched(MessageCreated::class);
    }

    /** Va al canal de la conversación y al de la bandeja. */
    public function test_el_mensaje_se_difunde_a_la_conversacion_y_a_la_bandeja(): void
    {
        $conversation = $this->conversacion();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => Message::DIRECTION_INBOUND,
            'channel'         => 'whatsapp',
            'type'            => Message::TYPE_TEXT,
            'body'            => 'Hola',
            'status'          => Message::STATUS_DELIVERED,
        ]);

        $canales = array_map(
            fn ($canal): string => (string) $canal,
            (new MessageCreated($message))->broadcastOn(),
        );

        $this->assertContains("private-conversations.{$conversation->id}", $canales);
        $this->assertContains('private-inbox', $canales);
    }

    /**
     * El payload debe tener la misma forma que serializarMensaje() del
     * controller: el Vue ya tiene ese tipo y así empuja el mensaje al historial
     * sin transformar nada.
     */
    public function test_el_payload_coincide_con_el_del_endpoint(): void
    {
        $conversation = $this->conversacion();
        $agente = $this->agente();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_user_id'  => $agente->id,
            'direction'       => Message::DIRECTION_OUTBOUND,
            'channel'         => 'whatsapp',
            'type'            => Message::TYPE_TEXT,
            'body'            => 'Respuesta',
            'status'          => Message::STATUS_PENDING,
            // meta_payload NO debe viajar: es el JSON crudo de Meta y puede pesar.
            'meta_payload'    => ['ruido' => str_repeat('x', 500)],
        ]);

        $payload = (new MessageCreated($message))->broadcastWith();

        $this->assertSame($conversation->id, $payload['conversation_id']);
        $this->assertArrayNotHasKey('meta_payload', $payload['message']);

        foreach ([
            'id', 'direction', 'channel', 'type', 'body', 'media_url', 'status',
            'failed_reason', 'sender', 'sent_at', 'delivered_at', 'read_at', 'created_at',
        ] as $campo) {
            $this->assertArrayHasKey($campo, $payload['message'], "Falta {$campo}.");
        }

        // El sender viene resuelto, no como id suelto.
        $this->assertSame($agente->name, $payload['message']['sender']['name']);
    }

    /*
    |-------------------------------------------------------------------------
    | MessageStatusChanged — lo que le faltaba a la bandeja
    |-------------------------------------------------------------------------
    */

    /**
     * El caso que motivó el evento: el mensaje nace pending y el Job lo pasa a
     * sent. Sin difundir, el agente no sabía si su mensaje había salido.
     */
    public function test_pasar_de_pending_a_sent_difunde_el_cambio(): void
    {
        $conversation = $this->conversacion();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => Message::DIRECTION_OUTBOUND,
            'channel'         => 'whatsapp',
            'type'            => Message::TYPE_TEXT,
            'body'            => 'Respuesta',
            'status'          => Message::STATUS_PENDING,
        ]);

        Event::fake([MessageStatusChanged::class]);

        // Igual que lo hace SendWhatsAppMessageJob.
        $message->forceFill([
            'status'  => Message::STATUS_SENT,
            'sent_at' => now(),
        ])->save();

        Event::assertDispatched(
            MessageStatusChanged::class,
            fn (MessageStatusChanged $e): bool => $e->message->status === Message::STATUS_SENT,
        );
    }

    public function test_un_fallo_difunde_el_motivo(): void
    {
        $conversation = $this->conversacion();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => Message::DIRECTION_OUTBOUND,
            'channel'         => 'whatsapp',
            'type'            => Message::TYPE_TEXT,
            'body'            => 'Respuesta',
            'status'          => Message::STATUS_PENDING,
        ]);

        $message->forceFill([
            'status'        => Message::STATUS_FAILED,
            'failed_reason' => 'Fuera de la ventana de 24h',
        ])->save();

        $payload = (new MessageStatusChanged($message->refresh()))->broadcastWith();

        $this->assertSame(Message::STATUS_FAILED, $payload['status']);
        $this->assertSame('Fuera de la ventana de 24h', $payload['failed_reason']);
        // El front ya tiene la burbuja: no hace falta reenviar el cuerpo.
        $this->assertArrayNotHasKey('body', $payload);
    }

    /**
     * Los Jobs escriben external_id y meta_payload en el mismo save() que el
     * estado. Si solo cambia eso, no debe difundirse nada.
     */
    public function test_un_cambio_irrelevante_no_difunde(): void
    {
        $conversation = $this->conversacion();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => Message::DIRECTION_OUTBOUND,
            'channel'         => 'whatsapp',
            'type'            => Message::TYPE_TEXT,
            'body'            => 'Respuesta',
            'status'          => Message::STATUS_SENT,
        ]);

        Event::fake([MessageStatusChanged::class]);

        $message->forceFill(['external_id' => 'wamid.abc123'])->save();

        Event::assertNotDispatched(MessageStatusChanged::class);
    }

    /*
    |-------------------------------------------------------------------------
    | TicketUpdated — la base del Kanban en vivo
    |-------------------------------------------------------------------------
    */

    public function test_mover_un_ticket_de_estado_difunde_con_el_valor_anterior(): void
    {
        $conversation = $this->conversacion();
        $ticket = Ticket::create([
            'conversation_id' => $conversation->id,
            'status'          => Ticket::STATUS_INTERESADO,
            'priority'        => Ticket::PRIORITY_MEDIA,
            'city'            => 'caracas',
        ]);

        Event::fake([TicketUpdated::class]);

        $ticket->update(['status' => Ticket::STATUS_RESERVADO]);

        Event::assertDispatched(TicketUpdated::class, function (TicketUpdated $e): bool {
            // El valor anterior es lo que le dice al Kanban de qué columna
            // sacar la tarjeta.
            return $e->ticket->status === Ticket::STATUS_RESERVADO
                && ($e->cambios['status'] ?? null) === Ticket::STATUS_INTERESADO;
        });
    }

    /** Guardar una nota no debe mover ninguna columna del tablero. */
    public function test_editar_una_nota_no_difunde(): void
    {
        $conversation = $this->conversacion();
        $ticket = Ticket::create([
            'conversation_id' => $conversation->id,
            'status'          => Ticket::STATUS_NUEVO,
            'priority'        => Ticket::PRIORITY_MEDIA,
            'city'            => 'caracas',
        ]);

        Event::fake([TicketUpdated::class]);

        $ticket->update(['notes' => 'Llamar el lunes']);

        Event::assertNotDispatched(TicketUpdated::class);
    }

    public function test_el_ticket_se_difunde_al_tablero_y_a_su_chat(): void
    {
        $conversation = $this->conversacion();
        $ticket = Ticket::create([
            'conversation_id' => $conversation->id,
            'status'          => Ticket::STATUS_NUEVO,
            'priority'        => Ticket::PRIORITY_MEDIA,
            'city'            => 'caracas',
        ]);

        $canales = array_map(
            fn ($canal): string => (string) $canal,
            (new TicketUpdated($ticket))->broadcastOn(),
        );

        $this->assertContains('private-tickets', $canales);
        $this->assertContains("private-conversations.{$conversation->id}", $canales);
    }

    /** El payload lleva status_label, que es lo que pinta el badge. */
    public function test_el_payload_del_ticket_trae_la_etiqueta_del_front(): void
    {
        $conversation = $this->conversacion();
        $ticket = Ticket::create([
            'conversation_id' => $conversation->id,
            'status'          => Ticket::STATUS_ALTA_PRIORIDAD,
            'priority'        => Ticket::PRIORITY_ALTA,
            'city'            => 'caracas',
        ]);

        $payload = (new TicketUpdated($ticket))->broadcastWith();

        $this->assertSame('alta_prioridad', $payload['ticket']['status']);
        $this->assertSame('Urgente', $payload['ticket']['status_label']);
        $this->assertArrayHasKey('tags', $payload['ticket']);
    }

    /*
    |-------------------------------------------------------------------------
    | Nombres de evento: el contrato con el Vue
    |-------------------------------------------------------------------------
    */

    /**
     * El front escucha nombres cortos, no el FQCN. Si alguien renombra las
     * clases, este test avisa antes de que el cliente deje de recibir nada.
     */
    public function test_los_nombres_de_evento_son_los_que_escucha_el_vue(): void
    {
        $conversation = $this->conversacion();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => Message::DIRECTION_INBOUND,
            'channel'         => 'whatsapp',
            'type'            => Message::TYPE_TEXT,
            'body'            => 'Hola',
            'status'          => Message::STATUS_DELIVERED,
        ]);

        $ticket = Ticket::create([
            'conversation_id' => $conversation->id,
            'status'          => Ticket::STATUS_NUEVO,
            'priority'        => Ticket::PRIORITY_MEDIA,
        ]);

        $this->assertSame('message.created', (new MessageCreated($message))->broadcastAs());
        $this->assertSame('message.status', (new MessageStatusChanged($message))->broadcastAs());
        $this->assertSame('ticket.updated', (new TicketUpdated($ticket))->broadcastAs());
    }

    /*
    |-------------------------------------------------------------------------
    | Autorización de canales
    |-------------------------------------------------------------------------
    */

    public function test_un_agente_activo_puede_escuchar_una_conversacion(): void
    {
        $conversation = $this->conversacion();

        $this->actingAs($this->agente())
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-conversations.{$conversation->id}",
            ])
            ->assertOk();
    }

    /**
     * Un usuario desactivado no debe poder escuchar nada.
     *
     * Se comprueba el callback del canal directamente y no vía HTTP: el
     * middleware EnsureUserIsActive del grupo `web` lo echa antes de llegar al
     * callback, así que por HTTP no se distingue "inactivo" de "sin permiso".
     * Lo que se valida acá es que la regla del canal también lo rechace — si
     * mañana se quita ese middleware, el canal sigue cerrado.
     */
    public function test_un_agente_desactivado_no_puede_escuchar(): void
    {
        $conversation = $this->conversacion();
        $inactivo = $this->agente(activo: false);

        $this->assertFalse(
            $this->autorizaCanal("conversations.{$conversation->id}", $inactivo),
            'Un agente desactivado no debe poder suscribirse a una conversación.',
        );

        foreach (['inbox', 'tickets'] as $canal) {
            $this->assertFalse(
                $this->autorizaCanal($canal, $inactivo),
                "Un agente desactivado no debe poder suscribirse a {$canal}.",
            );
        }
    }

    /**
     * No se puede suscribir a una conversación inexistente: si no, un cliente
     * podría quedarse escuchando un canal que mañana existirá.
     */
    public function test_no_se_puede_escuchar_una_conversacion_que_no_existe(): void
    {
        $this->assertFalse(
            $this->autorizaCanal('conversations.999999', $this->agente()),
        );
    }

    /**
     * Sin usuario ningún canal autoriza.
     *
     * Se comprueban las reglas directamente y no por HTTP: dentro de PHPUnit el
     * grupo `web` monta una sesión de prueba, así que una petición "sin sesión"
     * no se puede simular de forma fiable con postJson. Verificado a mano
     * levantando el kernel: sin cookie la ruta responde 403.
     */
    public function test_sin_usuario_no_se_puede_autorizar_ningun_canal(): void
    {
        $conversation = $this->conversacion();

        foreach (['inbox', 'tickets', "conversations.{$conversation->id}"] as $canal) {
            $this->assertFalse(
                $this->autorizaCanal($canal, null),
                "El canal {$canal} no debe autorizar sin usuario.",
            );
        }
    }

    /**
     * Ejecuta la regla de autorización de un canal para un usuario dado.
     *
     * Evita el ida y vuelta HTTP, que en tests arrastra la sesión de un
     * actingAs anterior y da falsos positivos.
     */
    private function autorizaCanal(string $canal, ?User $user): bool
    {
        $request = \Illuminate\Http\Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => "private-{$canal}",
            'socket_id'    => '1234.5678',
        ]);

        $request->setUserResolver(fn () => $user);

        try {
            return (bool) app(\Illuminate\Contracts\Broadcasting\Broadcaster::class)
                ->auth($request);
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
            // Sin usuario el broadcaster lanza 403 en vez de devolver false.
            // Para lo que se está comprobando, ambas cosas son "no autoriza".
            return false;
        }
    }

    public function test_los_canales_compartidos_autorizan_a_cualquier_agente_activo(): void
    {
        $agente = $this->agente();

        foreach (['private-inbox', 'private-tickets'] as $canal) {
            $this->actingAs($agente)
                ->postJson('/broadcasting/auth', [
                    'socket_id' => '1234.5678',
                    'channel_name' => $canal,
                ])
                ->assertOk();
        }
    }
}
