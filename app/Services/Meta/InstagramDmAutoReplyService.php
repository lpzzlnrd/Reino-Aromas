<?php

declare(strict_types=1);

namespace App\Services\Meta;

use App\Models\Conversation;
use App\Models\InstagramDmReply;
use App\Models\InstagramDmSetting;
use App\Models\Message;
use App\Services\OutboundMessageService;
use Illuminate\Support\Facades\Log;

/**
 * Responde sola a quien escribe un DM a la cuenta de Instagram.
 *
 * Es el equivalente en el buzón de lo que {@see InstagramCommentService} hace
 * con los comentarios, pero el problema es distinto: un comentario es un hecho
 * puntual y público, mientras que un DM abre una conversación que un agente
 * puede estar atendiendo. Por eso casi todo el código de acá son los CORTES
 * —las razones para callarse— y no el envío.
 *
 * ## Por qué el default es "solo el primer mensaje"
 *
 * Responder a todo mensaje entrante suena a mejor servicio y es lo contrario:
 * el cliente escribe "hola", recibe el menú; escribe "quiero el de Valencia",
 * recibe el menú otra vez. El robot pisa la conversación justo cuando se puso
 * interesante. Con 'first' la automatización hace lo que sabe hacer —atender
 * al que llega— y se aparta en cuanto hay diálogo.
 */
class InstagramDmAutoReplyService
{
    public function __construct(
        private readonly InstagramService $instagram,
        private readonly OutboundMessageService $outbound,
    ) {}

