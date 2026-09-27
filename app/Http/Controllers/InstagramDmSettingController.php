<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInstagramDmSettingRequest;
use App\Models\InstagramDmReply;
use App\Models\InstagramDmSetting;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;

/**
 * Configuración de la respuesta automática a quien escribe un DM.
 *
 * No hay CRUD: es UNA configuración, así que solo se lee y se actualiza —igual
 * que la del DM por comentario, y por el mismo motivo.
 *
 * Tampoco hay nada que sincronizar con Meta: la respuesta sale en el momento
 * en que llega el webhook del mensaje. Lo único que tiene que estar en Meta es
 * la suscripción al topic `messages`, que ya está.
 */
class InstagramDmSettingController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * GET /api/instagram/dm-settings
     *
     * La configuración más las métricas: sin ellas el negocio no puede saber
     * si esto está funcionando sin pedirle al dev que mire los logs.
     */
    public function show(): JsonResponse
    {
        $config = InstagramDmSetting::actual()->load([
            'template:id,name,city,is_active',
            'quickReplyMenu',
        ]);

        return response()->json([
            'settings' => $this->serializar($config),
            'stats'    => $this->metricas(),
            'recent'   => $this->ultimos(),
        ]);
    }

    /**
     * PATCH /api/instagram/dm-settings
     */
    public function update(UpdateInstagramDmSettingRequest $request): JsonResponse
    {
        $config = InstagramDmSetting::actual();

        $config->update($request->validated());

        $this->activityLog->log(
            causerType: User::class,
            causerId: $request->user()?->id,
            targetType: InstagramDmSetting::class,
            targetId: $config->id,
            action: 'instagram_dm_setting.updated',
            metadata: [
                'is_active'     => $config->is_active,
                'response_type' => $config->response_type,
                'trigger_mode'  => $config->trigger_mode,
            ],
        );

        return response()->json([
            'settings' => $this->serializar(
                $config->fresh()->load(['template:id,name,city,is_active', 'quickReplyMenu']),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(InstagramDmSetting $config): array
    {
        return [
            'id'                  => $config->id,
            'is_active'           => $config->is_active,
            'response_type'       => $config->response_type,
            'template_id'         => $config->template_id,
            'quick_reply_menu_id' => $config->quick_reply_menu_id,
            'template'            => $config->template !== null ? [
                'id'        => $config->template->id,
                'name'      => $config->template->name,
                'is_active' => $config->template->is_active,
            ] : null,
            'response_text'       => $config->response_text,
            'keywords'            => $config->keywords ?? [],
            'trigger_mode'        => $config->trigger_mode,
            'skip_if_assigned'    => $config->skip_if_assigned,
            'cooldown_minutes'    => $config->cooldown_minutes,
            'daily_limit'         => $config->daily_limit,

            // Encendida pero sin poder responder: la plantilla o el menú se
            // borraron o se desactivaron. Se avisa en la UI en vez de dejar
            // que se descubra por los clientes que no reciben nada.
            'broken' => $config->is_active && $config->respuesta() === null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metricas(): array
    {
        $hoy = now()->startOfDay();

        return [
            'sent_today' => InstagramDmReply::query()
                ->where('status', InstagramDmReply::STATUS_SENT)
                ->where('created_at', '>=', $hoy)
                ->count(),
            'sent_total' => InstagramDmReply::query()
                ->where('status', InstagramDmReply::STATUS_SENT)
                ->count(),
            'skipped_today' => InstagramDmReply::query()
                ->where('status', InstagramDmReply::STATUS_SKIPPED)
                ->where('created_at', '>=', $hoy)
                ->count(),
            'failed_today' => InstagramDmReply::query()
                ->where('status', InstagramDmReply::STATUS_FAILED)
                ->where('created_at', '>=', $hoy)
                ->count(),
        ];
    }

    /**
     * Los últimos intentos, para depurar sin entrar al servidor.
     *
     * Incluye los omitidos y los fallidos: la pregunta que trae a alguien a
     * esta pantalla suele ser "¿por qué NO respondió?", y el motivo está en
     * esa columna.
     *
     * @return list<array<string, mixed>>
     */
    private function ultimos(): array
    {
        return InstagramDmReply::query()
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(fn (InstagramDmReply $r): array => [
                'id'         => $r->id,
                'username'   => $r->sender_username,
                'message'    => $r->incoming_text,
                'status'     => $r->status,
                'reason'     => $r->skip_reason,
                'created_at' => $r->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
