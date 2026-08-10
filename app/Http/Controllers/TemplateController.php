<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTemplateRequest;
use App\Http\Requests\UpdateTemplateRequest;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Template;
use App\Services\ActivityLogService;
use App\Services\TemplateService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestión de plantillas de respuesta rápida.
 *
 * Controlador delgado: la sustitución de variables y el filtrado por
 * ciudad/canal viven en TemplateService.
 */
class TemplateController extends Controller
{
    public function __construct(
        private readonly TemplateService $templates,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * GET /api/templates
     *
     * Listado completo para el gestor. Devuelve también el catálogo de
     * variables para que la vista no tenga su propia copia.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Template::query();

        // Filtros opcionales. 'todas'/'todos' significa sin filtro; el string
        // vacío no se usa porque en un <select> es ambiguo con "sin valor".
        if (($city = $request->query('city')) !== null && $city !== 'todas') {
            $query->where('city', $city === 'ninguna' ? null : $city);
        }

        if (($channel = $request->query('channel')) !== null && $channel !== 'todos') {
            $query->where('channel', $channel === 'ninguno' ? null : $channel);
        }

        if (($search = $request->query('search')) !== null && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('body', 'like', "%{$search}%");
            });
        }

        $templates = $query
            ->orderByDesc('is_active')
            ->orderByDesc('usage_count')
            ->orderBy('name')
            ->get()
            ->map(fn (Template $t): array => $this->serializar($t));

        return response()->json([
            'templates' => $templates,
            'variables' => TemplateService::VARIABLES,
        ]);
    }

    /**
     * POST /api/templates
     */
    public function store(StoreTemplateRequest $request): JsonResponse
    {
        $template = Template::create($request->validated());

        $this->activityLog->log(
            causerType: User::class,
            causerId: $request->user()?->id,
            targetType: Template::class,
            targetId: $template->id,
            action: 'template.created',
            metadata: ['name' => $template->name],
        );

        return response()->json($this->serializar($template), 201);
    }

    /**
     * PUT /api/templates/{template}
     */
    public function update(UpdateTemplateRequest $request, Template $template): JsonResponse
    {
        $template->update($request->validated());

        $this->activityLog->log(
            causerType: User::class,
            causerId: $request->user()?->id,
            targetType: Template::class,
            targetId: $template->id,
            action: 'template.updated',
            metadata: ['name' => $template->name],
        );

        return response()->json($this->serializar($template->fresh()));
    }

    /**
     * DELETE /api/templates/{template}
     *
     * Borrado real: templates no usa SoftDeletes y una plantilla eliminada no
     * deja huérfano ningún mensaje ya enviado (el texto se copia al enviar).
     */
    public function destroy(Request $request, Template $template): JsonResponse
    {
        $nombre = $template->name;
        $id     = $template->id;

        $template->delete();

        $this->activityLog->log(
            causerType: User::class,
            causerId: $request->user()?->id,
            targetType: Template::class,
            targetId: $id,
            action: 'template.deleted',
            metadata: ['name' => $nombre],
        );

        return response()->json(['status' => true]);
    }

    /**
     * PATCH /api/templates/{template}/toggle-active
     *
     * Desactivar en vez de borrar es lo habitual: una plantilla de temporada
     * se apaga y se vuelve a encender el año siguiente.
     */
    public function toggleActive(Template $template): JsonResponse
    {
        $template->update(['is_active' => ! $template->is_active]);

        return response()->json(['is_active' => $template->is_active]);
    }

    /**
     * POST /api/templates/preview
     *
     * Vista previa en vivo mientras se escribe. Recibe el cuerpo sin guardar,
     * así que el editor muestra el resultado antes de crear la plantilla.
     *
     * Si se pasa contact_id usa datos reales; si no, valores de ejemplo para
     * que la previa nunca se vea vacía.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'body'       => ['required', 'string', 'max:4000'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
        ]);

        $contact = isset($validated['contact_id'])
            ? Contact::find($validated['contact_id'])
            : null;

        $valores = $contact !== null
            ? $this->templates->valoresPara($contact, $contact->activeConversation?->ticket, $request->user()?->name)
            : [
                'nombre' => 'María',
                'ciudad' => 'Caracas',
                'curso'  => 'Velas Artesanales',
                'agente' => $request->user()?->name ?? 'Ana',
            ];

        return response()->json([
            'rendered'              => $this->templates->render($validated['body'], $valores),
            'variables_usadas'      => $this->templates->variablesUsadas($validated['body']),
            'variables_desconocidas'=> $this->templates->variablesDesconocidas($validated['body']),
        ]);
    }

    /**
     * GET /api/conversations/{conversation}/templates
     *
     * Plantillas aplicables a una conversación, ya renderizadas con los datos
     * del contacto. Es lo que alimenta el selector dentro del chat.
     */
    public function forConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $conversation->loadMissing(['contact', 'ticket']);

        $templates = $this->templates->disponiblesPara(
            $conversation->contact,
            $conversation->ticket,
            $request->user()?->name,
        );

        return response()->json(
            $templates->map(fn (Template $t): array => [
                'id'            => $t->id,
                'name'          => $t->name,
                'category'      => $t->category,
                'body'          => $t->body,
                'rendered_body' => $t->getAttribute('rendered_body'),
            ])
        );
    }

    /**
     * POST /api/templates/{template}/use
     *
     * Marca la plantilla como usada. La vista lo llama al insertar el texto en
     * el chat, para que el contador refleje el uso real.
     */
    public function markUsed(Request $request, Template $template): JsonResponse
    {
        $this->templates->registrarUso($template, $request->user()?->id);

        return response()->json([
            'usage_count'  => $template->fresh()->usage_count,
        ]);
    }

    /**
     * Forma en que la vista espera cada plantilla.
     *
     * @return array<string, mixed>
     */
    private function serializar(Template $template): array
    {
        return [
            'id'                 => $template->id,
            'name'               => $template->name,
            'body'               => $template->body,
            'city'               => $template->city,
            'channel'            => $template->channel,
            'category'           => $template->category,
            'is_active'          => $template->is_active,
            // Datos del curso que consume el endpoint de WhatsApp Flows.
            'price'              => $template->price,
            'deposit'            => $template->deposit,
            'includes'           => $template->includes,
            'visit_frequency'    => $template->visit_frequency,
            'schedule'           => $template->schedule,
            'usage_count'        => $template->usage_count,
            'last_used_at'       => $template->last_used_at?->toIso8601String(),
            'meta_template_name' => $template->meta_template_name,
            'variables'          => $this->templates->variablesUsadas($template->body),
            'variables_desconocidas' => $this->templates->variablesDesconocidas($template->body),
        ];
    }
}
