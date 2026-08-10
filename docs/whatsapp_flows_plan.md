---
name: reino-aromas-whatsapp-flows
description: Plan de implementación de WhatsApp Flows para el CRM de Reino Aromas — automatiza el flujo de calificación de leads (saludo → selección de ciudad → información de curso → confirmación de interés → creación de ticket) dentro de WhatsApp, integrado con el backend Laravel existente.
---

# Plan de Implementación — WhatsApp Flows para Reino Aromas CRM

> Generado a partir del contexto de negocio real del cliente y la documentación oficial de Meta (accedida agosto 2026). Este documento es la guía operativa para implementar el Flow con Claude Code.

---

## 1. Resumen ejecutivo

Reino Aromas recibe leads por WhatsApp e Instagram que hoy se atienden **100% manual**: un agente saluda, pregunta la ciudad, busca y copia el texto de precios correspondiente, y espera respuesta. Esto es lento, inconsistente entre agentes, y no prioriza tickets hasta que un humano lo lee.

**WhatsApp Flows** puede automatizar la primera parte de esa conversación —el filtro— dentro del propio WhatsApp, sin que el lead sienta que habla con un bot externo ni sea redirigido a un link. El Flow reemplaza los primeros mensajes manuales (saludo, selección de ciudad, entrega de información) y solo cuando el lead confirma interés real, se crea el ticket en el CRM con **prioridad `muy_alta`**, listo para que un agente humano tome el pago y cierre la venta.

**Esto NO reemplaza al CRM ni a los agentes.** Es la capa de calificación automática que hoy hacen a mano, descrita explícitamente en `REINO AROMAS INICIO..md` y confirmada como comportamiento deseado.

### Decisión de arquitectura: Flow con endpoint (dinámico)

Aunque el catálogo de ciudades/precios es estático hoy, se recomienda un **Flow con endpoint conectado al backend Laravel**, no un Flow estático, por estas razones:

1. El backend ya tiene el modelo `Template` con precios por ciudad (`forCity()` scope) — el Flow debe leer de ahí, no duplicar precios hardcodeados en el JSON del Flow. Si Reino Aromas sube el precio de Caracas, se actualiza en un solo lugar.
2. Cuando el lead confirma "estoy interesado", ese evento **debe crear un `Contact`, `Conversation` y `Ticket` con prioridad `muy_alta`** en la base de datos real — esto solo es posible con un endpoint, no con un Flow estático.
3. Margarita está "en desarrollo" según el cliente — un endpoint permite ocultar/mostrar ciudades dinámicamente sin recompilar el Flow.

---

## 2. Cómo se traduce el flujo real del cliente a pantallas del Flow

Basado en el flujo descrito en `REINO AROMAS INICIO..md`:

| Paso actual (manual, hecho por agente) | Pantalla del Flow |
|---|---|
| Agente saluda | **Pantalla 1 — Bienvenida** |
| Agente pregunta "¿en qué ciudad vives?" con las 5 opciones | **Pantalla 2 — Selección de ciudad** (RadioButtonsGroup) |
| Agente busca y envía el texto configurado de esa ciudad (precio, qué incluye) | **Pantalla 3 — Información del curso** (dinámica, vía endpoint según ciudad elegida) |
| Agente pregunta si quiere reservar / está interesado | **Pantalla 4 — Confirmación de interés** |
| Si confirma: agente envía métodos de pago y monto de reserva ($20) | **Pantalla 5 — Métodos de pago y cierre** |
| Si paga y manda captura: agente lo redirige a chat personal fuera del CRM | *(Esto queda FUERA del Flow — ver sección 6, es intencional)* |

### Lo que el Flow NO debe hacer

- **No debe procesar pagos ni fotos de comprobante.** El cliente especificó que eso se maneja en un chat personal fuera del CRM. Meta Flows no está diseñado para recibir imágenes de todas formas.
- **No debe reemplazar la conversación humana post-interés.** El Flow termina en "ticket creado, prioridad muy alta" y ahí un agente humano retoma.
- **No debe intentar responder comentarios de Instagram** — eso está fuera de alcance según `SKILL.md` ("Auto-respuesta basada en comentarios... fuera de alcance").

