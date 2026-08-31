# Puesta en marcha: SSH, .env, Reverb, WhatsApp y Flows

**Escrito:** 2026-08-30
**Para:** dejar el VPS operativo y poder probar WhatsApp y Flows de punta a punta.

Cada sección dice **qué haces tú** y **qué hago yo**. Lo que lleva 🔴 te bloquea
hoy; lo que lleva 🟡 es mejora.

---

## 1. Darme acceso SSH

### Por qué

Ahora mismo diagnostico a ciegas: leo `.env.prod` del repo local, que puede estar
desincronizado con el `.env` real del servidor. Con SSH leo el estado de verdad,
pruebo, leo el log y corrijo sin que hagas de intermediario copiando y pegando.

### Lo que hago con acceso

- Leer el `.env` real y comparar con lo que espera el código
- `systemctl status reino-reverb` y cerrar el 404 de los WebSockets
- Ver si el proxy de nginx para `/app` y `/apps` existe
- Confirmar el `VARCHAR(255)` de `profile_picture_url` contra MySQL
- Probar el envío de WhatsApp con curl y ver la respuesta real de Meta
- Desplegar y verificar que el cron deja de fallar

### Lo que NO se resuelve con SSH

Estas son de navegador y siguen siendo tuyas:

- Registrar el número en el panel de Meta
- Crear el Flow y publicarlo
- Poner los dominios en Allowed domains

### Pasos

**1. Genera una clave dedicada.** No uses tu `id_rsa` — esa abre
`beta.channels.widening.io` y no tiene por qué entrar en esto.

```bash
ssh-keygen -t ed25519 -f ~/.ssh/reinoaromas_tmp -C "claude-debug-temporal"
```

Déjala sin passphrase (si le pones una, no puedo usarla desatendida).

**2. Sube la pública al VPS.** Desde donde ya tengas acceso:

```bash
cat ~/.ssh/reinoaromas_tmp.pub
```

Y ese contenido lo pegas en el `~/.ssh/authorized_keys` del usuario del VPS.

**3. Dime los datos de conexión por el chat** — no son secretos:

- IP o dominio del VPS
- usuario (`root`, `deploy`, otro)
- puerto si no es el 22

**4. Cuando terminemos, revócala.** Borras esa línea del `authorized_keys` y
listo. Tu clave principal nunca estuvo en juego.

### Reglas con las que voy a trabajar

- **Solo lectura primero.** Te paso el diagnóstico antes de tocar nada.
- **Backup del `.env`** antes de editarlo (`cp .env .env.bak.FECHA`).
- **Te consulto uno por uno**: `ALTER TABLE`, escribir el `.env`, tocar nginx,
  reiniciar servicios.
- **Nunca** `migrate:fresh`, `db:wipe` ni nada que borre datos.

---

## 2. Variables del `.env` de producción

Comparé el código con `.env.prod`. Esto es lo que falta o está mal.

### 🔴 Faltan (bloquean WhatsApp)

| Variable | Por qué | De dónde sale |
|---|---|---|
| `META_WHATSAPP_PHONE_NUMBER_ID` | Está **vacía**. `WhatsAppService.php:372` la lee para enviar: sin ella, el envío falla | Panel: WhatsApp → API Setup |
| `FLOWS_PRIVATE_KEY` | Sin ella el endpoint de Flows responde 500 | `php artisan flows:generate-keys` |
| `FLOWS_PASSPHRASE` | La del par de claves | La eliges tú al generar |
| `FLOWS_WELCOME_ID` | El id del Flow publicado | Panel: WhatsApp → Flows |

### 🟡 Solo si vas a usar Embedded Signup

`META_SIGNUP_WHATSAPP_CONFIG_ID` — no está en ningún `.env`. Ver §4 sobre si de
verdad lo necesitas (spoiler: probablemente no).

### Formato de `FLOWS_PRIVATE_KEY`

Va **entre comillas dobles y con los saltos escapados como `\n`**, en una sola
línea:

```
FLOWS_PRIVATE_KEY="-----BEGIN ENCRYPTED PRIVATE KEY-----\nMIIFHDBOBgkq...\n-----END ENCRYPTED PRIVATE KEY-----"
```

Si la pegas con saltos reales, Laravel lee solo la primera línea y falla.

### Después de tocar el `.env`

```bash
php artisan config:clear && php artisan config:cache
```