    /**
     * Decide si responder al mensaje recién guardado, y responde.
     *
     * Se llama DESPUÉS de persistir el entrante: la decisión depende de cuántos
     * mensajes tiene la conversación, y contarlos antes daría uno de menos.
     *
     * No lanza nunca: un fallo de la automatización no puede tumbar el
     * procesamiento del webhook, o Meta reintentaría y el mensaje del cliente
     * se duplicaría en la bandeja.
     */
    public function responder(Conversation $conversation, Message $entrante, ?string $igsid): void
    {
        try {
            $this->intentar($conversation, $entrante, $igsid);
        } catch (\Throwable $e) {
            Log::error('[Instagram] Falló la respuesta automática al DM', [
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    private function intentar(Conversation $conversation, Message $entrante, ?string $igsid): void
    {
        $config = InstagramDmSetting::actual();

        if (! $config->is_active) {
            return;
        }

        if ($igsid === null || trim($igsid) === '') {
            // Sin IGSID no hay a quién responder. No se registra: no es una
            // decisión de negocio, es un payload incompleto.
            return;
        }

        $texto = $entrante->body;

        $motivo = $this->motivoParaNoResponder($config, $conversation, $entrante, $igsid, $texto);

        if ($motivo !== null) {
            // Los cortes silenciosos (los que pasan todo el tiempo y son
            // normales) no se registran: llenarían la tabla de ruido y
            // esconderían los casos que sí hay que mirar.
            if (! $this->esCorteRutinario($motivo)) {
                $this->registrar($igsid, $conversation, $texto, InstagramDmReply::STATUS_SKIPPED, $motivo);
            }

            return;
        }

        $cuerpo = $config->respuesta();

        if ($cuerpo === null) {
            $this->registrar(
                $igsid,
                $conversation,
                $texto,
                InstagramDmReply::STATUS_SKIPPED,
                'La respuesta configurada apunta a una plantilla o menú que ya no sirve',
            );

            return;
        }

        $opciones = $config->opcionesDeMenu();

        // Con menú se envía directo por la API y no por la cola: las burbujas
        // son parte del mismo mensaje y OutboundMessageService solo sabe
        // mandar texto. Ver el comentario de registrarEnvioDeMenu().
        if ($opciones !== []) {
            $this->enviarMenu($config, $conversation, $igsid, $cuerpo, $opciones, $texto);

            return;
        }

        // Texto o plantilla: por el servicio común, para que quede persistido
        // como 'pending' y visible en el chat aunque la cola esté caída.
        $mensaje = $this->outbound->queueTextMessage($conversation, $cuerpo);

        $this->registrar(
            $igsid,
            $conversation,
            $texto,
            InstagramDmReply::STATUS_SENT,
            null,
            $mensaje->id,
        );

        Log::info('[Instagram] Respuesta automática encolada', [
            'conversation_id' => $conversation->id,
            'response_type'   => $config->response_type,
        ]);
    }

    /**
     * Por qué NO hay que responder este mensaje, o null si hay que hacerlo.
     *
     * El orden va de lo más barato a lo más caro: los cortes que se resuelven
     * mirando el propio mensaje van primero, y las consultas a la base
     * después.
     */
    private function motivoParaNoResponder(
        InstagramDmSetting $config,
        Conversation $conversation,
        Message $entrante,
        string $igsid,
        ?string $texto,
    ): ?string {
        // Un mensaje sin texto (una foto, un audio, un sticker) no se puede
        // filtrar por palabras clave ni se responde bien con un menú de
        // cursos. Queda para un agente.
        if ($texto === null || trim($texto) === '') {
            return 'El mensaje no trae texto';
        }

        if (! $config->coincide($texto)) {
            return 'El mensaje no contiene ninguna palabra clave';
        }

        // Solo el primer mensaje de la conversación. Se cuenta sobre los
        // ENTRANTES: los salientes incluyen las respuestas automáticas
        // anteriores y un saludo del agente, que no deberían contar como
        // "la persona ya venía hablando".
        if (! $config->respondeSiempre()) {
            $entrantes = $conversation->messages()
                ->where('direction', Message::DIRECTION_INBOUND)
                ->where('id', '!=', $entrante->id)
                ->count();

            if ($entrantes > 0) {
                return 'No es el primer mensaje de la conversación';
            }
        }

        // Un agente ya tomó el caso: el robot se aparta. Es el corte que
        // evita la peor cara de la automatización —el menú cayendo en medio
        // de una conversación humana.
        if ($config->skip_if_assigned && $this->tieneAgente($conversation)) {
            return 'Un agente ya está atendiendo el caso';
        }

        if ($config->cooldown_minutes > 0) {
            $ultimo = InstagramDmReply::ultimoEnvioA($igsid);

            if ($ultimo !== null
                && $ultimo->created_at?->gt(now()->subMinutes($config->cooldown_minutes))) {
                return 'Se le respondió hace menos de ' . $config->cooldown_minutes . ' minutos';
            }
        }

        if ($config->daily_limit > 0 && InstagramDmReply::enviadosHoy() >= $config->daily_limit) {
            return 'Se alcanzó el tope de ' . $config->daily_limit . ' respuestas por día';
        }

        return null;
    }

    /**
     * ¿El caso ya lo tomó alguien del equipo?
     *
     * Se mira el ticket y no la conversación: la asignación vive ahí. Sin
     * ticket todavía, nadie lo tomó.
     */
    private function tieneAgente(Conversation $conversation): bool
    {
        return $conversation->ticket()->whereNotNull('assigned_user_id')->exists();
    }

    /**
     * Cortes que pasan todo el tiempo y no vale la pena registrar.
     *
     * Son los de funcionamiento normal: casi todos los mensajes que llegan no
     * son el primero de su conversación. Guardarlos convertiría la tabla en un
     * log de toda la bandeja y escondería los casos que sí hay que revisar
     * (una plantilla rota, el tope diario alcanzado).
     */
    private function esCorteRutinario(string $motivo): bool
    {
        return str_starts_with($motivo, 'No es el primer mensaje')
            || str_starts_with($motivo, 'Se le respondió hace menos');
    }

    /**
     * Envía un menú de opciones y lo registra.
     *
     * Va directo a la API en vez de por OutboundMessageService porque las
     * burbujas viajan dentro del mismo mensaje y ese servicio solo sabe
     * encolar texto. El precio es que si la cola de Meta falla acá no hay
     * reintento; a cambio, la persona recibe las opciones —que es todo el
     * punto del menú— o no recibe nada, en vez de un texto suelto sin forma
     * de responder.
     *
     * @param list<array{content_type: string, title: string, payload: string}> $opciones
     */
    private function enviarMenu(
        InstagramDmSetting $config,
        Conversation $conversation,
        string $igsid,
        string $cuerpo,
        array $opciones,
        ?string $texto,
    ): void {
        $resultado = $this->instagram->sendQuickReplies($igsid, $cuerpo, $opciones);

        if (! ($resultado['success'] ?? false)) {
            $this->registrar(
                $igsid,
                $conversation,
                $texto,
                InstagramDmReply::STATUS_FAILED,
                $this->mensajeDeError($resultado['error'] ?? null),
            );

            return;
        }

        $config->quickReplyMenu?->increment('sends');

        // El mensaje se crea después del envío y ya como 'sent': no pasó por
        // la cola, así que un 'pending' acá sería mentira y el reintento
        // manual desde el chat lo mandaría dos veces.
        $mensaje = $conversation->messages()->create([
            'direction'   => Message::DIRECTION_OUTBOUND,
            'channel'     => 'instagram',
            'type'        => Message::TYPE_TEXT,
            'body'        => $cuerpo,
            'status'      => Message::STATUS_SENT,
            'external_id' => $this->texto($resultado['message_id'] ?? null),
            'sent_at'     => now(),
        ]);

        $this->registrar(
            $igsid,
            $conversation,
            $texto,
            InstagramDmReply::STATUS_SENT,
            null,
            $mensaje->id,
        );

        Log::info('[Instagram] Menú automático enviado por DM', [
            'conversation_id' => $conversation->id,
            'opciones'        => count($opciones),
        ]);
    }

    private function registrar(
        string $igsid,
        Conversation $conversation,
        ?string $texto,
        string $status,
        ?string $motivo = null,
        ?int $messageId = null,
    ): void {
        InstagramDmReply::create([
            'sender_igsid'    => $igsid,
            'sender_username' => $conversation->contact?->instagram_handle
                ?? $conversation->contact?->display_name,
            'incoming_text'   => $texto,
            'status'          => $status,
            'skip_reason'     => $motivo,
            'message_id'      => $messageId,
        ]);
    }

    /** El mensaje de error de Meta, acortado para la columna. */
    private function mensajeDeError(mixed $error): string
    {
        $mensaje = is_array($error) ? ($error['message'] ?? null) : null;

        return mb_substr(
            is_string($mensaje) && trim($mensaje) !== '' ? $mensaje : 'Meta rechazó el envío',
            0,
            255,
        );
    }

    private function texto(mixed $valor): ?string
    {
        return is_string($valor) && trim($valor) !== '' ? $valor : null;
    }
}
