<?php

declare(strict_types=1);

namespace App\Services\Meta;

use App\Models\Contact;
use App\Models\InstagramCommentReply;
use App\Models\InstagramCommentSetting;
use App\Models\Message;
use App\Services\ContactService;
use App\Services\ConversationService;
use App\Services\TicketService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Responde a quien comenta un post de Instagram, por dos vías a la vez.
 *
 *   1. Un comentario PÚBLICO debajo del suyo ("te escribimos al privado").
 *   2. Un DM privado con el mensaje comercial.
 *
 * Las dos son independientes y se pueden activar por separado. El aviso público
 * existe porque el DM, para quien no sigue la cuenta, cae en la carpeta de
 * Solicitudes -- que nadie mira. Y porque el resto de la gente que lee los
 * comentarios ve que la cuenta responde.
 *
 * ORDEN DELIBERADO: el aviso público va primero. Es barato, es lo que la persona
 * ve sin salir del post, y no depende de que el DM salga. Si el privado falla o
 * se frena por el tope diario, al menos quedó dicho que la cuenta responde.
 *
 * El webhook de comentarios llega por `entry.changes` con `field: "comments"`,
 * NO por `entry.messaging` como los mensajes y los postbacks. Ver
 * InstagramService::processWebhookPayload().
 *
 * Sobre los límites de Meta (un DM por comentario, ventana de 7 días, solo
 * comentarios de primer nivel) ver InstagramService::sendCommentReply() y
 * replyToComment().
 */
class InstagramCommentService
{
    public function __construct(
        private ContactService $contactService,
        private ConversationService $conversationService,
        private TicketService $ticketService,
        private InstagramService $instagram,
    ) {}

    /**
     * Procesa un cambio `comments` del webhook.
     *
     * Nunca lanza: lo llama el Job que procesa el webhook completo, y un
     * comentario que no se puede responder no debe tumbar el resto del payload
     * (que puede traer mensajes reales de clientes esperando).
     *
     * @param  array<string, mixed> $value El nodo `value` del cambio.
     */
    public function handleComment(array $value): void
    {
        $commentId = $this->texto($value['id'] ?? null);

        if ($commentId === null) {
            Log::warning('[Instagram] Cambio de comentario sin id', $value);

            return;
        }

        // El @ y el IGSID de quien comentó. Meta usa `from` en el webhook de
        // comentarios (no `sender` como en los mensajes).
        $igsid    = $this->texto($value['from']['id'] ?? null);
        $username = $this->texto($value['from']['username'] ?? null);
        $texto    = $this->texto($value['text'] ?? null);

        // El id del post. Meta lo manda en `media.id`.
        $mediaId = $this->texto($value['media']['id'] ?? null);

        // IDEMPOTENCIA PRIMERO. Se reserva el comment_id antes de decidir
        // cualquier cosa: Meta reintenta el webhook si no recibe un 200 a
        // tiempo, y dos entregas del mismo comentario llegarían a este método
        // en paralelo. El índice único de la tabla es el que resuelve la
        // carrera; el catch de abajo es la otra mitad.
        try {
            $registro = InstagramCommentReply::create([
                'comment_id'         => $commentId,
                'media_id'           => $mediaId,
                'commenter_igsid'    => $igsid,
                'commenter_username' => $username,
                'comment_text'       => $texto,
                'status'             => InstagramCommentReply::STATUS_SKIPPED,
                'skip_reason'        => 'En proceso',
            ]);
        } catch (QueryException $e) {
            // Violación del índice único: este comentario ya se evaluó. Es el
            // camino esperado en un reintento de Meta, así que se registra en
            // info y no en warning para no ensuciar el log.
            Log::info('[Instagram] Comentario ya procesado; no se reenvía el DM', [
                'comment_id' => $commentId,
            ]);

            return;
        }

        // Dos preguntas distintas, en este orden:
        //
        //   1. ¿Hay que dejar en paz este comentario? (la cuenta propia, un
        //      hilo, un comentario que no nos interesa)
        //   2. Si sí hay que atenderlo, ¿hay un DM que mandar?
        //
        // Separarlas importa porque el aviso público se publica en los casos en
        // que el DM no sale por motivos NUESTROS (el tope diario, un fallo de
        // Meta): la persona ya comentó y dejarla sin ninguna señal es peor.
        $motivo = $this->motivoParaIgnorar($value, $igsid);

        if ($motivo !== null) {
            $registro->forceFill([
                'status'      => InstagramCommentReply::STATUS_SKIPPED,
                'skip_reason' => $motivo,
            ])->save();

            return;
        }

        $config = InstagramCommentSetting::actual();

        // El aviso público va PRIMERO y con su propia condición: es barato, es
        // lo que la persona ve sin salir del post, y no depende de que el DM
        // haya salido. Si el DM falla después, al menos quedó dicho que la
        // cuenta responde.
        $this->publicarAviso($config, $registro, $commentId);

        $motivoDm = $this->motivoParaNoEnviarDm($config, $texto);

        if ($motivoDm !== null) {
            $registro->forceFill([
                'status'      => InstagramCommentReply::STATUS_SKIPPED,
                'skip_reason' => $motivoDm,
            ])->save();

            return;
        }

        // Ya validado en motivoParaNoEnviarDm(); el ?? '' es para el analizador
        // estático, que no puede saberlo.
        $cuerpo = $config->respuesta() ?? '';

        // El DM se manda por comment_id y NO por el IGSID: la ventana la abre
        // el comentario público, no una conversación previa. Enviar a
        // recipient.id fallaría con "no messaging window" para alguien que
        // nunca escribió al negocio.
        $resultado = $this->instagram->sendCommentReply($commentId, $cuerpo);

        if (! ($resultado['success'] ?? false)) {
            $registro->forceFill([
                'status'      => InstagramCommentReply::STATUS_FAILED,
                'skip_reason' => $this->mensajeDeError($resultado['error'] ?? null),
            ])->save();

            return;
        }

        // Recién acá se toca el CRM. El orden importa: si se creara la
        // conversación antes del envío, un fallo de Meta dejaría un chat
        // fantasma en la bandeja con un mensaje que nadie recibió.
        $mensaje = $this->registrarEnElCrm(
            $igsid,
            $username,
            $cuerpo,
            $texto,
            $commentId,
            $mediaId,
            $this->texto($resultado['message_id'] ?? null),
        );

        $registro->forceFill([
            'status'      => InstagramCommentReply::STATUS_SENT,
            'skip_reason' => null,
            'message_id'  => $mensaje?->id,
        ])->save();

        Log::info('[Instagram] DM de bienvenida enviado por comentario', [
            'comment_id' => $commentId,
            'username'   => $username,
        ]);
    }