Sin esto sigue leyendo la config cacheada. Es la causa nº1 de "lo cambié y no
pasó nada".

---

## 3. Reverb (los WebSockets)

### Qué está roto

El log muestra `Pusher error: <!DOCTYPE html> ... 404 Not Found`, y ese HTML es
**la página de error de Laravel**, no de Reverb. Significa que el POST de
publicación llega a nginx → PHP-FPM → Laravel, que no tiene esa ruta.

Dos causas, las dos probablemente vivas:

1. El servicio `reino-reverb` no existe. `setup-reverb.sh` está en `scripts/`
   pero nunca se corrió (lo dice el commit `3f85f41`).
2. Falta el bloque de proxy en nginx.

### La trampa de las variables

Miré `config/reverb.php` y `.env.prod`, y aquí hay un detalle que se presta a
confusión — **son dos pares distintos y ambos son correctos**:

| Variable | Para qué | Valor en prod |
|---|---|---|
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | Dónde **escucha** el proceso Reverb | `127.0.0.1` / `6001` |
| `REVERB_HOST` / `REVERB_PORT` | A dónde **se conecta** el cliente (navegador) | `reinoaromas.tech` / `443` |

`config/reverb.php:32-33` usa las primeras; `:80-81` usa las segundas.

**Ojo con el puerto:** tu `.env` dice `REVERB_SERVER_PORT=6001`, pero el default
del código es `8080`. El proxy de nginx tiene que apuntar a **6001**, no a 8080.

### Pasos (los hago yo con SSH)

```bash
# 1. Instalar el servicio
sudo bash /var/www/reinoaromas/scripts/setup-reverb.sh

# 2. Verificar que levantó
systemctl status reino-reverb --no-pager

# 3. Comprobar que escucha en 6001
ss -tlnp | grep 6001
```

Y el bloque de nginx (te lo muestro antes de aplicarlo):

```nginx
location ~ ^/(app|apps)/ {
    proxy_pass http://127.0.0.1:6001;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 60s;
}
```

### Cómo pruebas que funciona

1. Abre el CRM en dos pestañas, ambas en Mensajería.
2. Manda un mensaje al Facebook del negocio desde tu teléfono.
3. **Debe aparecer solo en las dos**, sin refrescar.
4. El indicador "En vivo" debe estar verde de verdad — ahora escucha el socket
   real, no si Reverb está configurado en el build.

Si falla, revisa que `storage/logs/laravel.log` ya no tenga `Pusher error`.

---

## 4. WhatsApp

### La buena noticia: no necesitas App Review

La doc de Meta es explícita:

> *"Business apps are automatically approved for Standard Access (...) if you are
> using the API for yourself as a Direct Developer, you do not need Advanced
> access or app review."*

Tu app es tipo **BUSINESS** y Reino Aromas usa su propio número: eres Direct
Developer. **Standard Access te alcanza.** Yo te había dicho lo contrario antes;
me equivoqué y tú lo cuestionaste bien.

**Consecuencia:** el Embedded Signup pierde sentido para tu caso. Ese flujo
existe para que *terceros* vinculen sus números a tu plataforma. Queda construido
y dormido, sin estorbar. Si algún día vendes esto como servicio, se retoma.

### Estado verificado por MCP

| Qué | Estado |
|---|---|
| App `1675056933828057` | ✅ Live, tipo BUSINESS, admin |
| Webhook `whatsapp_business_account` | ✅ activo con `messages`, `account_update`, `smb_*`, `history` |
| Business verification | ✅ pasa |
| `META_WHATSAPP_PHONE_NUMBER_ID` | 🔴 **vacío** |

### Pasos para probar (camino corto)

**1. Saca el `phone_number_id`** — Panel → WhatsApp → API Setup. Es un número
largo, junto al número de teléfono.

**2. Ponlo en el `.env`** del VPS y `config:clear`.

**3. Prueba la recepción:** manda un WhatsApp al número del negocio desde tu
teléfono. Debe aparecer en Mensajería.

**4. Prueba el envío:** responde desde el CRM.

> ⚠️ La **ventana de 24h**: fuera de ella solo salen plantillas aprobadas. Para
> probar texto libre, escribe tú primero desde tu teléfono — eso abre la ventana.

### 🔴 Sobre el número: NO lo registres si ya usas WhatsApp Business

