<?php

declare(strict_types=1);

namespace App\Services\Flows;

use App\Models\Conversation;
use App\Models\Template;
use App\Services\TicketService;
use Illuminate\Support\Facades\Log;

/**
 * Decide qué responder a cada request del canal de datos de un Flow.
 *
 * Meta manda varios tipos de request por el mismo POST:
 *
 *   - action=ping          → health check periódico
 *   - data.error presente  → el cliente reporta un error
 *   - action=INIT          → el usuario abrió el Flow
 *   - action=data_exchange → el usuario envió una pantalla
 *   - action=BACK          → el usuario volvió atrás
 *
 * Los nombres de pantalla siguen el plan de implementación
 * (docs/whatsapp_flows_plan.md) y deben coincidir EXACTAMENTE con los del
 * Flow JSON publicado en WhatsApp Manager.
 */
class FlowRouterService
{
    /** Pantalla reservada de Meta que cierra el Flow. */
    private const SCREEN_SUCCESS = 'SUCCESS';

    public const SCREEN_WELCOME       = 'BIENVENIDA';
    public const SCREEN_CITY          = 'CITY_SELECTION';
    public const SCREEN_COURSE_INFO   = 'COURSE_INFO';
    public const SCREEN_CONFIRMATION  = 'INTEREST_CONFIRMATION';
    public const SCREEN_PAYMENT       = 'PAYMENT_INFO';

    public function __construct(private readonly TicketService $tickets) {}

    /**
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function handle(array $body): array
    {
        $action  = (string) ($body['action'] ?? '');
        $version = (string) ($body['version'] ?? '3.0');

        // El health check va primero: es el más frecuente y el más barato.
        // Sin responderlo, Meta marca el Flow como no saludable y deja de
        // entregarlo a los usuarios.
        if ($action === 'ping') {
            return ['version' => $version, 'data' => ['status' => 'active']];
        }

        // Errores del cliente de WhatsApp. Solo hay que acusar recibo, pero
        // conviene loguearlos: aquí aparecen cosas como 'public-key-missing'
        // que exigen resubir la clave con `php artisan flows:upload-key`.
        if (isset($body['data']['error'])) {
            Log::warning('[Flows] Error reportado por el cliente', [
                'error'         => $body['data']['error'] ?? null,
                'error_message' => $body['data']['error_message'] ?? null,
                'screen'        => $body['screen'] ?? null,
                'flow_token'    => $body['flow_token'] ?? null,
            ]);

            return ['version' => $version, 'data' => ['acknowledged' => true]];
        }

        return match ($action) {
            'INIT', 'BACK'  => $this->pantallaCiudades($version),
            'data_exchange' => $this->procesarPantalla($body, $version),
            default         => $this->cerrarConError($version, "Acción no soportada: {$action}"),
        };
    }

    /**
     * Primera pantalla: elegir ciudad.
     *
     * Las ciudades salen de las plantillas activas con precio cargado. Si una
     * ciudad no tiene plantilla activa (el caso de Margarita "en desarrollo"),
     * simplemente no aparece — sin tocar el Flow JSON.
     *
     * @return array<string, mixed>
     */
    private function pantallaCiudades(string $version): array
    {
        return [
            'version' => $version,
            'screen'  => self::SCREEN_CITY,
            'data'    => ['ciudades' => $this->ciudadesDisponibles()],
        ];
    }

