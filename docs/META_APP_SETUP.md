# Configuración de la app en Meta for Developers — Reino Aromas

Guía completa para dejar la app operativa: desde los campos del dashboard hasta
la aprobación en App Review.

**Datos reales de la app (verificados el 2026-08-10):**

| Campo | Valor |
|---|---|
| Nombre | Reino Aromas |
| App ID | `1675056933828057` |
| Tu rol | admin |
| Estado | `dev_mode` (no está en vivo) |
| Email de contacto | `reinoaromas3@gmail.com` — **sin verificar** |
| Dominio | `reinoaromas.tech` |

**Bloqueadores actuales para App Review** (los reporta la propia API de Meta):

- `has_privacy_policy: false` — falta la URL de política de privacidad
- `business_verification_passes: false` — falta verificación del negocio

Ambos se resuelven en las secciones 2 y 6 de esta guía.

---

## 1. Configuración básica (App Dashboard → Configuración → Básica)

Rellena estos campos exactamente así:

| Campo | Valor a poner |
|---|---|
| Nombre para mostrar | `Reino Aromas` |
| Correo de contacto | `reinoaromas3@gmail.com` (**verifícalo**, hoy está sin verificar) |
| URL de política de privacidad | `https://reinoaromas.tech/privacidad` |
| URL de condiciones del servicio | `https://reinoaromas.tech/terminos` |
| URL de eliminación de datos | `https://reinoaromas.tech/eliminacion-de-datos` |
| Icono de la app | 1024×1024 px, PNG, fondo sólido (hoy está vacío) |
| Categoría | `Empresas y páginas` |
| Dominios de la app | `reinoaromas.tech` |
| URL del sitio | `https://reinoaromas.tech` |

> **Ojo con la URL de eliminación de datos.** Meta acepta dos formas: una URL de
> instrucciones (más simple, es la que recomiendo) o un *callback* que recibe un
> `signed_request` firmado y devuelve JSON. Con la URL de instrucciones basta
> para cumplir, siempre que la página explique el procedimiento — la sección 8
> incluye el texto listo.

### Verificar el correo de contacto

Sin esto Meta bloquea la publicación de la app. Ve a Configuración → Básica,
haz clic en el aviso junto al correo y confirma desde la bandeja de
`reinoaromas3@gmail.com`.

---

## 2. Verificación del negocio (Business Verification)

Es el bloqueador más lento — **empiézalo hoy**, tarda de 2 días a 2 semanas.

Ruta: **Meta Business Suite → Configuración del negocio → Centro de seguridad →
Iniciar verificación**.

Documentos que pide para una empresa venezolana:

1. **Documento de constitución legal** — RIF de la empresa o registro mercantil.
   El nombre debe coincidir *exactamente* con el del portafolio de negocio.
2. **Comprobante de domicilio** — recibo de servicio (electricidad, internet) o
   estado de cuenta bancario a nombre de la empresa, con menos de 90 días.
3. **Verificación telefónica o de dominio** — Meta llama, envía SMS, o pide un
   registro DNS `TXT` en `reinoaromas.tech`. El DNS suele ser lo más rápido.

**Consejos que evitan rechazos:**

- El nombre del portafolio en Business Suite debe ser idéntico al del RIF,
  incluyendo abreviaturas (`C.A.`, `S.A.`). Si el RIF dice `REINO AROMAS C.A.`,
  el portafolio no puede decir `Reino Aromas`.
- Sube PDFs o fotos nítidas y completas: los cuatro bordes visibles, sin
  recortes, sin reflejos.
- Si rechazan, Meta indica el motivo en el Centro de seguridad. Corrige y
  reenvía — no hay penalización por reintentar.

---

## 3. Casos de uso y productos a agregar

En **App Dashboard → Casos de uso**, agrega:

| Producto | Para qué lo usa el CRM |
|---|---|
| WhatsApp | Recibir y responder mensajes de clientes |
| Instagram (Messenger API) | Recibir y responder DMs |
| Facebook Login for Business | OAuth para vincular la página y la cuenta IG |
| Webhooks | Notificaciones en tiempo real de mensajes entrantes |

