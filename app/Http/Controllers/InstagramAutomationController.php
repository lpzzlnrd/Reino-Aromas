<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInstagramAutomationRequest;
use App\Models\InstagramAutomation;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Meta\InstagramAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD de los botones automáticos de Instagram.
 *
 * Instagram no tiene WhatsApp Flows; Ice Breakers y Persistent Menu son lo más
 * cercano. Esta es la parte del CRM donde se configuran, para que el negocio no
 * tenga que entrar al panel de Meta ni escribir JSON.
 *
 * La sincronización con Meta NO es automática al guardar: se dispara con un
 * botón aparte. Es a propósito — mientras se está armando la lista se guarda
 * varias veces, y cada sincronización reemplaza la configuración completa en
 * Instagram. Sincronizar en cada tecla dejaría a los clientes viendo botones a
 * medio hacer.
 */
class InstagramAutomationController extends Controller
{
    public function __construct(
        private readonly InstagramAutomationService $automations,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * GET /api/instagram/automations
     *
     * Los botones agrupados por tipo, más si hay cambios sin sincronizar.
     */
    public function index(): JsonResponse
    {
        $botones = InstagramAutomation::query()
            ->with('template:id,name,city,is_active')
            ->ordered()
            ->get();

        return response()->json([
            'ice_breakers' => $this->serializar(
                $botones->where('kind', InstagramAutomation::KIND_ICE_BREAKER),
            ),
            'menu_items' => $this->serializar(
                $botones->where('kind', InstagramAutomation::KIND_MENU_ITEM),
            ),
            'limits' => [
                'ice_breakers' => InstagramAutomation::MAX_ICE_BREAKERS,
                'menu_items'   => InstagramAutomation::MAX_MENU_ITEMS,
            ],
            'pending_sync' => $botones->contains(
                fn (InstagramAutomation $b): bool => $b->necesitaSincronizar(),
            ),
        ]);
    }

    /**
     * POST /api/instagram/automations
     */
    public function store(StoreInstagramAutomationRequest $request): JsonResponse
    {
        $boton = InstagramAutomation::create($request->validated());

        $this->registrar($request->user(), $boton, 'instagram_automation.created');

        return response()->json(['automation' => $this->uno($boton)], 201);
    }

    /**
     * PATCH /api/instagram/automations/{automation}
     */
    public function update(
        StoreInstagramAutomationRequest $request,
        InstagramAutomation $automation,
    ): JsonResponse {
        $automation->update($request->validated());

        $this->registrar($request->user(), $automation, 'instagram_automation.updated');

        return response()->json(['automation' => $this->uno($automation->fresh())]);
    }

    /**
     * DELETE /api/instagram/automations/{automation}
     *
     * Borra de verdad. A diferencia de las cuentas de Meta, acá no hay rastro
     * que valga la pena conservar: un botón borrado no dejó nada más que sus
     * hits, y la configuración vive en Meta hasta la próxima sincronización.
     */
    public function destroy(Request $request, InstagramAutomation $automation): JsonResponse
    {
        $this->registrar($request->user(), $automation, 'instagram_automation.deleted');

        $automation->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * POST /api/instagram/automations/sync
     *
     * Manda a Meta la configuración completa de ambos tipos.
     *
     * Se sincronizan los dos aunque solo cambie uno: son dos campos del mismo
     * endpoint y el costo es el mismo, mientras que sincronizar solo uno deja
     * el otro divergiendo sin que nadie se dé cuenta.
     */
    public function sync(Request $request): JsonResponse
    {
        $iceBreakers = $this->automations->sincronizarIceBreakers();
        $menu        = $this->automations->sincronizarMenu();

        $ok = $iceBreakers['success'] && $menu['success'];

        if ($ok) {
            $this->activityLog->log(
                causerType: User::class,
                causerId: $request->user()?->id,
                targetType: InstagramAutomation::class,
                targetId: null,
                action: 'instagram_automation.synced',
                metadata: [],
            );
        }

        return response()->json([
            'success'      => $ok,
            'ice_breakers' => $iceBreakers,
            'menu'         => $menu,
        ], $ok ? 200 : 502);
    }

    /**
     * GET /api/instagram/automations/meta-state
     *
     * Lo que Instagram tiene configurado ahora mismo.
     *
     * Existe porque la configuración puede divergir: alguien pudo tocarla desde
     * el panel de Meta, o una sincronización pudo fallar a medias. Sin esto no
     * habría forma de saberlo desde el CRM.
     */
    public function metaState(): JsonResponse
    {
        $estado = $this->automations->estadoEnMeta();

        return response()->json($estado, $estado['success'] ? 200 : 502);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, InstagramAutomation> $botones
     * @return list<array<string, mixed>>
     */
    private function serializar($botones): array
    {
        return $botones->map(fn (InstagramAutomation $b): array => $this->uno($b))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function uno(InstagramAutomation $boton): array
    {
        return [
            'id'            => $boton->id,
            'kind'          => $boton->kind,
            'title'         => $boton->title,
            'payload'       => $boton->payload,
            'response_type' => $boton->response_type,
            'template_id'   => $boton->template_id,
            'template_name' => $boton->template?->name,
            'response_text' => $boton->response_text,
            'url'           => $boton->url,
            'position'      => $boton->position,
            'is_active'     => $boton->is_active,
            'hits'          => $boton->hits,
            'synced_at'     => $boton->synced_at?->toIso8601String(),
            'needs_sync'    => $boton->necesitaSincronizar(),
            // Avisa del caso silencioso: el botón dice responder con una
            // plantilla que ya no existe o está desactivada.
            'broken'        => $boton->response_type === InstagramAutomation::RESPONSE_TEMPLATE
                && $boton->template?->is_active !== true,
        ];
    }

    private function registrar(?User $user, InstagramAutomation $boton, string $accion): void
    {
        $this->activityLog->log(
            causerType: User::class,
            causerId: $user?->id,
            targetType: InstagramAutomation::class,
            targetId: $boton->id,
            action: $accion,
            metadata: ['kind' => $boton->kind, 'payload' => $boton->payload],
        );
    }
}
