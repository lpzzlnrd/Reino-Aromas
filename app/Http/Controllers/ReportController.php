<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Métricas del panel de control.
 *
 * Existe porque los componentes del dashboard tenían los números
 * hardcodeados: dashboard.cities.vue traía 400/205/182 clientes fijos y solo 3
 * de las 5 sedes, y la tabla de urgentes mostraba una fila de ejemplo
 * ('Nombre cliente', '#RE-0000').
 *
 * Alcance deliberado: conteos y distribuciones sobre datos que ya existen. NO
 * incluye embudos de conversión, tiempos por etapa ni exportación — eso es
 * trabajo aparte del alcance acordado.
 */
class ReportController extends MetaBaseController
{
    /**
     * GET /api/reports/summary
     *
     * Todo lo que el dashboard necesita en UNA llamada.
     *
     * Se agrupa a propósito en un solo endpoint: el panel pinta cuatro bloques
     * a la vez y cuatro requests en paralelo al montar la vista solo agregan
     * latencia y parpadeo.
     */
    public function summary(): JsonResponse
    {
        return response()->json([
            'by_status'  => $this->conteosPorEstado(),
            'by_city'    => $this->distribucionPorCiudad(),
            'by_channel' => $this->distribucionPorCanal(),
            'totals'     => $this->totales(),
        ]);
    }

    /**
     * GET /api/reports/by-city
     *
     * Clientes y tickets por sede. Alimenta dashboard.cities.vue.
     */
    public function byCity(): JsonResponse
    {
        return response()->json($this->distribucionPorCiudad());
    }

    /**
     * GET /api/reports/activity
     *
     * Actividad reciente real, desde activity_logs. Alimenta
     * dashboard.recents.vue, que hoy muestra cuatro items de ejemplo con
     * textos de compras y pagos — funcionalidad que está fuera de alcance y
     * no existe en el sistema.
     */
    public function activity(): JsonResponse
    {
        $logs = ActivityLog::query()
            ->with('causer:id,name,avatar_url')
            ->latest('created_at')
            ->limit(15)
            ->get();

        return response()->json(
            $logs->map(fn (ActivityLog $log): array => [
                'id'          => $log->id,
                'action'      => $log->action,
                // Texto ya redactado: la plantilla no debería tener un match
                // gigante para traducir claves de acción.
                'description' => $this->describir($log),
                'actor'       => $log->causer?->name,
                'actor_avatar' => $log->causer?->avatar_url,
                // causer nulo = lo hizo una automatización (un Flow), no una
                // persona. El front lo pinta distinto.
                'automatic'   => $log->causer_id === null,
                'created_at'  => $log->created_at->toIso8601String(),
            ])
        );
    }

    /**
     * Conteos por estado, con las etiquetas del enum CaseStatus.ts.
     *
     * @return array<string, int>
     */
    private function conteosPorEstado(): array
    {
        $porEstado = Ticket::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $conteos = [];
        foreach (Ticket::statusLabels() as $status => $label) {
            $conteos[$label] = (int) ($porEstado[$status] ?? 0);
        }

        return $conteos;
    }

    /**
     * Distribución por ciudad, con las CINCO sedes.
     *
     * Se devuelven todas aunque estén en cero: el panel dibuja una barra por
     * sede y omitir las vacías haría parecer que la ciudad no existe. El
     * porcentaje se calcula aquí para que el front no tenga que saber el total.
     *
     * @return list<array<string, mixed>>
     */
    private function distribucionPorCiudad(): array
    {
        $contactosPorCiudad = Contact::query()
            ->selectRaw('city, COUNT(*) as total')
            ->whereNotNull('city')
            ->groupBy('city')
            ->pluck('total', 'city');

        $ticketsPorCiudad = Ticket::query()
            ->selectRaw('city, COUNT(*) as total')
            ->whereNotNull('city')
            ->groupBy('city')
            ->pluck('total', 'city');

        $totalContactos = (int) $contactosPorCiudad->sum();

        $resultado = [];
        foreach (self::CIUDADES as $slug => $etiqueta) {
            $clientes = (int) ($contactosPorCiudad[$slug] ?? 0);

            $resultado[] = [
                'city'       => $slug,
                'label'      => $etiqueta,
                'clients'    => $clientes,
                'tickets'    => (int) ($ticketsPorCiudad[$slug] ?? 0),
                // Redondeado a 1 decimal, como lo mostraba la maqueta.
                'percentage' => $totalContactos > 0
                    ? round($clientes / $totalContactos * 100, 1)
                    : 0.0,
            ];
        }

        // Mayor primero: la sede más activa arriba.
        usort($resultado, fn (array $a, array $b): int => $b['clients'] <=> $a['clients']);

        return $resultado;
    }

