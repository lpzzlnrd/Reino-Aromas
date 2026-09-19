<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateInstagramCommentSettingRequest;
use App\Models\InstagramCommentReply;
use App\Models\InstagramCommentSetting;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;

/**
 * Configuración de la respuesta automática a quien comenta un post de Instagram:
 * el comentario público y el DM privado.
 *
 * No hay CRUD: es UNA configuración, así que solo se lee y se actualiza. Ver la
 * migración para el porqué de no reutilizar `instagram_automations`.
 *
 * A diferencia de los botones, acá NO hay que sincronizar nada con Meta: el
 * mensaje se manda en el momento en que llega el webhook del comentario. Lo
 * único que hay que tener configurado en Meta es la suscripción al topic
 * `comments`, que se hace una vez desde el panel.
 */
class InstagramCommentSettingController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * GET /api/instagram/comment-settings
     *
     * La configuración más las métricas de los últimos envíos: sin ellas el
     * negocio no tiene forma de saber si esto está funcionando, y la única
     * alternativa sería pedirle al dev que mire los logs.
     */
    public function show(): JsonResponse
    {
        $config = InstagramCommentSetting::actual()->load('template:id,name,city,is_active');

        return response()->json([
            'settings' => $this->serializar($config),
            'stats'    => $this->metricas(),
            'recent'   => $this->ultimos(),
        ]);
    }

    /**
     * PATCH /api/instagram/comment-settings
     */
    public function update(UpdateInstagramCommentSettingRequest $request): JsonResponse
    {
        $config = InstagramCommentSetting::actual();

        $config->update($request->validated());

        $this->activityLog->log(
            causerType: User::class,
            causerId: $request->user()?->id,
            targetType: InstagramCommentSetting::class,
            targetId: $config->id,
            action: 'instagram_comment_setting.updated',
            metadata: [
                'is_active'           => $config->is_active,
                'public_reply_active' => $config->public_reply_active,
                'response_type'       => $config->response_type,
            ],
        );

        return response()->json([
            'settings' => $this->serializar(
                $config->fresh()->load('template:id,name,city,is_active'),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializar(InstagramCommentSetting $config): array
    {
        return [
            'id'                  => $config->id,
            'is_active'           => $config->is_active,
            'public_reply_active' => $config->public_reply_active,
            'public_reply_text'   => $config->public_reply_text,
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
            'daily_limit'         => $config->daily_limit,

            // La plantilla elegida se borró o se desactivó: la automatización
            // está encendida pero no puede responder. Se avisa en la UI en vez
            // de dejar que se descubra por los comentarios sin respuesta.
            'broken' => $config->is_active && $config->respuesta() === null,
        ];
    }

    /**
     * Cuántos se enviaron y cuántos se omitieron, hoy y en total.
     *
     * @return array<string, mixed>
     */
    private function metricas(): array
    {
        $hoy = now()->startOfDay();

        return [
            'sent_today'   => InstagramCommentReply::query()->sent()->where('created_at', '>=', $hoy)->count(),
            'sent_total'   => InstagramCommentReply::query()->sent()->count(),
            'skipped_today' => InstagramCommentReply::query()
                ->where('status', InstagramCommentReply::STATUS_SKIPPED)
                ->where('created_at', '>=', $hoy)
                ->count(),
            'failed_today' => InstagramCommentReply::query()
                ->where('status', InstagramCommentReply::STATUS_FAILED)
                ->where('created_at', '>=', $hoy)
                ->count(),
        ];
    }

    /**
     * Los últimos intentos, para poder depurar sin entrar al servidor.
     *
     * Se incluyen los omitidos y los fallidos a propósito: la pregunta que trae
     * a alguien a esta pantalla suele ser "¿por qué NO se envió?", y el motivo
     * está justo en esa columna.
     *
     * @return list<array<string, mixed>>
     */
    private function ultimos(): array
    {
        return InstagramCommentReply::query()
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(fn (InstagramCommentReply $r): array => [
                'id'         => $r->id,
                'username'   => $r->commenter_username,
                'comment'    => $r->comment_text,
                'status'     => $r->status,
                'reason'     => $r->skip_reason,
                // Si el aviso público salió o por qué no: son dos envíos
                // independientes y el agente necesita distinguirlos.
                'replied'    => $r->public_reply_id !== null,
                    'reply_error' => $r->public_reply_error,
                'created_at' => $r->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