### Permisos que vas a solicitar

**WhatsApp:**
- `whatsapp_business_messaging` — enviar y recibir mensajes
- `whatsapp_business_management` — gestionar la cuenta y las plantillas

**Instagram (vía Messenger API con Facebook Login):**
- `instagram_basic`
- `instagram_manage_messages`
- `pages_manage_metadata` — necesario para suscribir los webhooks
- `pages_show_list`
- `pages_read_engagement`

**Facebook (páginas):**
- `pages_messaging`
- `pages_manage_metadata`

> `business_management` solo hace falta si vas a leer datos del portafolio desde
> la API. El CRM no lo hace hoy — no lo pidas, cada permiso extra alarga la
> revisión.

---

## 4. Configuración de WhatsApp Cloud API

1. **App Dashboard → WhatsApp → Configuración de la API.**
2. Conecta o crea una **cuenta de WhatsApp Business (WABA)**. Anota el
   **WABA ID**.
3. Agrega el **número de teléfono de negocio**. Debe ser un número que **no**
   esté registrado en la app normal de WhatsApp ni en WhatsApp Business — si lo
   está, primero hay que borrar esa cuenta desde el teléfono.
4. Verifica el número por SMS o llamada.
5. Anota el **Phone Number ID** — no es el número telefónico, es un ID numérico
   largo. Va en `META_WHATSAPP_PHONE_NUMBER_ID`.

### Token permanente (System User)

El token que da el dashboard **expira en 24 horas**: no sirve para producción.
Genera uno permanente:

1. **Business Suite → Configuración del negocio → Usuarios del sistema → Agregar.**
2. Nombre: `reino-aromas-crm`, rol: **Administrador**.
3. **Asignar activos** → selecciona la app `Reino Aromas` → activa *Administrar app*.
4. **Asignar activos** → selecciona la WABA → activa *Administrar cuentas de WhatsApp Business*.
5. **Generar token** con estos permisos:
   - `whatsapp_business_messaging`
   - `whatsapp_business_management`
   - `pages_messaging` (para Facebook/Instagram)
   - `instagram_basic`, `instagram_manage_messages`, `pages_manage_metadata`
6. En *Caducidad del token* elige **Nunca**.
7. Copia el token **en ese momento** — Meta no lo vuelve a mostrar.

### Verificación en dos pasos

Cloud API la exige. Define un PIN de 6 dígitos en
**WhatsApp Manager → tu número → Configuración → Verificación en dos pasos**.
Guárdalo: te lo piden al re-registrar el número.

---

## 5. Configuración de Instagram

**Requisitos previos:**
- Cuenta de Instagram **profesional** (Empresa o Creador), no personal.
- Página de Facebook **vinculada** a esa cuenta de Instagram.
- Tu usuario de Meta debe poder hacer la tarea **MODERAR** en esa página.

**Paso que se olvida siempre y rompe todo:**

En la app de Instagram (en el teléfono):
**Configuración → Mensajes y respuestas a historias → Controles de mensajes →
Herramientas conectadas → activar "Permitir acceso a los mensajes".**

Sin este toggle, la API no ve ni un solo DM aunque el webhook esté perfecto.

---

## 6. Webhooks — configuración exacta para este CRM

Tu backend ya tiene los endpoints implementados y verificados en el código.
Estos son los valores exactos:

| Producto | Callback URL | Verify Token |
|---|---|---|
| WhatsApp | `https://reinoaromas.tech/api/webhooks/whatsapp` | el valor de `META_WEBHOOK_VERIFY_TOKEN` |
| Instagram | `https://reinoaromas.tech/api/webhooks/instagram` | el mismo valor |

**Campos a suscribir:**

- WhatsApp → `messages` (obligatorio). Opcional: `message_template_status_update`.
- Instagram → `messages`, `messaging_postbacks`, `messaging_seen`.

**Cómo funciona la verificación en tu código:** Meta hace un `GET` con
`hub.mode`, `hub.verify_token` y `hub.challenge`. PHP convierte los puntos en
guiones bajos, por eso los controladores leen `hub_mode` — está correcto, no lo
"arregles". El controlador compara el token contra
`config('services.meta.webhook_verify_token')` y devuelve el challenge en texto
plano.