    /**
     * Por qué hay que dejar este comentario EN PAZ, o null si hay que atenderlo.
     *
     * Son los cortes que no dependen de la configuración del mensaje: cosas a
     * las que no se le habla, pase lo que pase. Si alguno aplica, no sale ni el
     * DM ni el aviso público.
     *
     * Devuelve el motivo en texto para guardarlo en la tabla: un "skipped" sin
     * explicación obliga a releer el código para entenderlo.
     *
     * @param  array<string, mixed> $value
     */
    private function motivoParaIgnorar(array $value, ?string $igsid): ?string
    {
        $config = InstagramCommentSetting::actual();

        // Todo apagado: ni DM ni aviso. Se comprueban los dos porque son
        // independientes -- se puede querer solo el aviso público.
        if (! $config->is_active && ! $config->public_reply_active) {
            return 'La automatización está desactivada';
        }

        // Un comentario de la propia cuenta del negocio. Sin este corte, cada
        // vez que un agente responde un comentario el CRM le mandaría un DM de
        // bienvenida a la propia empresa -- y se respondería a sí mismo en
        // público, en un bucle visible debajo del post.
        $cuentaPropia = $this->texto(config('services.meta.instagram_account_id'));

        if ($igsid !== null && $cuentaPropia !== null && $igsid === $cuentaPropia) {
            return 'Es un comentario de la propia cuenta';
        }

        // Respuestas a otros comentarios (hilos). `parent_id` presente significa
        // que es una réplica dentro de un hilo, no un comentario nuevo al post.
        //
        // Para el aviso público hay una razón extra y dura: la API cuelga las
        // respuestas del comentario PADRE, así que responder dentro de un hilo
        // publicaría el aviso debajo del comentario original, duplicándolo.
        if ($this->texto($value['parent_id'] ?? null) !== null) {
            return 'Es una respuesta dentro de un hilo, no un comentario al post';
        }

        // Sin IGSID no hay a quién mandarle nada por el CRM. El DM en sí va por
        // comment_id, pero sin el id del autor no se puede crear el contacto ni
        // atribuir la conversación, y un chat sin contacto rompe la bandeja.
        if ($igsid === null) {
            return 'El webhook no trajo el id de quien comentó';
        }

        return null;
    }