    /**
     * Distribución por canal de entrada.
     *
     * @return list<array<string, mixed>>
     */
    private function distribucionPorCanal(): array
    {
        $porCanal = Contact::query()
            ->selectRaw('channel, COUNT(*) as total')
            ->groupBy('channel')
            ->pluck('total', 'channel');

        $total = (int) $porCanal->sum();

        return array_map(
            fn (string $canal): array => [
                'channel'    => $canal,
                'label'      => self::CANALES[$canal],
                'contacts'   => (int) ($porCanal[$canal] ?? 0),
                'percentage' => $total > 0
                    ? round((int) ($porCanal[$canal] ?? 0) / $total * 100, 1)
                    : 0.0,
            ],
            Contact::channels(),
        );
    }

    /**
     * Totales de cabecera.
     *
     * @return array<string, int>
     */
    private function totales(): array
    {
        return [
            'contacts'            => Contact::query()->count(),
            'open_conversations'  => Conversation::query()
                ->where('status', Conversation::STATUS_OPEN)
                ->count(),
            'tickets'             => Ticket::query()->count(),
            // Los que necesitan atención ya: alimenta el badge de "urgentes".
            'urgent_tickets'      => Ticket::query()
                ->where('status', Ticket::STATUS_ALTA_PRIORIDAD)
                ->count(),
            // Sin asignar = nadie los está atendiendo todavía.
            'unassigned_tickets'  => Ticket::query()
                ->whereNull('assigned_user_id')
                ->whereNotIn('status', [Ticket::STATUS_CERRADO])
                ->count(),
        ];
    }

    /**
     * Frase legible para una entrada del log.
     *
     * Los metadatos de cada acción los escriben TicketService y
     * TemplateService; aquí solo se leen.
     */
    private function describir(ActivityLog $log): string
    {
        $meta = $log->metadata ?? [];

        return match ($log->action) {
            'ticket.created' => 'Nuevo ticket'
                . (isset($meta['channel']) ? ' desde ' . ($this->etiquetaCanal($meta['channel'])) : ''),

            'ticket.status_changed' => 'Cambió el estado a '
                . $this->etiquetaEstado($meta['to'] ?? null),

            'ticket.priority_changed' => 'Cambió la prioridad a '
                . ($meta['to'] ?? 'otra'),

            // La escribe un Flow de WhatsApp, no una persona.
            'ticket.qualified_automatically' => 'Lead calificado automáticamente'
                . (isset($meta['to']['status'])
                    ? ' como ' . $this->etiquetaEstado($meta['to']['status'])
                    : ''),

            'ticket.assigned'   => 'Asignó un ticket',
            'ticket.unassigned' => 'Quitó la asignación de un ticket',
            'ticket.tags_synced' => 'Actualizó las etiquetas de un ticket',

            'template.used'    => 'Usó la plantilla "' . ($meta['name'] ?? 'sin nombre') . '"',
            'template.created' => 'Creó la plantilla "' . ($meta['name'] ?? 'sin nombre') . '"',
            'template.updated' => 'Editó la plantilla "' . ($meta['name'] ?? 'sin nombre') . '"',
            'template.deleted' => 'Eliminó la plantilla "' . ($meta['name'] ?? 'sin nombre') . '"',

            // Cualquier acción nueva cae acá en vez de romper la vista.
            default => $log->action,
        };
    }

    private function etiquetaEstado(?string $status): string
    {
        return Ticket::statusLabels()[$status] ?? ($status ?? 'otro');
    }

    private function etiquetaCanal(?string $canal): string
    {
        return self::CANALES[$canal] ?? ($canal ?? 'otro canal');
    }

    /**
     * Las cinco sedes del enum de las migraciones, con su etiqueta.
     *
     * @var array<string, string>
     */
    private const CIUDADES = [
        'caracas'      => 'Caracas',
        'valencia'     => 'Valencia',
        'barquisimeto' => 'Barquisimeto',
        'maracay'      => 'Maracay',
        'margarita'    => 'Margarita',
    ];

    /**
     * @var array<string, string>
     */
    private const CANALES = [
        'whatsapp'  => 'WhatsApp',
        'instagram' => 'Instagram',
        'facebook'  => 'Facebook',
    ];
}
