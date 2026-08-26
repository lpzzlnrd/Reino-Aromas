<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendWhatsAppFlowJob;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\Meta\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Coexistencia: el negocio sigue atendiendo desde la app del teléfono y el CRM
 * ve las dos mitades de la conversación.
 *
 * Antes, processWebhookPayload() descartaba todo change cuyo field no fuera
 * exactamente 'messages'. Con coexistencia activa eso deja fuera:
 *
 *   - smb_message_echoes: lo que el negocio responde DESDE EL TELÉFONO. La
 *     bandeja mostraba la pregunta del cliente pero no la respuesta, así que un
 *     agente del CRM volvía a contestar algo ya atendido.
 *   - history: los 6 meses de chats previos que Meta sincroniza al vincular.
 *
 * Los dos casos comparten una regla que estos tests fijan: NO crean tickets ni
 * disparan el Flow de bienvenida. Son conversaciones que el negocio ya atendió;
 * crear tickets llenaría el Kanban de trabajo resuelto y el Flow le llegaría a
 * clientes viejos como si fueran leads nuevos.
 */
class WhatsAppCoexistenceTest extends TestCase
{
    use RefreshDatabase;

    private const NEGOCIO = '584244549563';
    private const CLIENTE = '584121234567';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function servicio(): WhatsAppService
    {
        return app(WhatsAppService::class);
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function payload(string $field, array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'id'      => '227976370407025',
                'changes' => [['field' => $field, 'value' => $value]],
            ]],
        ];
    }

    public function test_guarda_como_saliente_un_mensaje_mandado_desde_el_telefono(): void
    {
        $this->servicio()->processWebhookPayload($this->payload('smb_message_echoes', [
            'messaging_product' => 'whatsapp',
            'metadata'          => ['phone_number_id' => '199301559942631'],
            'message_echoes'    => [[
                'from'         => self::NEGOCIO,
                'recipient_id' => self::CLIENTE,
                'id'           => 'wamid.ECO_UNO',
                'timestamp'    => '1756200000',
                'type'         => 'text',
                'text'         => ['body' => 'Te confirmo el pedido, sale mañana.'],
            ]],
        ]));

        $mensaje = Message::where('external_id', 'wamid.ECO_UNO')->first();

        $this->assertNotNull($mensaje, 'El eco del teléfono debe quedar guardado.');
        $this->assertSame('outbound', $mensaje->direction);
        $this->assertSame('whatsapp', $mensaje->channel);
        $this->assertSame('Te confirmo el pedido, sale mañana.', $mensaje->body);

        // sender_user_id null es la marca de "salió del negocio, pero no lo
        // escribió nadie del CRM". Atribuirlo a un usuario sería inventar.
        $this->assertNull($mensaje->sender_user_id);
    }

    public function test_el_eco_resuelve_el_contacto_por_el_destinatario_no_por_el_remitente(): void
    {
        $this->servicio()->processWebhookPayload($this->payload('smb_message_echoes', [
            'message_echoes' => [[
                'from'         => self::NEGOCIO,
                'recipient_id' => self::CLIENTE,
                'id'           => 'wamid.ECO_DOS',
                'type'         => 'text',
                'text'         => ['body' => 'Hola'],
            ]],
        ]));

        // En un eco el `from` es el negocio: si el contacto se resolviera por
        // ahí, el CRM crearía un contacto con el número del propio negocio y le
        // colgaría encima las respuestas de todos los clientes.
        $this->assertDatabaseHas('contacts', ['channel_id' => self::CLIENTE]);
        $this->assertDatabaseMissing('contacts', ['channel_id' => self::NEGOCIO]);
    }

    public function test_el_eco_no_crea_ticket_ni_dispara_el_flow_de_bienvenida(): void
    {
        $this->servicio()->processWebhookPayload($this->payload('smb_message_echoes', [
            'message_echoes' => [[
                'from'         => self::NEGOCIO,
                'recipient_id' => self::CLIENTE,
                'id'           => 'wamid.ECO_TRES',
                'type'         => 'text',
                'text'         => ['body' => 'Buenas'],
            ]],
        ]));

        $this->assertSame(0, Ticket::count(), 'Un eco no abre trabajo nuevo: el negocio ya está respondiendo.');
        Queue::assertNotPushed(SendWhatsAppFlowJob::class);
    }

    public function test_no_duplica_un_eco_que_meta_reintenta(): void
    {
        $payload = $this->payload('smb_message_echoes', [
            'message_echoes' => [[
                'from'         => self::NEGOCIO,
                'recipient_id' => self::CLIENTE,
                'id'           => 'wamid.ECO_REPETIDO',
                'type'         => 'text',
                'text'         => ['body' => 'Una sola vez'],
            ]],
        ]);

        // Meta reintenta los webhooks hasta 36h: el mismo evento llega de nuevo.
        $this->servicio()->processWebhookPayload($payload);
        $this->servicio()->processWebhookPayload($payload);

        $this->assertSame(1, Message::where('external_id', 'wamid.ECO_REPETIDO')->count());
    }

    public function test_el_historial_guarda_las_dos_direcciones_segun_quien_lo_mando(): void
    {
        $this->servicio()->processWebhookPayload($this->payload('history', [
            'metadata' => ['phone_number_id' => '199301559942631'],
            'history'  => [[
                'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 55],
                'threads'  => [[
                    'id'       => self::CLIENTE,
                    'messages' => [
                        [
                            'from'            => self::CLIENTE,
                            'id'             => 'wamid.HIST_CLIENTE',
                            'timestamp'      => '1739230955',
                            'type'           => 'text',
                            'text'           => ['body' => '¿Tienen lavanda?'],
                            'history_context' => ['status' => 'READ'],
                        ],
                        [
                            'from'            => self::NEGOCIO,
                            'id'             => 'wamid.HIST_NEGOCIO',
                            'timestamp'      => '1739230970',
                            'type'           => 'text',
                            'text'           => ['body' => 'Sí, tenemos.'],
                            'history_context' => ['status' => 'READ'],
                        ],
                    ],
                ]],
            ]],
        ]));

        // El thread id es el número del cliente: un mensaje cuyo `from` coincide
        // con él es entrante, cualquier otro salió del negocio.
        $this->assertSame('inbound', Message::where('external_id', 'wamid.HIST_CLIENTE')->first()->direction);
        $this->assertSame('outbound', Message::where('external_id', 'wamid.HIST_NEGOCIO')->first()->direction);
    }

    public function test_el_historial_respeta_la_fecha_original_del_mensaje(): void
    {
        $this->servicio()->processWebhookPayload($this->payload('history', [
            'history' => [[
                'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 10],
                'threads'  => [[
                    'id'       => self::CLIENTE,
                    'messages' => [[
                        'from'      => self::CLIENTE,
                        'id'        => 'wamid.HIST_FECHA',
                        'timestamp' => '1739230955',
                        'type'      => 'text',
                        'text'      => ['body' => 'Mensaje de febrero'],
                    ]],
                ]],
            ]],
        ]));

        // Sin esto el historial entero queda fechado hoy y la conversación
        // aparece desordenada, con chats de hace meses arriba de los de ayer.
        $this->assertSame(
            '2025-02-10',
            Message::where('external_id', 'wamid.HIST_FECHA')->first()->created_at->format('Y-m-d'),
        );
    }

    public function test_el_historial_no_crea_tickets_ni_dispara_flows(): void
    {
        $this->servicio()->processWebhookPayload($this->payload('history', [
            'history' => [[
                'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 100],
                'threads'  => [[
                    'id'       => self::CLIENTE,
                    'messages' => [[
                        'from'      => self::CLIENTE,
                        'id'        => 'wamid.HIST_SIN_TICKET',
                        'timestamp' => '1739230955',
                        'type'      => 'text',
                        'text'      => ['body' => 'Chat viejo ya atendido'],
                    ]],
                ]],
            ]],
        ]));

        // Esta es la razón de ser del handler aparte: 6 meses de chats crearían
        // cientos de tickets de trabajo que el negocio ya resolvió por teléfono.
        $this->assertSame(0, Ticket::count());
        Queue::assertNotPushed(SendWhatsAppFlowJob::class);
    }

    public function test_marca_los_adjuntos_que_el_historial_no_reenvia(): void
    {
        $this->servicio()->processWebhookPayload($this->payload('history', [
            'history' => [[
                'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 20],
                'threads'  => [[
                    'id'       => self::CLIENTE,
                    'messages' => [[
                        'from'      => self::NEGOCIO,
                        'id'        => 'wamid.HIST_MEDIA',
                        'timestamp' => '1739230970',
                        // Meta no reenvía el archivo: solo avisa que ahí hubo uno.
                        'type'      => 'media_placeholder',
                    ]],
                ]],
            ]],
        ]));

        $mensaje = Message::where('external_id', 'wamid.HIST_MEDIA')->first();

        $this->assertNotNull($mensaje);
        $this->assertNotNull(
            $mensaje->body,
            'Un media_placeholder sin body se vería como un mensaje vacío en la bandeja.',
        );
    }

    public function test_un_mensaje_entrante_normal_sigue_creando_ticket(): void
    {
        // Red de seguridad: el cambio a match() en processWebhookPayload no debe
        // haber alterado el camino de 'messages', que es el que ya funcionaba.
        $this->servicio()->processWebhookPayload($this->payload('messages', [
            'contacts' => [['wa_id' => self::CLIENTE, 'profile' => ['name' => 'Ana']]],
            'messages' => [[
                'from'      => self::CLIENTE,
                'id'        => 'wamid.ENTRANTE_NORMAL',
                'timestamp' => '1756200000',
                'type'      => 'text',
                'text'      => ['body' => 'Hola, quiero un curso'],
            ]],
        ]));

        $mensaje = Message::where('external_id', 'wamid.ENTRANTE_NORMAL')->first();

        $this->assertNotNull($mensaje);
        $this->assertSame('inbound', $mensaje->direction);
        $this->assertSame(1, Ticket::count(), 'Un cliente nuevo que escribe sí abre trabajo.');
        Queue::assertPushed(SendWhatsAppFlowJob::class);
    }

    public function test_ignora_campos_de_coexistencia_que_no_procesamos(): void
    {
        // smb_app_state_sync está suscrito en el dashboard (llega la libreta de
        // contactos del negocio), pero todavía no se procesa. Debe pasar sin
        // reventar ni escribir nada.
        $this->servicio()->processWebhookPayload($this->payload('smb_app_state_sync', [
            'contacts' => [['wa_id' => self::CLIENTE, 'profile' => ['name' => 'Ana']]],
        ]));

        $this->assertSame(0, Message::count());
        $this->assertSame(0, Contact::count());
    }
}