    /**
     * Por qué no sale el DM, o null si hay que enviarlo.
     *
     * Estos motivos son NUESTROS (está apagado, no hay texto, se llegó al tope)
     * y por eso el aviso público ya se publicó antes de llegar acá: la persona
     * comentó y merece una señal, aunque el privado no salga.
     */
    private function motivoParaNoEnviarDm(InstagramCommentSetting $config, ?string $texto): ?string
    {
        if (! $config->is_active) {
            return 'El mensaje privado está desactivado';
        }

        if (! $config->coincide($texto)) {
            return 'El comentario no coincide con las palabras clave';
        }

        if ($config->respuesta() === null) {
            return 'No hay texto configurado para responder';
        }

        // El tope diario se comprueba al final: es el más caro (una consulta) y
        // no tiene sentido pagarlo para un comentario que se iba a omitir igual.
        $limite = $config->daily_limit;

        if ($limite > 0) {
            $enviadosHoy = InstagramCommentReply::query()
                ->sent()
                ->where('created_at', '>=', now()->startOfDay())
                ->count();

            if ($enviadosHoy >= $limite) {
                return "Se alcanzó el tope diario de {$limite} mensajes";
            }
        }

        return null;
    }

    /**
     * Publica el aviso público debajo del comentario.
     *
     * Nunca lanza y nunca marca el registro como fallido: el aviso es un extra
     * y el DM es lo que importa. Si esto falla, se guarda el motivo en su propia
     * columna y el flujo sigue.
     *
     * El id de la respuesta publicada hace de marca de idempotencia: con un
     * valor ahí, un reintento del webhook no vuelve a comentar debajo del post.
     */
    private function publicarAviso(
        InstagramCommentSetting $config,
        InstagramCommentReply $registro,
        string $commentId,
    ): void {
        $aviso = $config->avisoPublico();

        if ($aviso === null) {
            return;
        }

        // Ya publicado en una entrega anterior del mismo webhook.
        if ($registro->public_reply_id !== null) {
            return;
        }

        $resultado = $this->instagram->replyToComment($commentId, $aviso);

        if (! ($resultado['success'] ?? false)) {
            $registro->forceFill([
                'public_reply_error' => $this->mensajeDeError($resultado['error'] ?? null),
            ])->save();

            return;
        }

        $registro->forceFill([
            'public_reply_id'    => $this->texto($resultado['reply_id'] ?? null),
            'public_reply_error' => null,
        ])->save();
    }

    /**
     * Crea el contacto, la conversación y el mensaje saliente en el CRM.
     *
     * El DM ya salió: acá solo se refleja para que el agente vea el hilo
     * completo y pueda seguir la conversación si la persona responde.
     *
     * Nunca lanza. Si esto falla, el cliente ya recibió el mensaje y perderlo
     * de la bandeja es malo, pero marcar el intento como fallido sería peor:
     * el próximo webhook volvería a enviar y Meta ya lo rechazaría.
     */
    private function registrarEnElCrm(
        ?string $igsid,
        ?string $username,
        string $cuerpo,
        ?string $comentario,
        string $commentId,
        ?string $mediaId,
        ?string $externalId,
    ): ?Message {
        if ($igsid === null) {
            return null;
        }

        try {
            $contact = $this->contactService->findOrCreate('instagram', $igsid, [
                'display_name'     => $username,
                'instagram_handle' => $username,
            ]);

            $conversation = $this->conversationService->getOrOpenActive($contact);

            $mensaje = Message::create([
                'conversation_id' => $conversation->id,
                'sender_user_id'  => null,
                'direction'       => Message::DIRECTION_OUTBOUND,
                'channel'         => Contact::CHANNEL_INSTAGRAM,
                'external_id'     => $externalId,
                'type'            => Message::TYPE_TEXT,
                'body'            => $cuerpo,
                // El comentario que lo disparó queda acá y no en el body: el
                // body es lo que el cliente recibió, y mezclarlos haría que el
                // chat mostrara texto que nunca se envió.
                'meta_payload'    => [
                    'trigger'      => 'instagram_comment',
                    'comment_id'   => $commentId,
                    'media_id'     => $mediaId,
                    'comment_text' => $comentario,
                ],
                'status'          => Message::STATUS_SENT,
                'sent_at'         => now(),
            ]);

            // Un ticket para que el lead no se quede sin dueño en la bandeja.
            $this->ticketService->ensureTicketExists($conversation);

            $this->conversationService->updateLastMessageAt($conversation);
            $this->conversationService->refreshWindowStatus($conversation);

            return $mensaje;
        } catch (\Throwable $e) {
            Log::error('[Instagram] El DM por comentario salió pero no se pudo reflejar en el CRM', [
                'comment_id' => $commentId,
                'igsid'      => $igsid,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Un string no vacío, o null. Los ids de Meta llegan como int o string. */
    private function texto(mixed $valor): ?string
    {
        if ($valor === null || is_array($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto !== '' ? $texto : null;
    }

    /** El error de Meta como texto para guardarlo en `skip_reason`. */
    private function mensajeDeError(mixed $error): string
    {
        if (is_string($error) && trim($error) !== '') {
            return mb_substr($error, 0, 255);
        }

        if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
            return mb_substr($error['message'], 0, 255);
        }

        return 'Meta rechazó el envío';
    }
}