**Requisitos del endpoint** (ya cumplidos por tu VPS):
- HTTPS con certificado válido → lo tienes con certbot.
- Responder en menos de 5 segundos → los controladores responden `200` de
  inmediato y despachan a un Job.
- Firma HMAC-SHA256 en `X-Hub-Signature-256` → ya se valida contra
  `META_APP_SECRET`.

> **La app debe estar publicada (Live) para recibir webhooks**, sin importar el
> estado de App Review. En modo desarrollo solo llegan eventos de usuarios con
> rol en la app.

### Variables a cargar en el `.env` del VPS

Hoy están **todas vacías**, por eso no se procesa ningún mensaje:

```env
META_APP_SECRET=              # Dashboard → Configuración → Básica → Clave secreta
META_ACCESS_TOKEN=            # el token permanente del System User (sección 4)
META_WEBHOOK_VERIFY_TOKEN=    # inventado por ti, mismo valor en el dashboard
META_GRAPH_API_VERSION=v21.0  # ya tiene valor por defecto
META_INSTAGRAM_ACCOUNT_ID=    # ID de la cuenta profesional de Instagram
META_WHATSAPP_PHONE_NUMBER_ID= # Phone Number ID de la sección 4
```

Para generar un verify token seguro:

```bash
openssl rand -hex 32
```

Después de editar el `.env` en el VPS, **los tres pasos son obligatorios**:

```bash
cd /var/www/reinoaromas && \
  /usr/bin/php8.4 artisan config:cache && \
  systemctl restart reino-queue
```

Sin el `restart` el worker sigue corriendo con los valores viejos en memoria.

---

## 7. App Review — qué pide Meta y cómo responder

Antes de enviar, deben estar listos: política de privacidad publicada,
verificación del negocio aprobada, y la app funcionando en modo desarrollo.

Para **cada permiso** hay que entregar tres cosas:

1. **Descripción del caso de uso** — cómo lo usa tu app y por qué lo necesita.
2. **Video de demostración** — pantalla grabada mostrando el flujo completo.
3. **Instrucciones de prueba paso a paso** — para el revisor de Meta.

### Texto sugerido por permiso

**`whatsapp_business_messaging`**

> Reino Aromas es un CRM interno que usa nuestro equipo de atención al cliente
> para gestionar consultas sobre cursos artesanales y venta de insumos. Este
> permiso permite recibir los mensajes que los clientes envían a nuestro número
> de WhatsApp Business y responderlos desde la bandeja unificada del CRM. Los
> agentes responden manualmente; no hay envío masivo ni automatizado. Todos los
> mensajes provienen de clientes que iniciaron la conversación.

**`instagram_manage_messages`**

> Nuestros clientes consultan por mensajes directos de Instagram sobre precios,
> fechas de cursos y disponibilidad de insumos. Este permiso permite leer esos
> mensajes y responderlos desde el CRM, evitando que el equipo cambie entre
> aplicaciones. Solo se accede a las conversaciones de la cuenta profesional de
> Instagram que es propiedad del negocio.

**`pages_manage_metadata`**

> Se usa exclusivamente para suscribir nuestra aplicación a los webhooks de la
> página de Facebook vinculada, de modo que el CRM reciba notificaciones en
> tiempo real de mensajes nuevos. No se modifica ninguna otra configuración de
> la página.

### El video de demostración

Debe mostrar, en una sola grabación continua:

1. Login en `https://reinoaromas.tech` con un usuario agente.
2. Un cliente enviando un mensaje de WhatsApp (o DM de Instagram) desde otro teléfono.
3. Ese mensaje apareciendo en la bandeja del CRM.
4. El agente respondiendo desde el CRM.
5. La respuesta llegando al teléfono del cliente.

Sin subtítulos en inglés lo suelen rechazar. Sube el video sin listar y pega el
enlace, o cárgalo directo en el formulario.

### Credenciales de prueba

Meta necesita entrar a tu CRM. Crea un usuario dedicado y pásalo en el campo
correspondiente:

```
URL:      https://reinoaromas.tech/login
Usuario:  reviewer@reinoaromas.tech
Password: (una contraseña que no reutilices)
```

No borres ese usuario mientras la revisión esté abierta.

---

## 8. Textos legales a publicar en el sitio

Las tres páginas siguientes deben estar **públicas y accesibles sin login** en
`reinoaromas.tech`. Meta las abre automáticamente y rechaza la app si devuelven
404 o piden autenticación.

> **Antes de publicar:** reemplaza los marcadores `[...]` con los datos reales de
> la empresa. Estos textos cubren lo que Meta exige, pero no son asesoría legal
> — si manejan datos sensibles, conviene que un abogado los revise.

---

### 8.1 — `https://reinoaromas.tech/privacidad`

```markdown
# Política de Privacidad

**Última actualización:** [FECHA]

## 1. Quiénes somos

Reino Aromas ([RAZÓN SOCIAL COMPLETA], RIF [NÚMERO]) es una empresa venezolana
dedicada a la formación en oficios artesanales y a la venta de insumos para su
elaboración. Operamos en Caracas, Valencia, Barquisimeto, Maracay y Margarita.

Responsable del tratamiento de datos: Reino Aromas
Correo de contacto: [CORREO DE CONTACTO]
Dirección: [DIRECCIÓN FÍSICA]

## 2. Qué información recopilamos

Cuando usted nos contacta por WhatsApp, Instagram o Facebook, recibimos y
almacenamos:

- **Datos de identificación:** su nombre o nombre de usuario tal como aparece en
  la plataforma desde la que nos escribe.
- **Datos de contacto:** su número de teléfono (WhatsApp) o su identificador de
  cuenta (Instagram, Facebook).
- **Contenido de las conversaciones:** los mensajes que usted nos envía y las
  respuestas de nuestro equipo, incluidas imágenes, audios y documentos que
  comparta con nosotros.
- **Foto de perfil pública**, cuando la plataforma la pone a nuestra disposición.
- **Datos comerciales:** la ciudad desde la que nos escribe y el curso o producto
  por el que consulta, para poder atenderle correctamente.

No recopilamos datos de tarjetas de crédito, contraseñas ni documentos de
identidad a través de estos canales.

## 3. Para qué usamos su información

Usamos su información únicamente para:

- Responder sus consultas sobre cursos, precios, fechas y disponibilidad.
- Gestionar su inscripción a un curso o su pedido de insumos.
- Mantener el historial de la conversación para que cualquier miembro de nuestro
  equipo pueda continuar atendiéndole sin que usted repita la información.
- Enviarle confirmaciones y recordatorios relacionados con una inscripción o
  pedido que usted haya solicitado.

**No usamos su información para publicidad dirigida, no la vendemos, y no la
compartimos con terceros con fines comerciales.**

## 4. Base legal del tratamiento

Tratamos sus datos porque usted inició voluntariamente una conversación con
nosotros solicitando información o un servicio. Puede retirar su consentimiento
en cualquier momento escribiéndonos.

## 5. Con quién compartimos su información

Compartimos datos únicamente con:

- **Meta Platforms, Inc.** — como proveedor de la infraestructura de WhatsApp
  Business Platform e Instagram Messaging, por la cual transitan los mensajes.
  El tratamiento de Meta se rige por su propia política de privacidad.
- **Nuestro proveedor de alojamiento** — donde se almacena nuestra base de datos,
  en servidores con acceso restringido.

No compartimos su información con anunciantes, corredores de datos ni ninguna
otra empresa.

## 6. Cuánto tiempo conservamos su información

Conservamos las conversaciones mientras exista una relación comercial activa y
hasta **24 meses** después del último contacto. Cumplido ese plazo, los datos se
eliminan de forma permanente, salvo obligación legal de conservarlos por más
tiempo.

## 7. Cómo protegemos su información

- Todas las comunicaciones con nuestro sistema viajan cifradas mediante HTTPS.
- El acceso al CRM está restringido a personal autorizado, con usuario y
  contraseña individuales.
- Los mensajes entrantes se validan criptográficamente para confirmar que
  provienen de Meta y no de un tercero.

## 8. Sus derechos

Usted puede, en cualquier momento y sin costo:

- **Acceder** a los datos que tenemos sobre usted.
- **Rectificar** los datos que sean incorrectos.
- **Solicitar la eliminación** de sus datos (ver sección 9).
- **Oponerse** a que sigamos tratando su información.
- **Solicitar una copia** de sus datos en formato legible.

Para ejercer cualquiera de estos derechos escriba a **[CORREO DE CONTACTO]**.
Responderemos en un plazo máximo de **30 días**.

## 9. Cómo solicitar la eliminación de sus datos

Puede pedir que borremos toda su información por cualquiera de estas vías:

1. Escribiendo a **[CORREO DE CONTACTO]** con el asunto "Eliminación de datos",
   indicando el número de teléfono o el usuario desde el que nos contactó.
2. Enviándonos un mensaje por el mismo canal por el que nos escribió, con el
   texto "Solicito la eliminación de mis datos".
3. Siguiendo las instrucciones en
   **https://reinoaromas.tech/eliminacion-de-datos**

Procesamos estas solicitudes en un plazo máximo de **30 días** y le confirmamos
por escrito cuando la eliminación se haya completado.

## 10. Menores de edad

Nuestros servicios están dirigidos a personas mayores de 18 años. No
recopilamos intencionalmente datos de menores. Si detectamos que hemos recibido
información de un menor, la eliminamos de inmediato.

## 11. Cambios en esta política

Si modificamos esta política, publicaremos la versión actualizada en esta misma
dirección con una nueva fecha de "última actualización". Los cambios
sustanciales se comunicarán por el canal habitual de contacto.

## 12. Contacto

Para cualquier duda sobre esta política o sobre el tratamiento de sus datos:

**Reino Aromas**
Correo: [CORREO DE CONTACTO]
Teléfono: [TELÉFONO]
Dirección: [DIRECCIÓN FÍSICA]
```

