<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * Todo lo que el panel y la vista de Reportes necesitan en UNA llamada.
     *
     * Se agrupa a propósito en un solo endpoint: se pintan varios bloques a la
     * vez y otros tantos requests en paralelo al montar la vista solo agregan
     * latencia y parpadeo.
     *
     * Filtro opcional por rango: ?from=YYYY-MM-DD&to=YYYY-MM-DD. Sin él se
     * cuenta todo el histórico, que es como se comportaba antes — el dashboard
     * lo llama sin parámetros y no cambia.
     */
    public function summary(Request $request): JsonResponse
    {
        [$desde, $hasta] = $this->rango($request);

        return response()->json([
            'by_status'   => $this->conteosPorEstado($desde, $hasta),
            'by_city'     => $this->distribucionPorCiudad($desde, $hasta),
            'by_channel'  => $this->distribucionPorCanal($desde, $hasta),
            'by_priority' => $this->distribucionPorPrioridad($desde, $hasta),
            'by_course'   => $this->distribucionPorCurso($desde, $hasta),
            'totals'      => $this->totales($desde, $hasta),
            // El front lo muestra al pie ("datos del 1 al 31 de agosto") para
            // que no se lea un número filtrado como si fuera el total.
            'range'       => [
                'from' => $desde?->toDateString(),
                'to'   => $hasta?->toDateString(),
            ],
        ]);
    }

    /**
     * GET /api/reports/by-city
     *
     * Clientes y tickets por sede. Alimenta dashboard.cities.vue.
     */
    public function byCity(Request $request): JsonResponse
    {
        [$desde, $hasta] = $this->rango($request);

        return response()->json($this->distribucionPorCiudad($desde, $hasta));
    }

    /**
     * Rango de fechas del request, ya normalizado.
     *
     * `to` se lleva al final del día: con ?to=2026-08-13 el usuario espera que
     * incluya lo de ese día, no que corte a la medianoche. Una fecha inválida
     * se ignora en vez de reventar — el reporte cae al histórico completo.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function rango(Request $request): array
    {
        $parse = function (?string $valor, bool $finDelDia): ?CarbonImmutable {
            if ($valor === null || $valor === '') {
                return null;
            }

            try {
                $fecha = CarbonImmutable::parse($valor);
            } catch (\Throwable) {
                return null;
            }

            return $finDelDia ? $fecha->endOfDay() : $fecha->startOfDay();
        };

        $desde = $parse($request->query('from'), false);
        $hasta = $parse($request->query('to'), true);

        // Invertidos: se corrigen en vez de devolver un reporte vacío que
        // parecería "no hay datos".
        if ($desde !== null && $hasta !== null && $desde->greaterThan($hasta)) {
            return [$hasta->startOfDay(), $desde->endOfDay()];
        }

        return [$desde, $hasta];
    }

    /**
     * Aplica el rango a una consulta, si lo hay.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    private function enRango(
        \Illuminate\Database\Eloquent\Builder $query,
        ?CarbonImmutable $desde,
        ?CarbonImmutable $hasta,
        string $columna = 'created_at',
    ): \Illuminate\Database\Eloquent\Builder {
        return $query
            ->when($desde !== null, fn ($q) => $q->where($columna, '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->where($columna, '<=', $hasta));
    }

    /**
     * GET /api/reports/activity
     *
     * Actividad reciente real, desde activity_logs. Alimenta
     * dashboard.recents.vue, que hoy muestra cuatro items de ejemplo con
     * textos de compras y pagos — funcionalidad que está fuera de alcance y
     * no existe en el sistema.
     */
    public function activity(Request $request): JsonResponse
    {
        [$desde, $hasta] = $this->rango($request);

        // El límite es configurable porque el dashboard muestra un resumen
        // corto y la vista de Reportes una lista larga. Se topa en 100 para que
        // un ?limit=99999 no traiga la tabla entera.
        $limite = min(max((int) $request->query('limit', '15'), 1), 100);

        $logs = $this->enRango(
            ActivityLog::query()->with('causer:id,name,avatar_url'),
            $desde,
            $hasta,
        )
            ->latest('created_at')
            ->limit($limite)
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
    private function conteosPorEstado(?CarbonImmutable $desde = null, ?CarbonImmutable $hasta = null): array
    {
        $porEstado = $this->enRango(Ticket::query(), $desde, $hasta)
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
    private function distribucionPorCiudad(?CarbonImmutable $desde = null, ?CarbonImmutable $hasta = null): array
    {
        // Los contactos se filtran por first_seen_at y no por created_at: la
        // pregunta del reporte es "cuándo entró este cliente", que es lo que
        // marca esa columna.
        $contactosPorCiudad = $this->enRango(Contact::query(), $desde, $hasta, 'first_seen_at')
            ->selectRaw('city, COUNT(*) as total')
            ->whereNotNull('city')
            ->groupBy('city')
            ->pluck('total', 'city');

        $ticketsPorCiudad = $this->enRango(Ticket::query(), $desde, $hasta)
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
    private function distribucionPorCanal(?CarbonImmutable $desde = null, ?CarbonImmutable $hasta = null): array
    {
        $porCanal = $this->enRango(Contact::query(), $desde, $hasta, 'first_seen_at')
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
     * Distribución por prioridad, de la más alta a la más baja.
     *
     * Se devuelven las cuatro aunque estén en cero, igual que las ciudades: la
     * vista dibuja una barra por prioridad.
     *
     * @return list<array<string, mixed>>
     */
    private function distribucionPorPrioridad(?CarbonImmutable $desde = null, ?CarbonImmutable $hasta = null): array
    {
        $porPrioridad = $this->enRango(Ticket::query(), $desde, $hasta)
            ->selectRaw('priority, COUNT(*) as total')
            ->groupBy('priority')
            ->pluck('total', 'priority');

        $total = (int) $porPrioridad->sum();

        return array_map(
            fn (string $prioridad): array => [
                'priority'   => $prioridad,
                'label'      => self::PRIORIDADES[$prioridad],
                'tickets'    => (int) ($porPrioridad[$prioridad] ?? 0),
                'percentage' => $total > 0
                    ? round((int) ($porPrioridad[$prioridad] ?? 0) / $total * 100, 1)
                    : 0.0,
            ],
            array_keys(self::PRIORIDADES),
        );
    }

    /**
     * Cursos más solicitados.
     *
     * Es el corte de negocio de Reino Aromas: los cursos son el mayor ingreso,
     * así que saber cuál se pide más es lo que justifica esta vista.
     *
     * A diferencia de ciudades y prioridades, aquí NO hay lista fija: el curso
     * es texto libre que escribe el agente o rellena un Flow. Se normaliza a
     * minúsculas para que "Velas" y "velas" no salgan como dos filas, y se
     * devuelven los 10 primeros — la cola larga no aporta al reporte.
     *
     * @return list<array<string, mixed>>
     */
    private function distribucionPorCurso(?CarbonImmutable $desde = null, ?CarbonImmutable $hasta = null): array
    {
        $tickets = $this->enRango(Ticket::query(), $desde, $hasta)
            ->whereNotNull('course_interest')
            ->where('course_interest', '!=', '')
            ->pluck('course_interest');

        $total = $tickets->count();

        if ($total === 0) {
            return [];
        }

        // La agrupación se hace en PHP y no en SQL a propósito: MySQL agrupa sin
        // distinguir mayúsculas por su collation, pero SQLite (donde corren los
        // tests) sí distingue, y el reporte saldría distinto en cada motor.
        return $tickets
            ->groupBy(fn (string $curso): string => mb_strtolower(trim($curso)))
            ->map(fn ($grupo, string $clave): array => [
                'course'     => $clave,
                // Se muestra la primera grafía que usó el agente, no la clave
                // en minúsculas: "Sales Aromáticas" se lee mejor que "sales
                // aromáticas".
                'label'      => trim((string) $grupo->first()),
                'tickets'    => $grupo->count(),
                'percentage' => round($grupo->count() / $total * 100, 1),
            ])
            ->sortByDesc('tickets')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Totales de cabecera.
     *
     * Ojo con cuáles respetan el rango: los conteos de "cuántos entraron" sí,
     * pero open_conversations y unassigned_tickets son fotos del AHORA — un
     * chat abierto lo está hoy, no "en agosto". Filtrarlos daría un número que
     * no significa nada.
     *
     * @return array<string, int>
     */
    private function totales(?CarbonImmutable $desde = null, ?CarbonImmutable $hasta = null): array
    {
        return [
            'contacts'            => $this->enRango(Contact::query(), $desde, $hasta, 'first_seen_at')->count(),
            'open_conversations'  => Conversation::query()
                ->where('status', Conversation::STATUS_OPEN)
                ->count(),
            'tickets'             => $this->enRango(Ticket::query(), $desde, $hasta)->count(),
            // Los que necesitan atención ya: alimenta el badge de "urgentes".
            'urgent_tickets'      => $this->enRango(Ticket::query(), $desde, $hasta)
                ->where('status', Ticket::STATUS_ALTA_PRIORIDAD)
                ->count(),
            // Sin asignar = nadie los está atendiendo todavía.
            'unassigned_tickets'  => Ticket::query()
                ->whereNull('assigned_user_id')
                ->whereNotIn('status', [Ticket::STATUS_CERRADO])
                ->count(),
            // Cerrados en el periodo: es el numerador de la tasa de cierre que
            // pinta la vista. Va por closed_at, no por created_at — importa
            // cuándo se cerró, no cuándo nació.
            'closed_tickets'      => $this->enRango(
                Ticket::query()->whereNotNull('closed_at'),
                $desde,
                $hasta,
                'closed_at',
            )->count(),
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

    /**
     * Las cuatro prioridades del enum de las migraciones, de mayor a menor.
     * El orden importa: la vista las pinta en este orden.
     *
     * @var array<string, string>
     */
    private const PRIORIDADES = [
        Ticket::PRIORITY_MUY_ALTA => 'Muy alta',
        Ticket::PRIORITY_ALTA     => 'Alta',
        Ticket::PRIORITY_MEDIA    => 'Media',
        Ticket::PRIORITY_BAJA     => 'Baja',
    ];
}
