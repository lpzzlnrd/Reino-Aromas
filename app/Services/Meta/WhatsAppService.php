<?php

namespace App\Services\Meta;

use App\Jobs\SendWhatsAppFlowJob;
use App\Models\Message;
use App\Models\Conversation;
use App\Services\ContactService;
use App\Services\ConversationService;
use App\Services\TicketService;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public function __construct(
        private ContactService $contactService,
        private ConversationService $conversationService,
        private TicketService $ticketService,
        private ActivityLogService $activityLogService,
        private MetaCredentials $credentials = new MetaCredentials(),
    ) {}

    /**
     * Procesa el payload completo del webhook de WhatsApp.
     * Llamado desde ProcessWhatsAppMessageJob.
     */
    public function processWebhookPayload(array $payload): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? '';
                $value = $change['value'] ?? [];

                match ($field) {
                    'messages'           => $this->procesarMensajes($value),
                    'smb_message_echoes' => $this->procesarEcos($value),
                    'history'            => $this->procesarHistorial($value),
                    default              => null,
                };
            }
        }
    }

    /**
     * Eventos del campo `messages`: lo que escriben los clientes y los
     * acuses de recibo de lo que mandamos nosotros.
     */
    private function procesarMensajes(array $value): void
    {
        foreach ($value['messages'] ?? [] as $message) {
            $this->handleMessageEvent($message, $value['contacts'] ?? []);
        }

        foreach ($value['statuses'] ?? [] as $status) {
            $this->handleStatusUpdate($status);
        }
    }

    /**
     * Eventos del campo `smb_message_echoes` (coexistencia): mensajes que el
     * negocio mandó DESDE LA APP DEL TELÉFONO, no desde el CRM.
     *
     * Sin esto la bandeja muestra lo que preguntan los clientes pero no lo que
     * el negocio ya les respondió, y un agente del CRM vuelve a contestar algo
     * que estaba atendido.
     *
     * OJO: acá el `from` es el número del negocio y el `to` es el cliente —
     * al revés que en un mensaje entrante. El contacto se resuelve por `to`.
     */
    private function procesarEcos(array $value): void
    {
        foreach ($value['message_echoes'] ?? [] as $eco) {
            $this->handleEchoEvent($eco);
        }
    }

    /**
     * Eventos del campo `history` (coexistencia): hasta 6 meses de chats
     * previos que Meta sincroniza al vincular el número, en lotes.
     *
     * Se guardan los mensajes para que la conversación se vea completa, pero
     * NO se crean tickets ni se dispara el Flow de bienvenida: son chats que
     * el negocio ya atendió por teléfono. Ver handleHistoryMessage().
     */
    private function procesarHistorial(array $value): void
    {
        foreach ($value['history'] ?? [] as $lote) {
            $metadata = $lote['metadata'] ?? [];

            Log::info('[WhatsApp] Sincronizando historial de coexistencia', [
                'phase'       => $metadata['phase'] ?? null,
                'chunk_order' => $metadata['chunk_order'] ?? null,
                'progress'    => $metadata['progress'] ?? null,
                'threads'     => count($lote['threads'] ?? []),
            ]);

            foreach ($lote['threads'] ?? [] as $thread) {
                $waId = $thread['id'] ?? null;

                if ($waId === null) {
                    continue;
                }

                foreach ($thread['messages'] ?? [] as $mensaje) {
                    $this->handleHistoryMessage($mensaje, $waId);
                }
            }
        }
    }

    /**
     * Maneja un mensaje entrante individual del webhook.
     */
    private function handleMessageEvent(array $message, array $contacts): void
    {
        $senderId  = $message['from'] ?? null;
        $messageId = $message['id'] ?? null;

        if (!$senderId || !$messageId) {
            Log::warning('[WhatsApp] Mensaje sin from o id', $message);
            return;
        }

        // Idempotencia: ignorar si ya procesamos este mensaje
        if (Message::where('external_id', $messageId)->exists()) {
            Log::info("[WhatsApp] Mensaje duplicado ignorado: {$messageId}");
            return;
        }

        // Buscar nombre del contacto en el array de contacts del payload
        $displayName = $this->resolveDisplayName($senderId, $contacts);

        $contact = $this->contactService->findOrCreate('whatsapp', $senderId, [
            'display_name' => $displayName,
            'phone'        => $senderId,
        ]);

        // Se captura ANTES de tocar la conversación: findOrCreate ya guardó el
        // contacto, así que wasRecentlyCreated es la única señal de que este
        // lead es nuevo y todavía no ha hablado con nadie.
        $esContactoNuevo = $contact->wasRecentlyCreated;

        $conversation = $this->conversationService->getOrOpenActive($contact);

        $this->storeInboundMessage($conversation, $message, $messageId);

        // El ticket se crea SIEMPRE, antes de intentar el Flow. Es la red de
        // seguridad: si el Flow falla o el lead lo cierra sin completarlo, el
        // ticket sigue en la bandeja para que un agente lo atienda.
        $this->ticketService->ensureTicketExists($conversation);

        $this->conversationService->updateLastMessageAt($conversation);
        $this->conversationService->refreshWindowStatus($conversation);

        // Flow de bienvenida: reemplaza el saludo y la pregunta de ciudad que
        // hoy el agente manda a mano. Solo para leads nuevos — a un contacto
        // conocido que vuelve a escribir se le atiende directo.
        if ($esContactoNuevo) {
            SendWhatsAppFlowJob::dispatch($conversation->id, $senderId);
        }
    }

    /**
     * Un mensaje que el negocio mandó desde la app del teléfono (coexistencia).
     *
     * Se guarda como `outbound` con `sender_user_id` en null: salió del negocio
     * pero ningún usuario del CRM lo escribió, así que atribuirlo a alguien
     * sería mentira. Ese null es la marca de "vino del teléfono".
     *
     * No crea ticket ni dispara Flow: el negocio ya está en la conversación.
     */
    private function handleEchoEvent(array $eco): void
    {
        // El destinatario es el cliente. `recipient_id` es lo que manda el
        // payload de eco; `to` queda como respaldo por si cambia el nombre.
        $clienteId = $eco['recipient_id'] ?? $eco['to'] ?? null;
        $messageId = $eco['id'] ?? null;

        if (! $clienteId || ! $messageId) {
            Log::warning('[WhatsApp] Eco sin destinatario o id', $eco);

            return;
        }

        if (Message::where('external_id', $messageId)->exists()) {
            return;
        }

        $contact = $this->contactService->findOrCreate('whatsapp', $clienteId, [
            'phone' => $clienteId,
        ]);

        $conversation = $this->conversationService->getOrOpenActive($contact);

        $this->storeEchoMessage($conversation, $eco, $messageId);

        $this->conversationService->updateLastMessageAt($conversation);
        $this->conversationService->refreshWindowStatus($conversation);
    }

    /**
     * Un mensaje del historial de 6 meses que Meta sincroniza al vincular.
     *
     * A diferencia de handleMessageEvent(), esto NO crea tickets ni dispara el
     * Flow de bienvenida: son conversaciones que el negocio ya atendió por
     * teléfono. Crear tickets aquí llenaría el Kanban de trabajo ya resuelto, y
     * el Flow le llegaría a clientes viejos como si fueran leads nuevos.
     *
     * El `from` decide la dirección: si es el número del negocio el mensaje es
     * saliente; si es el del cliente, entrante.
     */
    private function handleHistoryMessage(array $mensaje, string $waId): void
    {
        $messageId = $mensaje['id'] ?? null;

        if ($messageId === null) {
            return;
        }

        if (Message::where('external_id', $messageId)->exists()) {
            return;
        }

        // Los media del historial llegan como `media_placeholder`: Meta no
        // reenvía el archivo, solo avisa que ahí hubo uno. Se guarda el
        // marcador para que la conversación no quede con huecos.
        $esEntrante = ($mensaje['from'] ?? null) === $waId;

        $contact = $this->contactService->findOrCreate('whatsapp', $waId, [
            'phone' => $waId,
        ]);

        $conversation = $this->conversationService->getOrOpenActive($contact);

        $type = $this->resolveMessageType($mensaje);

        $mensajeGuardado = new Message([
            'conversation_id' => $conversation->id,
            'sender_user_id'  => null,
            'direction'       => $esEntrante ? 'inbound' : 'outbound',
            'channel'         => 'whatsapp',
            'external_id'     => $messageId,
            'type'            => $type,
            'body'            => $this->extractBody($mensaje, $type),
            'media_url'       => $this->extractMediaUrl($mensaje, $type),
            'meta_payload'    => $mensaje,
            'status'          => $this->estadoDesdeHistorial($mensaje),
        ]);

        // El timestamp de Meta viene en segundos. Va asignado aparte porque
        // `created_at` no está en $fillable: pasarlo por create() lo descarta en
        // silencio y el historial entero queda fechado hoy, con chats de hace
        // meses apareciendo arriba de los de ayer.
        //
        // Solo created_at: el modelo declara UPDATED_AT = null y la tabla no
        // tiene esa columna.
        if (isset($mensaje['timestamp'])) {
            $mensajeGuardado->created_at = now()->setTimestamp((int) $mensaje['timestamp']);
        }

        $mensajeGuardado->save();
    }

    /**
     * Traduce el `history_context.status` de Meta (en mayúsculas) al enum
     * interno de mensajes.
     */
    private function estadoDesdeHistorial(array $mensaje): string
    {
        $estado = $mensaje['history_context']['status'] ?? null;

        return match ($estado) {
            'READ'      => 'read',
            'DELIVERED' => 'delivered',
            'PLAYED'    => 'read',
            'SENT'      => 'sent',
            'ERROR'     => 'failed',
            default     => 'delivered',
        };
    }

    /**
     * Persiste un eco (mensaje mandado desde el teléfono del negocio).
     */
    private function storeEchoMessage(Conversation $conversation, array $eco, string $externalId): Message
    {
        $type = $this->resolveMessageType($eco);

        return Message::create([
            'conversation_id' => $conversation->id,
            'sender_user_id'  => null,
            'direction'       => 'outbound',
            'channel'         => 'whatsapp',
            'external_id'     => $externalId,
            'type'            => $type,
            'body'            => $this->extractBody($eco, $type),
            'media_url'       => $this->extractMediaUrl($eco, $type),
            'meta_payload'    => $eco,
            'status'          => 'sent',
            'sent_at'         => isset($eco['timestamp'])
                ? now()->setTimestamp((int) $eco['timestamp'])
                : now(),
        ]);
    }

    /**
     * Actualiza el estado de un mensaje saliente (delivered, read, failed).
     */
    private function handleStatusUpdate(array $status): void
    {
        $externalId = $status['id'] ?? null;
        $newStatus  = $status['status'] ?? null;

        if (!$externalId || !$newStatus) {
            return;
        }

        $message = Message::where('external_id', $externalId)->first();

        if (!$message) {
            return;
        }

        $updates = match ($newStatus) {
            'delivered' => ['status' => 'delivered', 'delivered_at' => now()],
            'read'      => ['status' => 'read', 'read_at' => now()],
            'failed'    => [
                'status'        => 'failed',
                'failed_reason' => $status['errors'][0]['title'] ?? 'Unknown error',
            ],
            default => [],
        };

        if (!empty($updates)) {
            $message->update($updates);
        }
    }

    /**
     * Persiste el mensaje entrante en la BD.
     */
    private function storeInboundMessage(Conversation $conversation, array $messageData, string $externalId): Message
    {
        $type     = $this->resolveMessageType($messageData);
        $body     = $this->extractBody($messageData, $type);
        $mediaUrl = $this->extractMediaUrl($messageData, $type);

        return Message::create([
            'conversation_id' => $conversation->id,
            'sender_user_id'  => null,
            'direction'       => 'inbound',
            'channel'         => 'whatsapp',
            'external_id'     => $externalId,
            'type'            => $type,
            'body'            => $body,
            'media_url'       => $mediaUrl,
            'meta_payload'    => $messageData,
            'status'          => 'delivered',
        ]);
    }

    /**
     * Envía un mensaje de texto saliente vía WhatsApp Cloud API.
     * Llamado desde SendWhatsAppMessageJob.
     */
    public function sendMessage(string $recipientPhone, string $text): array
    {
        // Revienta con el nombre de la variable que falta en vez de armar la URL
        // con un id vacío y recibir un 404 indescifrable de Meta.
        $phoneNumberId = $this->credentials->obtener('whatsapp_phone_number_id');
        $accessToken   = $this->credentials->obtener('access_token');

        $response = Http::withToken($accessToken)
            ->post($this->credentials->urlGraph("{$phoneNumberId}/messages"), [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $recipientPhone,
                'type'              => 'text',
                'text'              => ['body' => $text],
            ]);

        if ($response->failed()) {
            $error = $response->json('error', []);
            Log::error('[WhatsApp] Error al enviar mensaje', [
                'recipient' => $recipientPhone,
                'error'     => $error,
            ]);

            return ['success' => false, 'error' => $error];
        }

        return [
            'success'    => true,
            'message_id' => $response->json('messages.0.id'),
        ];
    }

    /**
     * Envía el mensaje interactivo que abre un WhatsApp Flow.
     *
     * Solo funciona DENTRO de la ventana de servicio de 24h (es decir, cuando
     * el cliente escribió primero). Fuera de esa ventana hay que usar una
     * plantilla aprobada con componente de Flow, que es otro endpoint.
     *
     * El flow_token identifica esta sesión concreta del Flow: vuelve en cada
     * request al endpoint de datos y en la respuesta final, y es como se
     * correlaciona el Flow con la conversación del CRM.
     *
     * @param  string $recipientPhone Número del destinatario en formato E.164 sin '+'.
     * @param  string $flowId         ID del Flow publicado en WhatsApp Manager.
     * @param  string $flowToken      Identificador de esta sesión, generado por nosotros.
     * @param  string $flowCta        Texto del botón que abre el Flow (máx. 20 caracteres).
     * @param  string $bodyText       Texto del mensaje que acompaña al botón.
     * @param  string $firstScreen    Pantalla inicial del Flow JSON.
     * @return array{success: bool, message_id?: string, error?: mixed}
     */
    public function sendFlowMessage(
        string $recipientPhone,
        string $flowId,
        string $flowToken,
        string $flowCta,
        string $bodyText,
        string $firstScreen,
        ?string $headerText = null,
        ?string $footerText = null,
    ): array {
        $phoneNumberId = $this->credentials->obtener('whatsapp_phone_number_id');
        $accessToken   = $this->credentials->obtener('access_token');

        $interactive = [
            'type' => 'flow',
            'body' => ['text' => $bodyText],
            'action' => [
                'name' => 'flow',
                'parameters' => [
                    // 'published' exige que el Flow esté publicado en Meta.
                    // Para probar un borrador hay que cambiarlo a 'draft'.
                    'flow_message_version' => '3',
                    'flow_token'           => $flowToken,
                    'flow_id'              => $flowId,
                    'flow_cta'             => $flowCta,
                    'flow_action'          => 'navigate',
                    'flow_action_payload'  => ['screen' => $firstScreen],
                ],
            ],
        ];

        if ($headerText !== null) {
            $interactive['header'] = ['type' => 'text', 'text' => $headerText];
        }

        if ($footerText !== null) {
            $interactive['footer'] = ['text' => $footerText];
        }

        $response = Http::withToken($accessToken)
            ->post($this->credentials->urlGraph("{$phoneNumberId}/messages"), [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $recipientPhone,
                'type'              => 'interactive',
                'interactive'       => $interactive,
            ]);

        if ($response->failed()) {
            $error = $response->json('error', []);
            Log::error('[WhatsApp] Error al enviar el mensaje de Flow', [
                'recipient' => $recipientPhone,
                'flow_id'   => $flowId,
                'error'     => $error,
            ]);

            return ['success' => false, 'error' => $error];
        }

        return [
            'success'    => true,
            'message_id' => $response->json('messages.0.id'),
        ];
    }

    /**
     * Verifica la firma HMAC del webhook entrante.
     *
     * Sin app_secret devuelve false y NO calcula nada: un HMAC con clave vacía
     * es predecible, así que cualquiera que supiera que el secreto falta podría
     * firmar un payload falso y hacerse pasar por Meta. Rechazar es lo correcto
     * — mejor un webhook que no entra que uno que entra sin autenticar.
     */
    public function verifySignature(string $rawBody, string $signature): bool
    {
        if (! $this->credentials->tiene('app_secret')) {
            Log::error('[WhatsApp] META_APP_SECRET no está configurado: se rechaza el webhook por no poder verificar su firma.');

            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $this->credentials->obtener('app_secret'));

        return hash_equals($expected, $signature);
    }

    private function resolveDisplayName(string $senderId, array $contacts): ?string
    {
        foreach ($contacts as $contact) {
            if (($contact['wa_id'] ?? '') === $senderId) {
                return $contact['profile']['name'] ?? null;
            }
        }

        return null;
    }

    private function resolveMessageType(array $messageData): string
    {
        return match ($messageData['type'] ?? 'text') {
            'image'    => 'image',
            'audio'    => 'audio',
            'video'    => 'video',
            'document' => 'document',
            default    => 'text',
        };
    }

    private function extractBody(array $messageData, string $type): ?string
    {
        // El historial de coexistencia no reenvía los archivos: manda
        // `media_placeholder` para avisar que ahí hubo uno. Sin este texto el
        // mensaje se vería vacío en la bandeja, como si se hubiera perdido.
        if (($messageData['type'] ?? null) === 'media_placeholder') {
            return '[archivo adjunto no sincronizado]';
        }

        if ($type === 'text') {
            return $messageData['text']['body'] ?? null;
        }

        // Caption en adjuntos (opcional)
        return $messageData[$type]['caption'] ?? null;
    }

    private function extractMediaUrl(array $messageData, string $type): ?string
    {
        if ($type === 'text') {
            return null;
        }

        // WhatsApp Cloud API devuelve un media_id que hay que resolver vía /media/{id}
        // Guardamos el ID como referencia; la resolución de URL se hace on-demand
        $mediaId = $messageData[$type]['id'] ?? null;

        return $mediaId ? "whatsapp_media:{$mediaId}" : null;
    }
}