Si el número está hoy en la app de WhatsApp Business, registrarlo en Cloud API
**borra el historial**. Para conservarlo hay que usar **Coexistencia**, que ya
está implementada (los campos `smb_*` del webhook están suscritos).

Dime cuál es el caso antes de tocar nada aquí.

### 🟡 Mejora pendiente que te va a morder

`WhatsAppService.php:372-373` lee las credenciales del `.env`, pero el Embedded
Signup las guarda en la tabla `meta_accounts`. Hoy no importa (usas un solo
número desde el `.env`), pero si algún día vinculas por el popup, **vincular no
cambiará con qué número envías**. Lo dejo anotado; el fix es que
`WhatsAppService` prefiera `meta_accounts` con fallback al `.env`.

---

## 5. WhatsApp Flows

### Qué hay construido

- `FlowEndpointController` — endpoint cifrado, ruta `POST /api/webhooks/flows`
- `php artisan flows:generate-keys` — genera el par RSA
- `php artisan flows:upload-key` — sube la pública a Meta (`--check` verifica)

Falta **solo la configuración**, no código.

### Cómo funciona el cifrado (para que sepas qué estás haciendo)

Meta cifra cada request con tu clave **pública**, y tu servidor descifra con la
**privada**. Por eso el orden importa: generas el par, subes la pública, y la
privada va al `.env` del servidor y **nunca a git**.

### Pasos

**1. Genera el par** (en el VPS, no en local):

```bash
cd /var/www/reinoaromas
php artisan flows:generate-keys
```

Te pide una passphrase. Guárdala: va en `FLOWS_PASSPHRASE`.

**2. Mete la privada en el `.env`** con el formato de `\n` de §2, más la
passphrase.

**3. Limpia la config:**

```bash
php artisan config:clear
```

**4. Sube la pública a Meta:**

```bash
php artisan flows:upload-key
php artisan flows:upload-key --check   # confirma que Meta la tiene
```

**5. Crea el Flow en el panel** — WhatsApp → Flows → Create Flow. La primera
pantalla debe llamarse igual que `FLOWS_WELCOME_FIRST_SCREEN` (default:
`BIENVENIDA`).

**6. Configura el endpoint del Flow:**

```
https://reinoaromas.tech/api/webhooks/flows
```

**7. Publica el Flow** y copia su id a `FLOWS_WELCOME_ID`.

**8. Prueba:** el panel tiene un botón de test que manda un ping cifrado al
endpoint. Si responde correctamente, el cifrado está bien.

### Si falla

- **500 en el endpoint** → `FLOWS_PRIVATE_KEY` mal formateada (saltos reales en
  vez de `\n`), o falta `config:clear`
- **Meta dice que no puede descifrar** → la pública que subiste no corresponde a
  la privada del `.env`. Regenera el par y vuelve a subirla.
- El log filtrado por `[Flows]` en `laravel.log` dice cuál de los dos es.

---

## 6. Orden recomendado

1. **SSH** (§1) — destraba todo lo demás
2. **`phone_number_id`** al `.env` (§2, §4) — te deja probar WhatsApp hoy
3. **Reverb** (§3) — que el tiempo real funcione
4. **Flows** (§5) — es el más largo y depende de que WhatsApp ya funcione

Los puntos 2 y 3 son independientes: se pueden hacer en paralelo.

---

## 7. Pendientes de código que no dependen de ti

Los tengo identificados y verificados, listos para cuando digas:

| Qué | Dónde | Impacto |
|---|---|---|
| 🔴 `profile_picture_url` es `VARCHAR(255)` y las URLs de la CDN de Meta miden ~470 | `2026_05_18_000002_create_contacts_table.php:27` | **Pierde mensajes entrantes**: el `save()` está dentro de la transacción, y al reventar hace rollback del `Message::create()` |
| 🟡 `client_secret` viaja en la query string | `MetaAccountController.php:257` | Queda en logs de proxies. Debe ser POST con body |
| 🟡 `WhatsAppService` no lee de `meta_accounts` | `WhatsAppService.php:372` | Ver §4 |
| ✅ Scope `identifiedByChannel` | `Contact.php:51` | **Ya aplicado**, sin commitear |
| ✅ Modo oscuro | varios | **Ya aplicado**, build en verde |
| 📄 Modal de ficha sin `<Teleport>` | `docs/fix-modal-ficha-cliente.md` | Documentado, sin aplicar por pedido tuyo |
