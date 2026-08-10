<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreMessageRequest;
use App\Http\Requests\StoreTemplateMessageRequest;
use App\Models\Conversation;
use App\Models\Template;
use App\Services\OutboundMessageService;
use App\Services\TemplateService;
use Illuminate\Http\JsonResponse;

/**
 * Mensajes salientes del CRM.
 *
 * Reemplaza el envío que routes/api.php cableaba a FacebookMessageController
 * para todos los canales. Aquí no hay lógica de canal: OutboundMessageService
 * elige el Job según el contacto.
 */
class MessageController extends MetaBaseController
{
    public function __construct(
        private readonly OutboundMessageService $outboundMessages,
        private readonly TemplateService $templates,
    ) {}

    /**
     * POST /api/meta/conversations/{conversation}/messages
     *
     * Encola el mensaje y responde 202: el envío real ocurre en la cola.
     * El front debe pintar el mensaje en estado `pending` y esperar el cambio
     * a `sent` (hoy por recarga; en Semana 4 por broadcasting).
     */
    public function store(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        try {
            $message = $this->outboundMessages->queueTextMessage(
                conversation: $conversation,
                body: $request->validated()['body'],
                sender: $request->user(),
            );
        } catch (\RuntimeException $e) {
            // Canal no soportado o conversación sin contacto: es un problema
            // del dato, no del servidor.
            return $this->jsonError($e->getMessage(), 422);
        }

        return $this->jsonSuccess([
            'queued'          => true,
            'conversation_id' => $conversation->id,
            'message'         => [
                'id'         => $message->id,
                'direction'  => $message->direction,
                'channel'    => $message->channel,
                'type'       => $message->type,
                'body'       => $message->body,
                'status'     => $message->status,
                'created_at' => $message->created_at->toIso8601String(),
            ],
        ], 202);
    }

    /**
     * POST /api/meta/conversations/{conversation}/messages/template/{template}
     *
     * Envía una plantilla ya renderizada con los datos del contacto. Es el
     * endpoint que consume el selector de plantillas del chat: evita que el
     * front tenga que renderizar el texto y volver a postearlo, lo que
     * permitiría enviar un cuerpo distinto al de la plantilla.
     */
    public function storeFromTemplate(
        StoreTemplateMessageRequest $request,
        Conversation $conversation,
        Template $template,
    ): JsonResponse {
        if (! $template->is_active) {
            return $this->jsonError('La plantilla está desactivada.', 422);
        }

        $conversation->loadMissing(['contact', 'ticket']);

        // Se renderiza en el servidor y no se acepta el texto del front: así el
        // cuerpo enviado es siempre el de la plantilla, y el contador de uso
        // cuenta envíos reales.
        $body = $this->templates->render(
            $template->body,
            $this->templates->valoresPara(
                $conversation->contact,
                $conversation->ticket,
                $request->user()?->name,
            ),
        );

        try {
            $message = $this->outboundMessages->queueTextMessage(
                conversation: $conversation,
                body: $body,
                sender: $request->user(),
            );
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        // El contador de uso se incrementa solo si el mensaje quedó encolado.
        $this->templates->registrarUso($template, $request->user()?->id);

        return $this->jsonSuccess([
            'queued'          => true,
            'conversation_id' => $conversation->id,
            'template_id'     => $template->id,
            'message'         => [
                'id'         => $message->id,
                'direction'  => $message->direction,
                'channel'    => $message->channel,
                'type'       => $message->type,
                'body'       => $message->body,
                'status'     => $message->status,
                'created_at' => $message->created_at->toIso8601String(),
            ],
        ], 202);
    }
}
