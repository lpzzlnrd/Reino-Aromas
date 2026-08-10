<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTicketRequest;
use App\Models\Ticket;
use App\Services\ActivityLogService;
use App\Services\TicketService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tickets del CRM.
 *
 * Los cambios de estado, prioridad y asignación pasan por TicketService para
 * que queden en activity_logs: el historial de quién movió qué es requisito
 * del negocio, no un extra.
 */
class TicketController extends MetaBaseController
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * GET /api/tickets
     *
     * Listado para el Kanban y la vista de tablero. Filtros por query string:
     *   status, priority, city, assigned_user_id, mine=1
     */
    public function index(Request $request): JsonResponse
    {
        $tickets = Ticket::query()
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->query('status')),
            )
            ->when(
                $request->filled('priority'),
                fn ($q) => $q->where('priority', $request->query('priority')),
            )
            ->when(
                $request->filled('city'),
                fn ($q) => $q->where('city', $request->query('city')),
            )
            ->when(
                $request->filled('assigned_user_id'),
                fn ($q) => $q->where('assigned_user_id', $request->query('assigned_user_id')),
            )
            ->when(
                $request->boolean('mine') && $request->user() !== null,
                fn ($q) => $q->where('assigned_user_id', $request->user()->id),
            )
            ->with(['assignedUser:id,name,avatar_url', 'tags:id,name,color', 'conversation.contact'])
            // Orden por la secuencia real del embudo, no alfabético:
            // 'alta_prioridad' antes de 'nuevo' no tendría sentido en el tablero.
            //
            // Se usa CASE en vez de FIELD() porque FIELD es exclusivo de MySQL y
            // los tests corren sobre SQLite; CASE es SQL estándar.
            ->orderByRaw($this->ordenPor('status', Ticket::statuses()))
            ->orderByRaw($this->ordenPor('priority', [
                Ticket::PRIORITY_MUY_ALTA,
                Ticket::PRIORITY_ALTA,
                Ticket::PRIORITY_MEDIA,
                Ticket::PRIORITY_BAJA,
            ]))
            ->orderByDesc('updated_at')
            ->get();

        return response()->json(
            $tickets->map(fn (Ticket $t): array => $this->serializar($t))
        );
    }

    /**
     * GET /api/tickets/counts
     *
     * Totales por estado para los contadores del dashboard
     * (useCaseStatus().setCounts espera las etiquetas del enum CaseStatus).
     */
    public function counts(): JsonResponse
    {
        $porEstado = Ticket::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Se devuelven todos los estados, incluidos los que están en cero: el
        // front pinta una columna por estado y necesita la clave presente.
        $counts = [];
        foreach (Ticket::statusLabels() as $status => $label) {
            $counts[$label] = (int) ($porEstado[$status] ?? 0);
        }

        return response()->json($counts);
    }

    /**
     * GET /api/tickets/{ticket}
     */
    public function show(Ticket $ticket): JsonResponse
    {
        $ticket->load([
            'assignedUser:id,name,avatar_url',
            'tags:id,name,color',
            'conversation.contact',
            'activityLogs',
        ]);

        return response()->json($this->serializar($ticket, conHistorial: true));
    }

    /**
     * PATCH /api/tickets/{ticket}
     *
     * Actualización parcial. Estado, prioridad y asignación se delegan a
     * TicketService (que audita); el resto de campos se escriben directo
     * porque no forman parte del historial del embudo.
     */
    public function update(UpdateTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        if ($user === null) {
            return $this->jsonError('Sesión no válida.', 401);
        }

        if (array_key_exists('status', $data) && $data['status'] !== $ticket->status) {
            $this->tickets->changeStatus($ticket, $data['status'], $user);
        }

        if (array_key_exists('priority', $data) && $data['priority'] !== $ticket->priority) {
            $this->tickets->changePriority($ticket, $data['priority'], $user);
        }

        if (array_key_exists('assigned_user_id', $data)
            && $data['assigned_user_id'] !== $ticket->assigned_user_id) {
            $this->asignar($ticket, $data['assigned_user_id'], $user);
        }

        $directos = array_intersect_key($data, array_flip(['city', 'course_interest', 'notes']));
        if ($directos !== []) {
            $ticket->update($directos);
        }

        $ticket->refresh()->load(['assignedUser:id,name,avatar_url', 'tags:id,name,color', 'conversation.contact']);

        return $this->jsonSuccess(['ticket' => $this->serializar($ticket)]);
    }

    /**
     * PUT /api/tickets/{ticket}/tags
     *
     * Reemplaza las etiquetas del ticket por el conjunto recibido.
     */
    public function syncTags(Request $request, Ticket $ticket): JsonResponse
    {
        $validated = $request->validate([
            'tag_ids'   => ['present', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
        ]);

        $ticket->tags()->sync($validated['tag_ids']);

        $this->activityLog->log(
            causerType: User::class,
            causerId: $request->user()?->id,
            targetType: Ticket::class,
            targetId: $ticket->id,
            action: 'ticket.tags_synced',
            metadata: ['tag_ids' => $validated['tag_ids']],
        );

        return $this->jsonSuccess([
            'tags' => $ticket->load('tags:id,name,color')->tags->map(fn ($tag): array => [
                'id'    => $tag->id,
                'name'  => $tag->name,
                'color' => $tag->color,
            ])->values(),
        ]);
    }

    /**
     * Construye un ORDER BY que respeta el orden de $valores.
     *
     * Equivalente portable de FIELD(columna, ...) de MySQL. Los valores se
     * escapan aunque hoy vengan solo de constantes del modelo: si algún día se
     * alimenta desde el request, no debe convertirse en inyección SQL.
     *
     * @param list<string> $valores
     */
    private function ordenPor(string $columna, array $valores): string
    {
        $casos = '';
        foreach (array_values($valores) as $i => $valor) {
            $escapado = str_replace("'", "''", $valor);
            $casos .= " WHEN '{$escapado}' THEN {$i}";
        }

        // El else deja al final cualquier valor inesperado de la columna.
        return "CASE {$columna}{$casos} ELSE " . count($valores) . ' END';
    }

    /**
     * Asignación de agente. Vive aquí y no en TicketService porque el servicio
     * hoy no expone un método de asignación y el log debe quedar igual.
     */
    private function asignar(Ticket $ticket, ?int $assignedUserId, User $byUser): void
    {
        $previous = $ticket->assigned_user_id;

        $ticket->update(['assigned_user_id' => $assignedUserId]);

        $this->activityLog->log(
            causerType: User::class,
            causerId: $byUser->id,
            targetType: Ticket::class,
            targetId: $ticket->id,
            action: $assignedUserId === null ? 'ticket.unassigned' : 'ticket.assigned',
            metadata: ['from' => $previous, 'to' => $assignedUserId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(Ticket $ticket, bool $conHistorial = false): array
    {
        $contact = $ticket->conversation?->contact;

        $data = [
            'id'              => $ticket->id,
            'conversation_id' => $ticket->conversation_id,
            'status'          => $ticket->status,
            'status_label'    => $ticket->statusLabel(),
            'priority'        => $ticket->priority,
            'city'            => $ticket->city,
            'course_interest' => $ticket->course_interest,
            'notes'           => $ticket->notes,
            'reserved_at'     => $ticket->reserved_at?->toIso8601String(),
            'closed_at'       => $ticket->closed_at?->toIso8601String(),
            'updated_at'      => $ticket->updated_at?->toIso8601String(),
            'contact'         => $contact ? [
                'id'                  => $contact->id,
                'display_name'        => $contact->display_name,
                'profile_picture_url' => $contact->profile_picture_url,
                'channel'             => $contact->channel,
                'city'                => $contact->city,
            ] : null,
            'assigned_user'   => $ticket->assignedUser ? [
                'id'     => $ticket->assignedUser->id,
                'name'   => $ticket->assignedUser->name,
                'avatar' => $ticket->assignedUser->avatar_url,
            ] : null,
            'tags'            => $ticket->tags->map(fn ($tag): array => [
                'id'    => $tag->id,
                'name'  => $tag->name,
                'color' => $tag->color,
            ])->values(),
        ];

        if ($conHistorial) {
            $data['activity'] = $ticket->activityLogs->map(fn ($log): array => [
                'id'         => $log->id,
                'action'     => $log->action,
                'metadata'   => $log->metadata,
                'created_at' => $log->created_at->toIso8601String(),
            ])->values();
        }

        return $data;
    }
}
