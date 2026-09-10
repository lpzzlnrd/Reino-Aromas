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
 * Responde con un DM de bienvenida a quien comenta un post de Instagram.
 *
 * El webhook de comentarios llega por `entry.changes` con `field: "comments"`,
 * NO por `entry.messaging` como los mensajes y los postbacks. Ver
 * InstagramService::processWebhookPayload().
 *
 * Sobre los límites de Meta (un DM por comentario, ventana de 7 días) ver
 * InstagramService::sendCommentReply().
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

        $motivo = $this->motivoParaOmitir($value, $igsid, $texto);

        if ($motivo !== null) {
            $registro->forceFill([
                'status'      => InstagramCommentReply::STATUS_SKIPPED,
                'skip_reason' => $motivo,
            ])->save();

            return;
        }

        $config = InstagramCommentSetting::actual();

        // Ya validado en motivoParaOmitir(); el ?? '' es para el analizador
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
     * Por qué NO hay que responder este comentario, o null si sí hay que hacerlo.
     *
     * Devuelve el motivo en texto para guardarlo en la tabla: un "skipped" sin
     * explicación obliga a releer el código para entenderlo.
     *
     * @param  array<string, mixed> $value
     */
    private function motivoParaOmitir(array $value, ?string $igsid, ?string $texto): ?string
    {
        $config = InstagramCommentSetting::actual();

        if (! $config->is_active) {
            return 'La automatización está desactivada';
        }

        // Un comentario de la propia cuenta del negocio. Sin este corte, cada
        // vez que un agente responde un comentario el CRM le mandaría un DM de
        // bienvenida a la propia empresa.
        $cuentaPropia = $this->texto(config('services.meta.instagram_account_id'));

        if ($igsid !== null && $cuentaPropia !== null && $igsid === $cuentaPropia) {
            return 'Es un comentario de la propia cuenta';
        }

        // Respuestas a otros comentarios (hilos). `parent_id` presente significa
        // que es una réplica dentro de un hilo, no un comentario nuevo al post,
        // y el negocio quiere saludar a quien comenta la publicación.
        if ($this->texto($value['parent_id'] ?? null) !== null) {
            return 'Es una respuesta dentro de un hilo, no un comentario al post';
        }

        // Sin IGSID no hay a quién mandarle nada por el CRM. El DM en sí va por
        // comment_id, pero sin el id del autor no se puede crear el contacto ni
        // atribuir la conversación, y un chat sin contacto rompe la bandeja.
        if ($igsid === null) {
            return 'El webhook no trajo el id de quien comentó';
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