---

## 3. Diseño de pantallas (Flow JSON)

### Pantalla 1 — Bienvenida
- Texto de bienvenida con el nombre de Reino Aromas
- Botón único: "Continuar"
- Sin inputs, solo footer button → navega a Pantalla 2

### Pantalla 2 — Selección de ciudad
- Componente `RadioButtonsGroup`, campo `ciudad`
- Opciones: Caracas, Valencia, Barquisimeto, Maracay, Margarita
- **Nota de negocio:** si Margarita sigue "en desarrollo" al momento de implementar, el endpoint debe excluirla de las opciones dinámicamente (ver sección 4, `data_channel`) en lugar de quitarla a mano del JSON cada vez que cambie el estado.
- Footer button "Ver información" → dispara request al endpoint con la ciudad seleccionada

### Pantalla 3 — Información del curso (dinámica)
Poblada por la respuesta del endpoint según la ciudad elegida en Pantalla 2. Debe mostrar:
- Nombre de la ciudad
- Precio (ej. "$110 — incluye reserva de $20")
- Qué incluye: desayuno, refrigerio, café (según `REINO AROMAS INICIO..md`)
- Horario: 10am–6pm
- Frecuencia de visitas a esa ciudad (ej. "Cada mes" para Valencia, "Cada 2 meses" para Barquisimeto) — dato ya documentado por el cliente, refuerza urgencia si la próxima fecha está lejos
- Botones: "Estoy interesado" / "Solo quería info"

### Pantalla 4 — Confirmación de interés
Dos ramas según el botón de la Pantalla 3:

**Rama A — "Estoy interesado":**
- Mensaje confirmando que un agente le va a escribir para coordinar
- Campo opcional: nombre completo (si no se puede obtener de WhatsApp Business Profile)
- Footer button "Confirmar" → dispara el endpoint que crea el `Ticket` con prioridad `muy_alta`

**Rama B — "Solo quería info":**
- Mensaje de cierre cordial, ticket se crea igual pero con prioridad `media` (el cliente mencionó que la interacción alta o "estoy interesado" define la prioridad — la rama B no cumple ese criterio)

### Pantalla 5 — Cierre y métodos de pago (solo Rama A)
- Muestra el monto de reserva ($20, según `REINO AROMAS INICIO..md`)
- Mensaje: "Un agente te contactará en breve con los métodos de pago para confirmar tu reserva"
- **Importante:** NO se listan métodos de pago específicos dentro del Flow. El cliente indicó que esa parte se maneja personalmente fuera del CRM — el Flow solo calienta al lead y crea el ticket; el agente humano cierra el resto por el chat normal.
- Pantalla terminal, cierra el Flow

---

## 4. Implementación técnica del endpoint (Laravel)

### 4.1 Prerrequisitos (según docs de Meta, agosto 2026)

Antes de tocar código, confirmar en Meta Business Manager:

1. WhatsApp Business Platform activo con Cloud API (ya lo tienen, según `SKILL.md`)
2. Negocio verificado (necesario para volumen de mensajes en producción)
3. Un par de llaves RSA (pública/privada) generado y la pública subida a Meta, firmada por número de teléfono
4. Meta Flows requiere plan de mensajería de Cloud API — no funciona con integraciones on-premise viejas

### 4.2 Generar el par de llaves RSA

```bash
openssl genrsa -out private.pem -aes256 2048
openssl rsa -in private.pem -pubout -out public.pem
```

La llave privada (`private.pem`) se guarda en el servidor, **nunca en el repositorio** — va en `.env` como variable o en un path fuera del control de versiones. La pública se sube a Meta vía la API de negocio, firmada para el número de teléfono conectado.

```env
# .env
WHATSAPP_FLOWS_PRIVATE_KEY_PATH=/var/www/reinoaromas/storage/keys/private.pem
WHATSAPP_FLOWS_PRIVATE_KEY_PASSPHRASE=xxxxx
```

### 4.3 Nuevo endpoint — Contrato de datos

Ruta nueva en `routes/api.php`, agrupada con los webhooks existentes de Meta:

```php
Route::post('webhooks/flows/reino-aromas', [
    \App\Http\Controllers\Api\Webhooks\WhatsAppFlowController::class, 'handle'
])->name('webhooks.flows.reino-aromas');
```

**Este endpoint es distinto al webhook normal de mensajes** (`WhatsAppWebhookController` ya existente) — Flows usa un canal de datos cifrado separado, con su propio contrato request/response.

### 4.4 Descifrado del request (AES-GCM + RSA)

Cada request de Meta llega con:
- `encrypted_flow_data` (base64) — el payload real
- `encrypted_aes_key` (base64) — la clave AES cifrada con tu llave pública RSA
- `initial_vector` (base64) — IV para AES-GCM

Pasos (documentados en `developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/implementingyourflowendpoint`):

1. Descifrar `encrypted_aes_key` con la llave privada RSA → obtener la clave AES simétrica
2. Decodificar `encrypted_flow_data` y `initial_vector` de base64
3. Descifrar el payload con AES-GCM usando la clave AES + IV (el tag de autenticación de 128 bits viene al final del array cifrado)
4. El resultado es el JSON plano con los datos que el usuario envió en esa pantalla

```php
// app/Services/WhatsApp/FlowEncryptionService.php
class FlowEncryptionService
{
    public function decrypt(string $encryptedFlowData, string $encryptedAesKey, string $initialVector): array
    {
        $privateKey = openssl_pkey_get_private(
            file_get_contents(config('services.whatsapp.flows_private_key_path')),
            config('services.whatsapp.flows_private_key_passphrase')
        );

        openssl_private_decrypt(
            base64_decode($encryptedAesKey),
            $aesKey,
            $privateKey,
            OPENSSL_PKCS1_OAEP_PADDING
        );

        $flowDataRaw = base64_decode($encryptedFlowData);
        $iv = base64_decode($initialVector);
        $tagLength = 16; // 128 bits
        $tag = substr($flowDataRaw, -$tagLength);
        $cipherText = substr($flowDataRaw, 0, -$tagLength);

        $decrypted = openssl_decrypt(
            $cipherText, 'aes-128-gcm', $aesKey,
            OPENSSL_RAW_DATA, $iv, $tag
        );

        return json_decode($decrypted, true);
    }

    public function encrypt(array $responseData, string $aesKeyRaw, string $initialVector): string
    {
        // IV de respuesta = IV de request con todos los bits invertidos (XOR 0xFF)
        $flippedIv = '';
        foreach (str_split($initialVector) as $byte) {
            $flippedIv .= chr(~ord($byte) & 0xFF);
        }

        $plainText = json_encode($responseData);
        $encrypted = openssl_encrypt(
            $plainText, 'aes-128-gcm', $aesKeyRaw,
            OPENSSL_RAW_DATA, $flippedIv, $tag, '', 16
        );

        return base64_encode($encrypted . $tag);
    }
}
```

**Nota importante de la doc de Meta:** el IV de la respuesta se genera invirtiendo (XOR con `0xFF`) cada byte del IV usado en el request. Confirmar esto contra la versión de `data_api_version` activa (`"3.0"` al momento de esta investigación) porque Meta ha cambiado este detalle entre versiones.

### 4.5 Controlador — enrutando por pantalla