---

### 8.2 — `https://reinoaromas.tech/terminos`

```markdown
# Términos y Condiciones de Uso

**Última actualización:** [FECHA]

## 1. Aceptación

Al comunicarse con Reino Aromas a través de WhatsApp, Instagram, Facebook o
nuestro sitio web, usted acepta estos términos. Si no está de acuerdo, le
pedimos abstenerse de usar estos canales.

## 2. Qué ofrecemos

Reino Aromas ofrece:

- **Cursos presenciales de oficios artesanales:** elaboración de velas, jabones,
  difusores, sales aromáticas y mantequilla corporal.
- **Venta de insumos:** ceras de soja, fragancias, mechas, moldes y materiales
  relacionados.

Los canales de mensajería se usan exclusivamente para atención al cliente:
consultas, inscripciones y coordinación de pedidos.

## 3. Uso aceptable

Al comunicarse con nosotros, usted se compromete a **no**:

- Enviar contenido ilegal, ofensivo, discriminatorio, amenazante o que incite a
  la violencia.
- Enviar spam, publicidad no solicitada o mensajes masivos automatizados.
- Suplantar la identidad de otra persona o empresa.
- Intentar acceder sin autorización a nuestros sistemas o interferir con su
  funcionamiento.
- Usar nuestros canales para actividades fraudulentas o para promocionar
  productos de terceros.

Nos reservamos el derecho de dejar de atender y bloquear a quien incumpla estas
condiciones.

## 4. Inscripciones y pagos

- Los precios de cursos e insumos varían según la ciudad y se informan al
  momento de la consulta.
- Una inscripción se considera confirmada únicamente cuando se ha recibido el
  pago y hemos enviado la confirmación correspondiente.
- Las condiciones de cancelación, reprogramación y devolución se informan al
  confirmar la inscripción.
- Los pagos se procesan por los medios que indiquemos en cada caso. **Nunca
  solicitamos datos de tarjetas de crédito ni contraseñas por mensajería.**

## 5. Disponibilidad y horarios

Atendemos en horario laboral. Los mensajes recibidos fuera de ese horario se
responden el siguiente día hábil. No garantizamos disponibilidad ininterrumpida
de los canales, ya que dependen de servicios de terceros (Meta) sobre los que no
tenemos control.

## 6. Comunicaciones por WhatsApp e Instagram

- Solo le escribimos si usted inició la conversación o si solicitó que le
  contactáramos.
- Puede pedirnos que dejemos de escribirle en cualquier momento respondiendo
  con "BAJA" o "STOP", y dejaremos de enviarle mensajes.
- Estos servicios son operados por Meta Platforms, Inc. y su uso está también
  sujeto a los términos de Meta.

## 7. Propiedad intelectual

El contenido de nuestros cursos, materiales didácticos, fotografías, textos y
logotipos son propiedad de Reino Aromas. No está permitido reproducirlos,
distribuirlos ni usarlos comercialmente sin autorización escrita.

## 8. Limitación de responsabilidad

Los cursos entregan formación e información general. Reino Aromas no se
responsabiliza por el uso que cada participante haga de las técnicas aprendidas
ni por los resultados de productos elaborados por cuenta propia. La elaboración
de productos artesanales implica el manejo de materiales calientes y sustancias
químicas: es responsabilidad de cada persona seguir las medidas de seguridad
indicadas.

## 9. Protección de datos

El tratamiento de su información personal se rige por nuestra
[Política de Privacidad](https://reinoaromas.tech/privacidad).

## 10. Modificaciones

Podemos actualizar estos términos en cualquier momento. La versión vigente será
siempre la publicada en esta dirección.

## 11. Legislación aplicable

Estos términos se rigen por las leyes de la República Bolivariana de Venezuela.
Cualquier controversia se someterá a los tribunales competentes de [CIUDAD].

## 12. Contacto

**Reino Aromas**
Correo: [CORREO DE CONTACTO]
Teléfono: [TELÉFONO]
Dirección: [DIRECCIÓN FÍSICA]
```

