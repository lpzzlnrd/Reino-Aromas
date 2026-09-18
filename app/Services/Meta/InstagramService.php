<?php

namespace App\Services\Meta;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\InstagramAutomation;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\ContactService;
use App\Services\ConversationService;
use App\Services\OutboundMessageService;
use App\Services\TicketService;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramService
{
    /**
     * Tope de caracteres que Instagram acepta en un DM.
     *
     * Pasarse devuelve el error 100 con subcódigo 2534038, y Meta lo manda
     * traducido al idioma de la app — en chino, lo que deja al agente sin
     * entender por qué su mensaje no salió. Se valida acá antes de llamar a
     * Meta para fallar con un texto que el agente sí puede leer.
     *
     * OJO: el editor de plantillas permite hasta 4000 caracteres porque ese es
     * el tope cómodo de WhatsApp (4096). Una misma plantilla puede ser válida
     * en WhatsApp e inválida en Instagram, y por eso el límite vive acá, en el
     * canal, y no en la validación de la plantilla.
     */
    public const MAX_CARACTERES_DM = 1000;

    /**
     * Subcódigo de Meta para "el mensaje supera el largo permitido".
     *
     * Público porque SendInstagramMessageJob lo consulta para decidir que el
     * fallo es definitivo y no vale la pena reintentar.
     */
    public const SUBCODIGO_MENSAJE_LARGO = 2534038;

    public function __construct(
        private ContactService $contactService,
        private ConversationService $conversationService,
        private TicketService $ticketService,
        private ActivityLogService $activityLogService,
        private OutboundMessageService $outbound,
        private MetaCredentials $credentials = new MetaCredentials(),
    ) {}

    /**
     * Procesa el payload completo del webhook de Instagram.
     * Llamado desde ProcessInstagramMessageJob.
     */
    public function processWebhookPayload(array $payload): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $messaging) {
                $this->handleMessagingEvent($messaging);
            }

            // Los comentarios NO llegan por `messaging` sino por `changes`, que
            // hasta ahora se descartaba en silencio: aunque el topic estuviera
            // suscrito en Meta, no pasaba nada. Mismo patron que los postbacks
            // antes de handlePostback().
            foreach ($entry['changes'] ?? [] as $change) {
                $this->handleChange($change);
            }
        }
    }

    /**
     * Maneja un cambio de `entry.changes`.
     *
     * Hoy solo interesan los comentarios. Los demas campos se ignoran en
     * silencio y no con un warning: la app esta suscrita a varios topics y
     * loguear cada uno llenaria el log de ruido que parece un fallo.
     *
     * @param  array<string, mixed> $change
     */
    private function handleChange(array $change): void
    {
        $field = $change['field'] ?? null;
        $value = $change['value'] ?? [];

        if (! is_array($value)) {
            return;
        }

        // `comments` es el topic de comentarios en posts. `live_comments` es el
        // de directos y trae la misma forma, pero se deja fuera a proposito: un
        // DM automatico en medio de un live es otra conversacion de producto.
        if ($field === 'comments') {
            $this->comentarios()->handleComment($value);
        }
    }

    /**
     * El servicio de comentarios, resuelto tarde.
     *
     * No va en el constructor porque InstagramCommentService depende de este
     * servicio para enviar el DM: inyectarlo al reves cerraria un ciclo que el
     * contenedor no puede construir.
     */
    private function comentarios(): InstagramCommentService
    {
        return app(InstagramCommentService::class);
    }

    /**
     * Maneja un evento de mensajería individual del webhook.
     */
    private function handleMessagingEvent(array $messaging): void
    {
        $senderId = $messaging['sender']['id'] ?? null;
        $messageData = $messaging['message'] ?? null;

        // Instagram manda por el mismo campo 'messaging' los acuses de lectura
        // y de entrega, que traen 'read' o 'delivery' en vez de 'message'. No
        // son errores: se ignoran en silencio. Antes caian en el warning de
        // abajo y llenaban el log de ruido que parecia un fallo.
        if (isset($messaging['read']) || isset($messaging['delivery'])) {
            return;
        }

        // Los botones automaticos (Ice Breakers y Persistent Menu) no llegan
        // como 'message' sino como 'postback'. Se atienden ANTES de exigir
        // messageData, o caerian en el warning de abajo y no harian nada.
        if ($senderId && isset($messaging['postback'])) {
            $this->handlePostback($senderId, $messaging['postback']);

            return;
        }

        if (!$senderId || !$messageData) {
            Log::warning('[Instagram] Evento de mensajería sin sender o message', $messaging);
            return;
        }

        // Ignorar mensajes de eco (mensajes que nosotros enviamos)
        if ($messageData['is_echo'] ?? false) {
            return;
        }

        $externalId = $messageData['mid'] ?? null;

        // Idempotencia: ignorar si ya procesamos este mensaje
        if ($externalId && Message::where('external_id', $externalId)->exists()) {
            Log::info("[Instagram] Mensaje duplicado ignorado: {$externalId}");
            return;
        }

        // El webhook de Instagram NO trae el nombre: 'sender' solo lleva el id
        // (a diferencia de Messenger, donde sender.name viene en el payload).
        // El username se pide aparte a la User Profile API.
        $perfil = $this->fetchUserProfile($senderId);

        $contact = $this->contactService->findOrCreate('instagram', $senderId, [
            'display_name'        => $perfil['username'] ?? $perfil['name'] ?? null,
            'instagram_handle'    => $perfil['username'] ?? null,
            'profile_picture_url' => $perfil['profile_pic'] ?? null,
        ]);

        $conversation = $this->conversationService->getOrOpenActive($contact);

        $this->storeInboundMessage($conversation, $messageData, $externalId);

        $this->ticketService->ensureTicketExists($conversation);

        $this->conversationService->updateLastMessageAt($conversation);
        $this->conversationService->refreshWindowStatus($conversation);
    }

    /**
     * Persiste el mensaje entrante en la BD.
     */
    private function storeInboundMessage(Conversation $conversation, array $messageData, ?string $externalId): Message
    {
        $type = $this->resolveMessageType($messageData);
        $body = $messageData['text'] ?? null;
        $mediaUrl = $this->extractMediaUrl($messageData);

        return Message::create([
            'conversation_id' => $conversation->id,
            'sender_user_id'  => null,
            'direction'       => 'inbound',
            'channel'         => 'instagram',
            'external_id'     => $externalId,
            'type'            => $type,
            'body'            => $body,
            'media_url'       => $mediaUrl,
            'meta_payload'    => $messageData,
            'status'          => 'delivered',
        ]);
    }

    /**
     * Alguien toco un Ice Breaker o una entrada del Persistent Menu.
     *
     * El `payload` es el string que se configuro en el CRM y que Meta devuelve
     * tal cual: es lo unico que permite saber QUE boton se toco.
     *
     * Se crea contacto, conversacion y ticket igual que con un mensaje normal,
     * porque para el negocio esto ES un lead entrante: la diferencia es que ya
     * viene con una intencion declarada.
     *
     * El titulo del boton se guarda como mensaje entrante para que el agente
     * vea en el chat que pregunto la persona; sin eso, la conversacion
     * empezaria con la respuesta automatica y nadie sabria a que responde.
     *
     * @param array<string, mixed> $postback
     */
    private function handlePostback(string $senderId, array $postback): void
    {
        $payload = (string) ($postback['payload'] ?? '');
        $titulo  = (string) ($postback['title'] ?? '');

        if ($payload === '') {
            Log::warning('[Instagram] Postback sin payload', $postback);

            return;
        }

        $automatizacion = InstagramAutomation::query()
            ->where('payload', $payload)
            ->first();

        // Un payload que el CRM no conoce: quedo configurado en Meta por fuera,
        // o se borro del CRM sin resincronizar. No se descarta el evento --
        // sigue siendo un lead -- pero se avisa para poder limpiarlo.
        if ($automatizacion === null) {
            Log::warning('[Instagram] Postback de un boton que el CRM no conoce', [
                'payload' => $payload,
                'titulo'  => $titulo,
            ]);
        }

        $perfil = $this->fetchUserProfile($senderId);

        $contact = $this->contactService->findOrCreate('instagram', $senderId, [
            'display_name'        => $perfil['username'] ?? $perfil['name'] ?? null,
            'instagram_handle'    => $perfil['username'] ?? null,
            'profile_picture_url' => $perfil['profile_pic'] ?? null,
        ]);

        $conversation = $this->conversationService->getOrOpenActive($contact);

        // El boton pulsado, como mensaje entrante. external_id null: Meta no
        // manda un mid para los postbacks, y la columna es unique -- dos
        // postbacks con external_id vacio chocarian.
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_user_id'  => null,
            'direction'       => 'inbound',
            'channel'         => 'instagram',
            'external_id'     => null,
            'type'            => 'text',
            'body'            => $titulo !== '' ? $titulo : $payload,
            'meta_payload'    => $postback,
            'status'          => 'delivered',
        ]);

        $this->ticketService->ensureTicketExists($conversation);
        $this->conversationService->updateLastMessageAt($conversation);
        $this->conversationService->refreshWindowStatus($conversation);

        if ($automatizacion === null) {
            return;
        }

        $automatizacion->increment('hits');

        $respuesta = $automatizacion->respuesta();

        // handoff, o una plantilla borrada/desactivada: no se responde nada y
        // el ticket queda en la bandeja para que lo tome un agente. Es
        // deliberado -- mejor silencio que un mensaje vacio.
        if ($respuesta === null || trim($respuesta) === '') {
            Log::info('[Instagram] Boton sin respuesta automatica; queda para un agente', [
                'payload'       => $payload,
                'response_type' => $automatizacion->response_type,
            ]);

            return;
        }

        // Se encola por el servicio comun para que el mensaje quede persistido
        // como 'pending' y visible en el chat aunque la cola este caida.
        $this->outbound->queueTextMessage($conversation, $respuesta);
    }

    /**
     * Pide el perfil del usuario a la User Profile API.
     *
     * Hace falta porque el webhook de Instagram solo manda `sender.id`; el
     * nombre y el username hay que pedirlos con una llamada aparte al nodo del
     * IGSID.
     *
     * Se prefiere `username` sobre `name` para el nombre visible: en Instagram
     * la gente se reconoce por el @, y `name` puede venir null si el usuario no
     * lo configuro.
     *
     * Nunca lanza: un contacto sin nombre es peor que uno llamado "Instagram
     * User", pero perder el MENSAJE por no poder resolver el perfil es mucho
     * peor. Si falla, se registra y el mensaje se guarda igual.
     *
     * @return array{name?: string, username?: string, profile_pic?: string}
     */
    private function fetchUserProfile(string $igsid): array
    {
        $accessToken = $this->credentials->obtener('instagram_access_token')
            ?: $this->credentials->obtener('access_token');

        if (! $accessToken) {
            return [];
        }

        try {
            $response = Http::timeout(10)
                ->withToken($accessToken)
                ->get($this->credentials->urlGraphInstagram($igsid), [
                    'fields' => 'name,username,profile_pic',
                ]);
        } catch (\Throwable $e) {
            Log::warning('[Instagram] No se pudo pedir el perfil del usuario', [
                'igsid' => $igsid,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if ($response->failed()) {
            // El caso normal: "User consent is required to access user profile"
            // cuando el usuario comento pero nunca escribio. No es un fallo
            // nuestro, asi que se registra como info y no como error.
            Log::info('[Instagram] Perfil no disponible', [
                'igsid' => $igsid,
                'error' => $response->json('error.message'),
            ]);

            return [];
        }

        return array_filter([
            'name'        => $response->json('name'),
            'username'    => $response->json('username'),
            'profile_pic' => $response->json('profile_pic'),
        ]);
    }

    /**
     * Envía un mensaje de texto saliente vía Instagram Messaging API.
     * Llamado desde SendInstagramMessageJob.
     */
    public function sendMessage(string $recipientIgsid, string $text): array
    {
        // Antes de gastar la llamada: Meta rechaza los mensajes largos con un
        // error traducido al idioma de la app, que el agente no puede leer.
        // mb_strlen y no strlen: los emojis y las tildes cuentan como un
        // carácter para Meta, pero strlen los contaría como varios bytes y
        // rechazaría mensajes que en realidad caben.
        if (($largo = mb_strlen($text)) > self::MAX_CARACTERES_DM) {
            Log::warning('[Instagram] Mensaje demasiado largo, no se envió', [
                'recipient' => $recipientIgsid,
                'largo'     => $largo,
                'maximo'    => self::MAX_CARACTERES_DM,
            ]);

            return [
                'success' => false,
                'error'   => $this->errorMensajeLargo($largo),
            ];
        }

        // Falla con el nombre de la variable ausente en vez de pedirle a Meta
        // una URL con el id vacío.
        $igAccountId = $this->credentials->obtener('instagram_account_id');

        // El producto "Instagram API con login de Instagram" tiene su propio
        // token; si no está configurado se cae al de la app principal, que es
        // el correcto cuando Instagram entra por el topic de esa app.
        $accessToken = $this->credentials->obtener('instagram_access_token')
            ?: $this->credentials->obtener('access_token');

        // graph.instagram.com, NO graph.facebook.com: el host de Facebook
        // responde "(#3) Application does not have the capability to make this
        // API call" para estos endpoints. Ver urlGraphInstagram().
        $response = Http::withToken($accessToken)
            ->post($this->credentials->urlGraphInstagram("{$igAccountId}/messages"), [
                'recipient' => ['id' => $recipientIgsid],
                'message'   => ['text' => $text],
            ]);

        if ($response->failed()) {
            $error = $response->json('error', []);
            Log::error('[Instagram] Error al enviar mensaje', [
                'recipient' => $recipientIgsid,
                'error'     => $error,
            ]);

            return ['success' => false, 'error' => $this->errorLegible($error, $text)];
        }

        return [
            'success'    => true,
            'message_id' => $response->json('message_id'),
        ];
    }

    /**
     * Convierte un error de Meta en algo que el agente pueda leer.
     *
     * Meta traduce sus mensajes al idioma configurado en la app, y para esta
     * cuenta llegan en chino: el agente ve un muro de caracteres que no le
     * dice qué hacer. Los subcódigos, en cambio, son estables y numéricos.
     *
     * Solo se reescriben los errores accionables por el agente. El resto se
     * devuelve tal cual: inventar un texto amable para un fallo que no
     * entendemos escondería la causa real justo cuando hace falta.
     *
     * @param  array<string, mixed>|mixed $error
     * @return array<string, mixed>|mixed
     */
    private function errorLegible(mixed $error, string $text): mixed
    {
        if (! is_array($error)) {
            return $error;
        }

        if (($error['error_subcode'] ?? null) === self::SUBCODIGO_MENSAJE_LARGO) {
            return $this->errorMensajeLargo(mb_strlen($text)) + ['meta_error' => $error];
        }

        return $error;
    }

    /**
     * Forma del error de mensaje largo, con el dato que el agente necesita:
     * cuánto se pasó y de cuánto es el tope.
     *
     * @return array<string, mixed>
     */
    private function errorMensajeLargo(int $largo): array
    {
        return [
            'message'       => "El mensaje tiene {$largo} caracteres y Instagram solo permite "
                . self::MAX_CARACTERES_DM . '. Acorta el texto de la plantilla e inténtalo de nuevo.',
            'type'          => 'MensajeDemasiadoLargo',
            'code'          => 100,
            'error_subcode' => self::SUBCODIGO_MENSAJE_LARGO,
        ];
    }

    /**
     * Envia un DM a quien comento un post, usando el id del comentario.
     *
     * Es un endpoint distinto de sendMessage() solo en el destinatario:
     * `recipient.comment_id` en vez de `recipient.id`. Meta lo permite porque
     * el comentario publico cuenta como la interaccion que abre la ventana.
     *
     * LIMITES DE META, que no son negociables y explican el resto del disenio:
     *
     *  - UN solo mensaje por comentario. Un segundo intento se rechaza.
     *  - Ventana de 7 dias desde el comentario.
     *  - No se puede retomar la conversacion despues por iniciativa propia; si
     *    la persona responde, ahi si se abre la ventana normal de 24h.
     *
     * Por eso la idempotencia se guarda en `instagram_comment_replies` ANTES de
     * enviar y no despues: si el proceso muere entre la llamada y el registro,
     * es preferible no haber mandado el DM a mandarlo dos veces.
     *
     * @return array{success: bool, message_id?: string|null, error?: mixed}
     */
    public function sendCommentReply(string $commentId, string $text): array
    {
        // Validar antes pesa más acá que en sendMessage(): Meta permite UN solo
        // DM por comentario, así que un rechazo por largo quema el único
        // intento y esa persona ya no recibe nada nunca.
        if (($largo = mb_strlen($text)) > self::MAX_CARACTERES_DM) {
            Log::warning('[Instagram] DM por comentario demasiado largo, no se envió', [
                'comment_id' => $commentId,
                'largo'      => $largo,
                'maximo'     => self::MAX_CARACTERES_DM,
            ]);

            return [
                'success' => false,
                'error'   => $this->errorMensajeLargo($largo),
            ];
        }

        $igAccountId = $this->credentials->obtener('instagram_account_id');

        $accessToken = $this->credentials->obtener('instagram_access_token')
            ?: $this->credentials->obtener('access_token');

        // graph.instagram.com, igual que el resto del producto de IG. Ver la
        // nota en sendMessage().
        $response = Http::withToken($accessToken)
            ->post($this->credentials->urlGraphInstagram("{$igAccountId}/messages"), [
                'recipient' => ['comment_id' => $commentId],
                'message'   => ['text' => $text],
            ]);

        if ($response->failed()) {
            $error = $response->json('error', []);
            Log::error('[Instagram] Error al enviar el DM de bienvenida por comentario', [
                'comment_id' => $commentId,
                'error'      => $error,
            ]);

            return ['success' => false, 'error' => $this->errorLegible($error, $text)];
        }

        return [
            'success'    => true,
            'message_id' => $response->json('message_id'),
        ];
    }

    /**
     * Publica una respuesta PUBLICA debajo del comentario.
     *
     * Es el aviso "te escribimos al privado": sin el, la persona no sabe que
     * tiene un DM esperando (le llega a Solicitudes si no sigue la cuenta, que
     * es una carpeta que nadie mira), y el resto de la gente no ve que la cuenta
     * responde.
     *
     * Endpoint distinto del DM: aqui el comentario es el NODO, no el
     * destinatario -- POST /{comment-id}/replies con `message` en la query.
     *
     * LIMITES DE LA DOC, que explican los cortes de arriba:
     *
     *  - Solo comentarios de primer nivel. Una respuesta a una respuesta se
     *    cuelga del comentario padre, asi que responder un hilo duplicaria el
     *    aviso bajo el comentario original.
     *  - No se puede responder a comentarios ocultos.
     *  - Nada de esto aplica a Instagram Live.
     *
     * @return array{success: bool, reply_id?: string|null, error?: mixed}
     */
    public function replyToComment(string $commentId, string $text): array
    {
        $accessToken = $this->credentials->obtener('instagram_access_token')
            ?: $this->credentials->obtener('access_token');

        // El mensaje va como query string y no en el cuerpo: asi lo define la
        // referencia de IG Comment Replies.
        $response = Http::withToken($accessToken)
            ->post($this->credentials->urlGraphInstagram("{$commentId}/replies"), [
                'message' => $text,
            ]);

        if ($response->failed()) {
            $error = $response->json('error', []);
            Log::error('[Instagram] Error al responder el comentario en publico', [
                'comment_id' => $commentId,
                'error'      => $error,
            ]);

            return ['success' => false, 'error' => $error];
        }

        return [
            'success'  => true,
            'reply_id' => $response->json('id'),
        ];
    }

    /**
     * Verifica la firma HMAC del webhook entrante.
     *
     * Sin app_secret devuelve false sin calcular nada: un HMAC con clave vacía
     * lo puede reproducir cualquiera. Ver la nota en WhatsAppService.
     */
    public function verifySignature(string $rawBody, string $signature): bool
    {
        // Instagram puede entrar por dos caminos, y CADA UNO firma con un
        // secret distinto:
        //
        //   a) el producto "Instagram API con login de Instagram", que es una
        //      app aparte con su propio app secret -> META_INSTAGRAM_APP_SECRET
        //   b) el topic 'instagram' de la app principal -> META_APP_SECRET
        //
        // Usar el secret equivocado devuelve 403 a Meta y el mensaje se pierde
        // sin dejar rastro en el log de Laravel: el rechazo ocurre antes.
        // Se prueban los dos en vez de elegir por configuracion, porque asi la
        // integracion sigue funcionando si el negocio cambia de camino sin que
        // nadie se acuerde de tocar el .env.
        $secretos = array_values(array_filter([
            $this->credentials->obtener('instagram_app_secret'),
            $this->credentials->obtener('app_secret'),
        ]));

        if ($secretos === []) {
            Log::error('[Instagram] Sin META_INSTAGRAM_APP_SECRET ni META_APP_SECRET: se rechaza el webhook por no poder verificar su firma.');

            return false;
        }

        foreach ($secretos as $secreto) {
            $expected = 'sha256=' . hash_hmac('sha256', $rawBody, (string) $secreto);

            // hash_equals y no ===: comparar firmas con == filtra informacion
            // por el tiempo que tarda en fallar.
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        Log::warning('[Instagram] Firma del webhook invalida con todos los secrets configurados.', [
            'secrets_probados' => count($secretos),
        ]);

        return false;
    }

    private function resolveMessageType(array $messageData): string
    {
        if (isset($messageData['attachments'])) {
            $attachmentType = $messageData['attachments'][0]['type'] ?? 'document';
            return match ($attachmentType) {
                'image'  => 'image',
                'audio'  => 'audio',
                'video'  => 'video',
                default  => 'document',
            };
        }

        return 'text';
    }

    private function extractMediaUrl(array $messageData): ?string
    {
        return $messageData['attachments'][0]['payload']['url'] ?? null;
    }
}