```php
// app/Http/Controllers/Api/Webhooks/WhatsAppFlowController.php
class WhatsAppFlowController extends MetaBaseController
{
    public function __construct(
        private FlowEncryptionService $encryption,
        private ContactService $contacts,
        private ConversationService $conversations,
        private TicketService $tickets,
        private ActivityLogService $activityLog,
    ) {}

    public function handle(Request $request)
    {
        $decrypted = $this->encryption->decrypt(
            $request->input('encrypted_flow_data'),
            $request->input('encrypted_aes_key'),
            $request->input('initial_vector'),
        );

        $response = match ($decrypted['screen'] ?? null) {
            'CITY_SELECTION' => $this->handleCitySelection($decrypted),
            'INTEREST_CONFIRMATION' => $this->handleInterestConfirmed($decrypted),
            'INFO_ONLY' => $this->handleInfoOnly($decrypted),
            default => $this->healthCheckResponse(),
        };

        return response($this->encryption->encrypt(
            $response, $decrypted['_aes_key_raw'], $decrypted['_iv_raw']
        ));
    }

    private function handleCitySelection(array $data): array
    {
        $ciudad = $data['data']['ciudad'];

        // Lee de Template, NO hardcodeado — un solo lugar de verdad para precios
        $template = Template::active()->forCity($ciudad)->first();

        if (!$template) {
            // Margarita "en desarrollo" u otra ciudad sin template activo
            return [
                'screen' => 'CITY_SELECTION',
                'data' => ['error_message' => 'Esa ciudad no tiene fechas disponibles por ahora.'],
            ];
        }

        return [
            'screen' => 'COURSE_INFO',
            'data' => [
                'ciudad' => $ciudad,
                'precio' => $template->precio,
                'incluye' => $template->descripcion_incluye,
                'frecuencia' => $template->frecuencia_visitas,
            ],
        ];
    }

    private function handleInterestConfirmed(array $data): array
    {
        // Aquí se replica EXACTAMENTE lo que hoy hace un agente a mano:
        // crear contact + conversation + ticket con prioridad muy_alta
        $contact = $this->contacts->findOrCreate(
            channel: 'whatsapp',
            channelId: $data['data']['wa_id'],
            profileData: ['display_name' => $data['data']['nombre'] ?? null, 'city' => $data['data']['ciudad']],
        );

        $conversation = $this->conversations->getOrOpenActive($contact);

        $ticket = $this->tickets->create($conversation, [
            'priority' => 'muy_alta', // el cliente definió esto explícitamente: interacción alta = muy alta prioridad
            'status' => 'interesado',
            'course_interest' => $data['data']['ciudad'],
        ]);

        $this->activityLog->log(
            causer: null, // originado por el Flow, no por un usuario humano
            target: $ticket,
            action: 'ticket_creado_por_flow',
            metadata: ['origen' => 'whatsapp_flow', 'ciudad' => $data['data']['ciudad']],
        );

        return [
            'screen' => 'PAYMENT_INFO',
            'data' => ['monto_reserva' => 20],
        ];
    }
}
```

**Nota de diseño:** `ActivityLogService::log()` con `causer: null` es una decisión deliberada — el ticket no lo creó un agente humano, lo creó el Flow automáticamente. Si `ActivityLog` usa `morphTo` estricto y no acepta `null`, considerar un "usuario sistema" dedicado (`role = 'sistema'`) en lugar de forzar `causer_id` a un agente real, para no ensuciar la auditoría atribuyéndole a un humano algo que no hizo.

### 4.6 El endpoint de health check

Meta hace pings periódicos sin campo `screen` definido para verificar que el endpoint responde. El controlador debe responder algo neutro (`healthCheckResponse()`) sin intentar procesar lógica de negocio en ese caso — si no responde correctamente, Meta puede desactivar el Flow por "endpoint no saludable".

---

## 5. El mensaje que dispara el Flow

Según la documentación de Meta, un Flow se abre desde:
- Un **template aprobado** con componente de Flow (para iniciar conversación fuera de la ventana de 24h)
- Un **mensaje interactivo de Flow** dentro de una ventana de servicio activa (dentro de las 24h desde el último mensaje del cliente)

Dado el flujo real de Reino Aromas: el lead ya escribió primero (por publicidad, historia de IG, o el link de WhatsApp en la bio). Eso abre la ventana de 24h, así que el Flow puede dispararse como **mensaje interactivo dentro de esa ventana**, sin necesidad de aprobar un template nuevo para el primer contacto.

Esto se integra en el `WhatsAppWebhookController` existente: cuando llega el **primer mensaje de un contacto nuevo** (sin `Contact` previo en la base de datos), en lugar de solo crear el ticket en estado `nuevo` como hace hoy, el sistema responde automáticamente con el mensaje interactivo que abre el Flow de bienvenida.

```php
// dentro de ProcessWhatsAppMessageJob o similar, cuando el contacto es nuevo
if ($contact->wasRecentlyCreated) {
    dispatch(new SendWhatsAppFlowJob($contact, flowId: config('services.whatsapp.flows.bienvenida_id')));
}
```