---

### 8.3 — `https://reinoaromas.tech/eliminacion-de-datos`

```markdown
# Eliminación de datos

**Última actualización:** [FECHA]

Si usted se comunicó con Reino Aromas por WhatsApp, Instagram o Facebook,
guardamos su nombre, su identificador de contacto y el historial de la
conversación para poder atenderle. Puede pedirnos que eliminemos toda esa
información en cualquier momento y sin costo.

## Cómo solicitar la eliminación

Elija la vía que le resulte más cómoda:

### Opción 1 — Por correo electrónico

Escriba a **[CORREO DE CONTACTO]** con:

- **Asunto:** Eliminación de datos
- **En el mensaje:** el número de teléfono o el nombre de usuario desde el que
  nos contactó, para poder localizar su información.

### Opción 2 — Por el mismo canal de mensajería

Envíenos un mensaje por WhatsApp o Instagram con el texto:

> Solicito la eliminación de mis datos

### Opción 3 — Desde Facebook

Si nos contactó por Facebook, puede ir a
**Configuración y privacidad → Configuración → Apps y sitios web**, seleccionar
Reino Aromas y pulsar **Eliminar**. Esto nos notifica automáticamente su
solicitud.

## Qué eliminamos

Al procesar su solicitud borramos de forma permanente:

- Su nombre y su foto de perfil.
- Su número de teléfono o identificador de cuenta.
- El historial completo de mensajes intercambiados.
- Las notas internas y etiquetas asociadas a su contacto.

## Qué podríamos conservar

Si usted realizó un pago o se inscribió en un curso, es posible que debamos
conservar el registro contable de esa operación por el tiempo que exige la
legislación fiscal venezolana. En ese caso conservamos únicamente el dato mínimo
necesario para cumplir esa obligación, y nada más.

## Plazos

- **Confirmación de recibo:** dentro de las 72 horas hábiles.
- **Eliminación completa:** máximo 30 días desde la solicitud.
- **Confirmación final:** le escribimos cuando el proceso haya terminado.

## Contacto

**Reino Aromas**
Correo: [CORREO DE CONTACTO]
Teléfono: [TELÉFONO]
```

---

## 9. Checklist antes de enviar a revisión

Configuración básica:

- [ ] Correo de contacto verificado
- [ ] Icono de la app subido (1024×1024)
- [ ] URL de privacidad respondiendo 200 sin login
- [ ] URL de términos respondiendo 200 sin login
- [ ] URL de eliminación de datos respondiendo 200 sin login
- [ ] Dominio `reinoaromas.tech` agregado en Dominios de la app

Negocio:

- [ ] Verificación del negocio **aprobada**
- [ ] Nombre del portafolio idéntico al del RIF

WhatsApp:

- [ ] WABA creada y número verificado
- [ ] PIN de dos pasos configurado y guardado
- [ ] Token permanente de System User generado
- [ ] `META_WHATSAPP_PHONE_NUMBER_ID` cargado en el `.env`

Instagram:

- [ ] Cuenta profesional vinculada a la página de Facebook
- [ ] Toggle "Permitir acceso a los mensajes" **activado**
- [ ] `META_INSTAGRAM_ACCOUNT_ID` cargado en el `.env`

Webhooks:

- [ ] Callback de WhatsApp verificado (marca verde en el dashboard)
- [ ] Callback de Instagram verificado
- [ ] Campo `messages` suscrito en ambos
- [ ] `META_APP_SECRET` y `META_WEBHOOK_VERIFY_TOKEN` cargados
- [ ] `config:cache` corrido y `reino-queue` reiniciado

App Review:

- [ ] Video de demostración grabado con subtítulos en inglés
- [ ] Usuario de prueba creado para el revisor
- [ ] Justificación escrita para cada permiso
- [ ] App publicada en modo **Live**

---

## 10. Cómo probar que todo funciona

Con las variables cargadas y el worker reiniciado, escribe desde otro teléfono
al número de WhatsApp del negocio y corre en el VPS:

```bash
cd /var/www/reinoaromas && { \
  echo "--- LOG (ultimas 30) ---"; \
  tail -n 30 storage/logs/laravel.log; \
  echo "--- JOBS EN COLA ---"; \
  /usr/bin/php8.4 artisan queue:monitor default; \
  echo "--- JOBS FALLIDOS ---"; \
  /usr/bin/php8.4 artisan queue:failed; \
  echo "--- MENSAJES EN BD ---"; \
  /usr/bin/php8.4 artisan tinker --execute="echo App\Models\Message::count();"; \
}
```

Qué buscar:

- `[WhatsApp] Webhook verificado correctamente` → la verificación pasó.
- `Firma inválida en webhook entrante` → `META_APP_SECRET` está mal o falta
  correr `config:cache`.
- Jobs fallidos → mira el error con `queue:failed --verbose`.
- Contador de mensajes en 0 con webhook verificado → el worker no está corriendo:
  `systemctl status reino-queue`.

---

## 11. Errores frecuentes y qué significan

| Síntoma | Causa habitual |
|---|---|
| El webhook no verifica (marca roja) | El verify token del dashboard no coincide con el del `.env`, o falta `config:cache` |
| Verifica pero no llegan mensajes | La app está en modo desarrollo — debe estar **Live** |
| `Firma inválida en webhook entrante` | `META_APP_SECRET` incorrecto o vacío |
| Instagram no entrega ningún DM | Falta el toggle "Permitir acceso a los mensajes" en la app de Instagram |
| Error 190 al enviar | El token expiró — usa el del System User, no el temporal |
| Error 131047 en WhatsApp | Pasaron 24h desde el último mensaje del cliente; hay que usar una plantilla aprobada |
| App Review rechazado sin detalle | Casi siempre el video no muestra el flujo completo o no tiene subtítulos en inglés |

---

## 12. Orden recomendado de ejecución

Lo que tarda va primero:

1. **Hoy:** iniciar verificación del negocio (sección 2) — es lo más lento.
2. **Hoy:** publicar las tres páginas legales (sección 8) y cargarlas en el
   dashboard (sección 1).
3. **Hoy:** verificar el correo de contacto.
4. Configurar WhatsApp y generar el token permanente (sección 4).
5. Configurar Instagram y activar el toggle (sección 5).
6. Cargar el `.env` y configurar los webhooks (sección 6).
7. Probar de punta a punta (sección 10).
8. Grabar el video y enviar a App Review (sección 7).
