<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInstagramQuickReplyMenuRequest;
use App\Http\Requests\StoreQuickReplyOptionRequest;
use App\Models\InstagramAutomation;
use App\Models\InstagramQuickReplyMenu;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * CRUD de los menús de opciones de Instagram (Quick Replies).
 *
 * Un menú es un mensaje con hasta 13 burbujas: al tocar una, Meta dispara el
 * webhook de postback que ya atiende InstagramService::handlePostback(), que
 * resuelve el payload contra `instagram_automations` y responde. Por eso acá
 * solo se administra la configuración — el motor de respuesta ya existía.
 *
 * A diferencia de los Ice Breakers, un menú NO se publica en el perfil de
 * Meta: viaja dentro de un mensaje concreto. No hay nada que sincronizar, y
 * por eso este controlador no tiene sync().
 */
class InstagramQuickReplyMenuController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * GET /api/instagram/quick-reply-menus
     */
    public function index(): JsonResponse
    {
        // `body` es obligatorio en la lista de columnas: InstagramAutomation::
        // respuesta() lo lee para decidir si la opción responde algo, y sin él
        // estaRota() daba true para TODAS las opciones de tipo plantilla.
        $menus = InstagramQuickReplyMenu::query()
            ->with(['opciones.template:id,name,body,is_active'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'menus'  => $menus->map(fn (InstagramQuickReplyMenu $m): array => $this->serializar($m))->values(),
            'limits' => [
                'opciones'     => InstagramQuickReplyMenu::MAX_OPCIONES,
                'titulo'       => InstagramQuickReplyMenu::MAX_TITULO_OPCION,
                'cuerpo'       => 1000,
            ],
        ]);
    }

    /**
     * POST /api/instagram/quick-reply-menus
     */
    public function store(StoreInstagramQuickReplyMenuRequest $request): JsonResponse
    {
        $menu = InstagramQuickReplyMenu::create($request->validated());

        // refresh() por el mismo motivo que en TemplateController::store(): los
        // defaults de la tabla (is_active, sends) quedan null en memoria y el
        // editor recibiría null donde espera un booleano.
        $menu->refresh();

        $this->registrar($request->user(), $menu, 'instagram_quick_reply_menu.created');

        return response()->json(['menu' => $this->serializar($menu)], 201);
    }

    /**
     * PATCH /api/instagram/quick-reply-menus/{menu}
     */
    public function update(
        StoreInstagramQuickReplyMenuRequest $request,
        InstagramQuickReplyMenu $menu,
    ): JsonResponse {
        $menu->update($request->validated());

        $this->registrar($request->user(), $menu, 'instagram_quick_reply_menu.updated');

        return response()->json(['menu' => $this->serializar($menu->fresh())]);
    }

    /**
     * DELETE /api/instagram/quick-reply-menus/{menu}
     */
    public function destroy(Request $request, InstagramQuickReplyMenu $menu): JsonResponse
    {
        $this->registrar($request->user(), $menu, 'instagram_quick_reply_menu.deleted');

        // Las opciones quedan huérfanas (menu_group_id a null por la FK) en vez
        // de borrarse: pueden haber sido enviadas ya, y sus payloads siguen
        // llegando por webhook. Borrarlas dejaría postbacks que el CRM no sabe
        // resolver.
        $menu->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * POST /api/instagram/quick-reply-menus/{menu}/options
     *
     * Añade una burbuja al menú.
     */
    public function storeOption(
        StoreQuickReplyOptionRequest $request,
        InstagramQuickReplyMenu $menu,
    ): JsonResponse {
        $datos = $request->validated();

        $opcion = $menu->opciones()->create([
            'kind'          => InstagramAutomation::KIND_QUICK_REPLY,
            'title'         => $datos['title'],
            // El payload se genera solo: es un detalle técnico que vuelve en el
            // webhook y pedírselo al admin solo abre la puerta a duplicados,
            // que romperían la resolución del postback. El prefijo QR_ lo hace
            // reconocible en los logs.
            'payload'       => $this->payloadUnico($datos['title']),
            'response_type' => $datos['response_type'],
            'template_id'   => $datos['template_id'] ?? null,
            'response_text' => $datos['response_text'] ?? null,
            'position'      => $datos['position'] ?? ($menu->opciones()->max('position') + 1),
            'is_active'     => $datos['is_active'] ?? true,
        ]);

        $this->registrar($request->user(), $menu, 'instagram_quick_reply_option.created');

        return response()->json(['option' => $this->serializarOpcion($opcion)], 201);
    }

    /**
     * PATCH /api/instagram/quick-reply-menus/{menu}/options/{option}
     */
    public function updateOption(
        StoreQuickReplyOptionRequest $request,
        InstagramQuickReplyMenu $menu,
        InstagramAutomation $option,
    ): JsonResponse {
        // El payload NO se toca al editar: ya puede estar en mensajes enviados
        // y cambiarlo dejaría esos botones sin respuesta.
        $option->update($request->safe()->except(['payload']));

        $this->registrar($request->user(), $menu, 'instagram_quick_reply_option.updated');

        return response()->json(['option' => $this->serializarOpcion($option->fresh())]);
    }

    /**
     * DELETE /api/instagram/quick-reply-menus/{menu}/options/{option}
     */
    public function destroyOption(
        Request $request,
        InstagramQuickReplyMenu $menu,
        InstagramAutomation $option,
    ): JsonResponse {
        $option->delete();

        $this->registrar($request->user(), $menu, 'instagram_quick_reply_option.deleted');

        return response()->json(['deleted' => true]);
    }

    /**
     * Un payload único a partir del título.
     *
     * Debe ser único en toda la tabla porque handlePostback() lo resuelve sin
     * saber de qué menú viene: dos opciones con el mismo payload harían que la
     * respuesta dependiera del orden de la consulta.
     */
    private function payloadUnico(string $titulo): string
    {
        $base = 'QR_' . Str::upper(Str::slug($titulo, '_'));
        $base = Str::limit($base, 100, '');

        // El sufijo aleatorio evita la colisión entre dos opciones de texto
        // idéntico en menús distintos ("Precios" en dos menús es razonable).
        do {
            $payload = $base . '_' . Str::upper(Str::random(6));
        } while (InstagramAutomation::where('payload', $payload)->exists());

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(InstagramQuickReplyMenu $menu): array
    {
        $menu->loadMissing('opciones.template:id,name,body,is_active');

        return [
            'id'        => $menu->id,
            'name'      => $menu->name,
            'body'      => $menu->body,
            'is_active' => $menu->is_active,
            'sends'     => $menu->sends,
            'options'   => $menu->opciones->map(
                fn (InstagramAutomation $o): array => $this->serializarOpcion($o)
            )->values(),
            // La vista lo pinta como aviso: un menú sin opciones activas se
            // enviaría como un texto suelto y la persona no tendría qué tocar.
            'is_complete'    => $menu->estaCompleto(),
            'broken_options' => $menu->opcionesRotas()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializarOpcion(InstagramAutomation $opcion): array
    {
        return [
            'id'            => $opcion->id,
            'title'         => $opcion->title,
            'payload'       => $opcion->payload,
            'response_type' => $opcion->response_type,
            'template_id'   => $opcion->template_id,
            'template_name' => $opcion->template?->name,
            'response_text' => $opcion->response_text,
            'position'      => $opcion->position,
            'is_active'     => $opcion->is_active,
            'hits'          => $opcion->hits,
            'is_broken'     => $opcion->estaRota(),
        ];
    }

    private function registrar(?User $user, InstagramQuickReplyMenu $menu, string $accion): void
    {
        $this->activityLog->log(
            causerType: $user !== null ? User::class : null,
            causerId: $user?->id,
            targetType: InstagramQuickReplyMenu::class,
            targetId: $menu->id,
            action: $accion,
            metadata: ['name' => $menu->name],
        );
    }
}