**Importante:** esto reemplaza el comportamiento actual de "se crea el ticket en estado nuevo y un agente lo atiende manualmente" SOLO para el primer contacto. Si el Flow falla o el usuario lo cierra sin completar, el ticket ya existe en estado `nuevo` como red de seguridad — un agente humano lo ve igual en el Kanban.

---

## 6. Lo que queda fuera del Flow, a propósito

Confirmado explícitamente por el negocio en `REINO AROMAS INICIO..md` y `SKILL.md`:

- **Pagos:** el Flow nunca pide número de tarjeta, referencia de pago móvil, ni nada financiero. Solo informa el monto de reserva.
- **Fotos de comprobante:** Meta Flows no maneja adjuntos de imagen de esta forma; el cliente ya definió que esto se hace en un chat personal fuera del CRM.
- **Comentarios de Instagram:** fuera de alcance según el documento de scope del cliente.
- **Clasificación automática por IA/palabras clave:** el cliente fue explícito en que la asignación y prioridad avanzada es manual. El Flow solo aplica la regla de prioridad ya definida por el cliente ("interacción alta o botón 'interesado' → muy alta"), no inventa lógica nueva de scoring.

---

## 7. Dónde encaja esto en el cronograma de 7 semanas

Según `DESARROLLO..md`, la integración base de WhatsApp (webhooks, contacto/conversación/ticket automático) es **Semana 2**. WhatsApp Flows es una extensión de esa integración, no un cronograma paralelo.

**Recomendación de ubicación:** tratar Flows como un entregable adicional dentro de la Semana 2 o como la primera tarea de la Semana 3 (Gestión de Tickets), ya que depende de que el modelo de `Ticket` y `TicketService` ya existan. **No intentar antes de que la Semana 2 esté cerrada** — el Flow depende de webhooks funcionando y de poder crear contactos/conversaciones/tickets, que es justamente lo que esa semana entrega.

**Riesgo de cronograma a comunicar al cliente (igual que el riesgo ya documentado de aprobación de número):** la aprobación de un Flow publicado por Meta, y de cualquier template que lo dispare fuera de la ventana de 24h, puede tardar días. Si el cronograma ya tiene el riesgo de aprobación de WhatsApp Business Number anotado, agregar este como riesgo hermano.

---

## 8. Checklist de implementación para Claude Code

- [ ] Generar par de llaves RSA y subir la pública a Meta (firmada al número de teléfono)
- [ ] Crear `FlowEncryptionService` con métodos `decrypt()` / `encrypt()`
- [ ] Crear `WhatsAppFlowController` con el enrutamiento por `screen`
- [ ] Agregar campos necesarios a `Template` si faltan (`descripcion_incluye`, `frecuencia_visitas`) — revisar migración existente antes de asumir que no están
- [ ] Diseñar y publicar el Flow JSON en WhatsApp Manager (5 pantallas descritas en sección 3)
- [ ] Conectar el Flow al endpoint nuevo desde "Edit Flow" en WhatsApp Manager
- [ ] Probar el endpoint con el health check de Meta antes de asignarle tráfico real
- [ ] Integrar el disparo automático del Flow para contactos nuevos en `ProcessWhatsAppMessageJob`
- [ ] Confirmar que `ActivityLogService` soporta `causer` nulo/sistema para tickets creados por el Flow
- [ ] Probar el journey completo end-to-end con un número de prueba antes de la demo de la Semana 2/3
- [ ] Documentar en el manual de agentes (Semana 7) que los tickets con metadata `origen: whatsapp_flow` fueron precalificados automáticamente

---

## 9. Referencias

- Meta — Implementing endpoints for Flows: `developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/implementingyourflowendpoint`
- Meta — Flows getting started guide: `developers.facebook.com/docs/whatsapp/flows/gettingstarted`
- Meta — Flows API reference: `developers.facebook.com/docs/whatsapp/flows`
- Contexto de negocio del cliente: `REINO AROMAS INICIO..md`
- Cronograma y alcance: `DESARROLLO..md`, `SKILL.md` (reino-aromas-crm), `SKILL.md` (reino-aromas-backend)