    /**
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function procesarPantalla(array $body, string $version): array
    {
        $screen = (string) ($body['screen'] ?? '');
        $data   = is_array($body['data'] ?? null) ? $body['data'] : [];

        return match ($screen) {
            self::SCREEN_WELCOME      => $this->pantallaCiudades($version),
            self::SCREEN_CITY         => $this->infoDelCurso($data, $version),
            self::SCREEN_COURSE_INFO  => $this->ramaSegunInteres($body, $data, $version),
            self::SCREEN_CONFIRMATION => $this->confirmarInteres($body, $data, $version),
            default                   => $this->cerrarConError($version, "Pantalla desconocida: {$screen}"),
        };
    }

    /**
     * Pantalla 3: información del curso en la ciudad elegida.
     *
     * Lee de Template, nunca de valores hardcodeados: si suben el precio de
     * Caracas se actualiza en un solo lugar.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function infoDelCurso(array $data, string $version): array
    {
        $ciudad = (string) ($data['ciudad'] ?? '');

        $template = $this->plantillaDeCiudad($ciudad);

        if ($template === null) {
            // Ciudad sin fechas disponibles: se devuelve a la misma pantalla
            // con el mensaje, en vez de cerrar el Flow.
            return [
                'version' => $version,
                'screen'  => self::SCREEN_CITY,
                'data'    => [
                    'ciudades'      => $this->ciudadesDisponibles(),
                    'error_message' => 'Esa ciudad no tiene fechas disponibles por ahora. Escríbenos y te avisamos.',
                ],
            ];
        }

        return [
            'version' => $version,
            'screen'  => self::SCREEN_COURSE_INFO,
            'data'    => [
                'ciudad'          => $ciudad,
                'ciudad_nombre'   => ucfirst($ciudad),
                // Se mandan como string ya formateado: los componentes de texto
                // del Flow JSON no formatean números.
                'precio'          => $template->price !== null ? '$' . rtrim(rtrim((string) $template->price, '0'), '.') : 'Consultar',
                'reserva'         => $template->deposit !== null ? '$' . rtrim(rtrim((string) $template->deposit, '0'), '.') : '$20',
                'incluye'         => $template->includes ?? 'Materiales e insumos para practicar.',
                'horario'         => $template->schedule ?? '10:00 am a 6:00 pm',
                'frecuencia'      => $template->visit_frequency ?? '',
            ],
        ];
    }

    /**
     * Pantalla 4: el usuario indicó si está interesado o solo quería info.
     *
     * @param  array<string, mixed> $body
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function ramaSegunInteres(array $body, array $data, string $version): array
    {
        $interesado = filter_var($data['interesado'] ?? false, FILTER_VALIDATE_BOOL);

        if (! $interesado) {
            // Rama B: cierre cordial. El ticket queda con prioridad media,
            // que es la que ya tiene por defecto — no hay que tocarlo.
            $this->calificar($body, $data, interesado: false);

            return [
                'version' => $version,
                'screen'  => self::SCREEN_SUCCESS,
                'data'    => [
                    'extension_message_response' => [
                        'params' => ['resultado' => 'solo_info'],
                    ],
                ],
            ];
        }

        // Rama A: pedir confirmación antes de crear el ticket caliente.
        return [
            'version' => $version,
            'screen'  => self::SCREEN_CONFIRMATION,
            'data'    => [
                'ciudad' => $data['ciudad'] ?? null,
            ],
        ];
    }

    /**
     * Pantalla 5: el usuario confirmó interés → ticket en prioridad muy alta.
     *
     * @param  array<string, mixed> $body
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function confirmarInteres(array $body, array $data, string $version): array
    {
        $ticket = $this->calificar($body, $data, interesado: true);

        $reserva = $this->plantillaDeCiudad((string) ($data['ciudad'] ?? ''))?->deposit;

        return [
            'version' => $version,
            'screen'  => self::SCREEN_SUCCESS,
            'data'    => [
                'extension_message_response' => [
                    'params' => [
                        'resultado'     => 'interesado',
                        'ticket_id'     => $ticket?->id,
                        'monto_reserva' => $reserva !== null ? (float) $reserva : 20.0,
                    ],
                ],
            ],
        ];
    }

    /**
     * Actualiza el ticket de la conversación según el interés declarado.
     *
     * El ticket YA existe: lo creó `ensureTicketExists()` al llegar el primer
     * mensaje. Aquí solo se califica, no se crea — así un Flow abandonado a
     * medias deja igualmente el lead en la bandeja.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $data
     */
    private function calificar(array $body, array $data, bool $interesado): ?\App\Models\Ticket
    {
        $conversation = $this->conversacionDelToken((string) ($body['flow_token'] ?? ''));

        if ($conversation?->ticket === null) {
            Log::warning('[Flows] No se encontró el ticket para calificar', [
                'flow_token' => $body['flow_token'] ?? null,
            ]);

            return null;
        }

        $extra = [];

        if (! empty($data['ciudad'])) {
            $extra['city'] = $data['ciudad'];
        }

        if (! empty($data['curso'])) {
            $extra['course_interest'] = $data['curso'];
        }

        return $this->tickets->qualifyAutomatically(
            ticket:   $conversation->ticket,
            status:   $interesado ? 'interesado' : 'nuevo',
            // La regla la definió el cliente: interacción alta = muy alta.
            priority: $interesado ? 'muy_alta' : 'media',
            origen:   'whatsapp_flow',
            extra:    $extra,
        );
    }

    /**
     * Resuelve la conversación a partir del flow_token.
     *
     * El token se genera al enviar el Flow (SendWhatsAppFlowJob) y Meta lo
     * devuelve en cada request, así que es el único vínculo entre la sesión
     * del Flow y el CRM.
     */
    private function conversacionDelToken(string $token): ?Conversation
    {
        if ($token === '') {
            return null;
        }

        return Conversation::query()
            ->forFlowToken($token)
            ->with('ticket')
            ->first();
    }

    /**
     * Ciudades con plantilla activa y precio cargado.
     *
     * Formato que exige Flow JSON para RadioButtonsGroup: id + title.
     *
     * @return list<array{id: string, title: string}>
     */
    private function ciudadesDisponibles(): array
    {
        return Template::query()
            ->active()
            ->whereNotNull('city')
            ->whereNotNull('price')
            ->orderBy('city')
            ->get()
            ->unique('city')
            ->map(fn (Template $t): array => [
                'id'    => (string) $t->city,
                'title' => ucfirst((string) $t->city),
            ])
            ->values()
            ->all();
    }

    private function plantillaDeCiudad(string $ciudad): ?Template
    {
        if ($ciudad === '') {
            return null;
        }

        return Template::query()
            ->active()
            ->where('city', $ciudad)
            ->whereNotNull('price')
            ->first();
    }

    /**
     * Cierra el Flow ante una request que no reconocemos.
     *
     * Para el usuario es mejor que se cierre y le escriba un agente, a que la
     * pantalla se quede girando.
     *
     * @return array<string, mixed>
     */
    private function cerrarConError(string $version, string $motivo): array
    {
        Log::error('[Flows] Request no manejada', ['motivo' => $motivo]);

        return [
            'version' => $version,
            'screen'  => self::SCREEN_SUCCESS,
            'data'    => [
                'extension_message_response' => [
                    'params' => ['error' => $motivo],
                ],
            ],
        ];
    }
}
