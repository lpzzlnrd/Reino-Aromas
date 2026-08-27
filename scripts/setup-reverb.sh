#!/usr/bin/env bash
#
# Instala Laravel Reverb como servicio en el VPS.
#
# Levanta el servidor de WebSockets, lo pone detrás del proxy de nginx (TLS) y
# arregla la configuración del .env que impedía que funcionara.
#
# Es IDEMPOTENTE: se puede correr varias veces. Comprueba antes de cambiar y
# hace copia de seguridad de lo que toca.
#
#   sudo bash scripts/setup-reverb.sh
#
# Por qué hace falta un proxy y no basta con abrir el puerto:
#
#   Reverb escucha en un puerto alto sin TLS. Un `ufw allow 6001` no sirve: el
#   navegador bloquea una conexión ws:// abierta desde una página https://
#   (mixed content), y sin certificado no hay wss://. La única vía es que nginx
#   reciba en 443 con el certificado que ya tiene y pase la conexión al puerto
#   interno. Así tampoco hay que tocar el firewall.
#
# Ver DEPLOY.md sección 7.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/reinoaromas}"
PHP="${PHP:-/usr/bin/php8.4}"
SERVICIO="reino-reverb"
NGINX_SITE="/etc/nginx/sites-available/reinoaromas"
ENV_FILE="$APP_DIR/.env"

# Puerto interno de Reverb. NO es 8080: ese lo usa phpMyAdmin en 127.0.0.1:8080.
PUERTO="${REVERB_PORT_INTERNO:-6001}"

marca="$(date +%Y%m%d%H%M%S)"

log()   { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
ok()    { printf '    \033[0;32m✓\033[0m %s\n' "$*"; }
aviso() { printf '    \033[0;33m!\033[0m %s\n' "$*"; }
fatal() { printf '\n\033[0;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fatal "Hay que correrlo como root (sudo)."
[[ -d $APP_DIR ]] || fatal "No existe $APP_DIR."
[[ -f $ENV_FILE ]] || fatal "No existe $ENV_FILE."
[[ -x $PHP ]] || fatal "No existe $PHP. En el VPS hay 8.3, 8.4 y 8.5: hay que usar la ruta explícita de la 8.4."

# ---------------------------------------------------------------------------
# 0. El puerto tiene que estar libre
# ---------------------------------------------------------------------------
log "Comprobando que el puerto $PUERTO esté libre"

# El grep se limita a localhost:PUERTO para no confundirse con un puerto que
# empiece igual (60011) ni con el propio Reverb si ya está corriendo.
if ss -tlnp 2>/dev/null | grep -qE "127\.0\.0\.1:$PUERTO |0\.0\.0\.0:$PUERTO |\*:$PUERTO "; then
    if systemctl is-active --quiet "$SERVICIO"; then
        aviso "Lo ocupa $SERVICIO, que ya está corriendo. Se reiniciará al final."
    else
        # Caso real (2026-08-27): un `reverb:start` arrancado a mano que quedó
        # huérfano, ocupando el puerto sin systemd que lo gestione. No es un
        # conflicto de verdad — es lo que este script viene a reemplazar — así
        # que se para y se sigue, en vez de abortar y dejarlo suelto.
        PID_OCUPA=$(ss -tlnpH 2>/dev/null \
            | grep -E "127\.0\.0\.1:$PUERTO |0\.0\.0\.0:$PUERTO |\*:$PUERTO " \
            | grep -oE 'pid=[0-9]+' | head -1 | cut -d= -f2 || true)

        CMD_OCUPA=""
        [[ -n ${PID_OCUPA:-} ]] && CMD_OCUPA=$(tr '\0' ' ' < "/proc/$PID_OCUPA/cmdline" 2>/dev/null || true)

        if [[ $CMD_OCUPA == *"reverb:start"* ]]; then
            aviso "Un reverb:start suelto (PID $PID_OCUPA) ocupa el puerto. Se para: systemd lo va a gestionar."
            kill "$PID_OCUPA" 2>/dev/null || true

            # Se le da margen a que suelte el socket antes de seguir.
            for _ in 1 2 3 4 5 6 7 8 9 10; do
                kill -0 "$PID_OCUPA" 2>/dev/null || break
                sleep 1
            done

            kill -0 "$PID_OCUPA" 2>/dev/null && kill -9 "$PID_OCUPA" 2>/dev/null || true
            ok "Puerto liberado"
        else
            ss -tlnp | grep ":$PUERTO " || true
            fatal "El puerto $PUERTO lo ocupa otro proceso (${CMD_OCUPA:-desconocido}). Elegí otro con REVERB_PORT_INTERNO=6002."
        fi
    fi
else
    ok "Puerto $PUERTO libre"
fi

# ---------------------------------------------------------------------------
# 1. Variables del .env
# ---------------------------------------------------------------------------
log "Revisando el .env"

cp -a "$ENV_FILE" "$ENV_FILE.bak.$marca"
ok "Copia en $ENV_FILE.bak.$marca"

# Avisa de la trampa que ya mordió una vez: dotenv se queda con la ÚLTIMA
# definición, así que una variable duplicada hace que la primera no exista y el
# síntoma no menciona en ningún momento que haya un duplicado.
for var in BROADCAST_CONNECTION REVERB_APP_KEY REVERB_APP_SECRET REVERB_APP_ID \
           REVERB_HOST REVERB_PORT REVERB_SCHEME REVERB_SERVER_HOST REVERB_SERVER_PORT; do
    n=$(grep -cE "^[[:space:]]*$var=" "$ENV_FILE" || true)
    if [[ $n -gt 1 ]]; then
        aviso "$var está definida $n veces: dotenv usa la última. Revisá y borrá las de más."
    fi
done

# Escribe una variable respetando una definición previa que ya sea correcta.
# `sed -i` sobre la última coincidencia, o append si no existe.
poner_env() {
    local clave="$1" valor="$2"

    if grep -qE "^[[:space:]]*$clave=" "$ENV_FILE"; then
        local actual
        actual=$(grep -E "^[[:space:]]*$clave=" "$ENV_FILE" | tail -1 | cut -d= -f2-)

        if [[ "$actual" == "$valor" ]]; then
            ok "$clave ya vale $valor"
            return
        fi

        sed -i -E "s|^[[:space:]]*$clave=.*|$clave=$valor|" "$ENV_FILE"
        ok "$clave: $actual → $valor"
    else
        printf '%s=%s\n' "$clave" "$valor" >> "$ENV_FILE"
        ok "$clave=$valor (añadida)"
    fi
}

# El bug que impedía que funcionara.
#
# REVERB_SERVER_HOST=0.0.0.0 hace que Reverb escuche en TODAS las interfaces,
# incluida la pública. Como no hay TLS en ese puerto, cualquiera puede abrirlo
# en claro. Con nginx haciendo de proxy, Reverb solo necesita escuchar en
# localhost — y así el firewall deja de ser lo único que lo protege.
poner_env REVERB_SERVER_HOST 127.0.0.1
poner_env REVERB_SERVER_PORT "$PUERTO"

# Lo que ve el NAVEGADOR: el dominio público por 443 con TLS. Es distinto de
# donde escucha el servidor, y confundir los dos pares es el error clásico.
poner_env REVERB_HOST reinoaromas.tech
poner_env REVERB_PORT 443
poner_env REVERB_SCHEME https
poner_env BROADCAST_CONNECTION reverb

# Sin credenciales el handshake devuelve 401 y el navegador reintenta en bucle.
for var in REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET; do
    valor=$(grep -E "^[[:space:]]*$var=" "$ENV_FILE" | tail -1 | cut -d= -f2- || true)
    [[ -n ${valor//[[:space:]]/} ]] || fatal "$var está vacía. Generá credenciales con: sudo -u www-data $PHP artisan reverb:install"
done
ok "Credenciales presentes"

# ---------------------------------------------------------------------------
# 2. Servicio de systemd
# ---------------------------------------------------------------------------
log "Instalando $SERVICIO.service"

cat > "/etc/systemd/system/$SERVICIO.service" <<SERVICE
[Unit]
Description=Reino Aromas Reverb WebSocket server
After=network.target
# Sin nginx delante el puerto no es alcanzable desde el navegador, pero el
# servicio arranca igual: es Wants y no Requires para que un nginx caído no
# impida que Reverb esté listo cuando vuelva.
Wants=nginx.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=$APP_DIR
ExecStart=$PHP artisan reverb:start

# El proceso es de larga vida y mantiene una conexión por agente conectado.
# El límite por defecto (1024) se agota antes de lo que parece: cada cliente
# consume un descriptor y los de PHP se suman.
LimitNOFILE=10000

# reverb:start no termina nunca por diseño. Sin esto systemd mataría el
# proceso al recargar la unidad en medio de un deploy.
KillSignal=SIGTERM
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
SERVICE

ok "Unidad escrita (PHP: $PHP)"

systemctl daemon-reload

# ---------------------------------------------------------------------------
# 3. Proxy de nginx
# ---------------------------------------------------------------------------
log "Configurando el proxy de nginx"

[[ -f $NGINX_SITE ]] || fatal "No existe $NGINX_SITE."

if grep -q 'location ~ \^/(app|apps)/' "$NGINX_SITE"; then
    ok "El bloque del WebSocket ya está"
else
    cp -a "$NGINX_SITE" "$NGINX_SITE.bak.$marca"
    ok "Copia en $NGINX_SITE.bak.$marca"

    # El bloque va en el server de 443, no en el de 80.
    #
    # Certbot dejó dos bloques `server`: el de 80 solo redirige a https. Meter
    # el proxy ahí lo dejaría inalcanzable, porque el navegador solo pide wss://
    # por 443. Se busca la línea del `root` DENTRO del bloque con ssl_certificate.
    linea_ssl=$(grep -n 'ssl_certificate ' "$NGINX_SITE" | head -1 | cut -d: -f1 || true)
    [[ -n $linea_ssl ]] || fatal "No encontré ssl_certificate en $NGINX_SITE. ¿Corrió certbot? Sin TLS el WebSocket no puede funcionar."

    # Primer 'location /' después del ssl_certificate: ahí abre el bloque útil.
    linea_ins=$(awk -v desde="$linea_ssl" 'NR > desde && /^[[:space:]]*location \/ \{/ { print NR; exit }' "$NGINX_SITE")
    [[ -n $linea_ins ]] || fatal "No encontré 'location / {' en el bloque TLS de $NGINX_SITE. Insertá el proxy a mano (DEPLOY.md sección 7)."

    cat > /tmp/reverb-proxy.conf <<PROXY
    # WebSocket de Reverb (Laravel Echo / protocolo Pusher).
    #
    # Reverb sirve el handshake en /app/{key} y la API HTTP en /apps/{id}, de ahí
    # que el patrón cubra los dos. Va ANTES de 'location /' porque esa ruta
    # acabaría en try_files → index.php y Laravel devolvería un 404 — que es
    # exactamente el "Pusher error: 404" que se veía en cada mensaje.
    location ~ ^/(app|apps)/ {
        proxy_pass http://127.0.0.1:$PUERTO;
        proxy_http_version 1.1;

        # Los tres que convierten la petición en un WebSocket. Sin 'Upgrade'
        # nginx la trata como HTTP normal y la conexión se cierra al instante.
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;

        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;

        # Un WebSocket está callado entre mensajes. Con el timeout por defecto
        # (60s) nginx cortaría una conversación tranquila y el cliente
        # reconectaría cada minuto: el ping de Pusher va cada ~120s.
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;

        # El buffering rompe el tiempo real: nginx acumularía los frames en vez
        # de pasarlos en cuanto llegan.
        proxy_buffering off;
    }

PROXY

    # Inserta el bloque justo ANTES de la línea de 'location / {'.
    awk -v ins="$linea_ins" -v archivo=/tmp/reverb-proxy.conf '
        NR == ins { while ((getline linea < archivo) > 0) print linea }
        { print }
    ' "$NGINX_SITE" > /tmp/reinoaromas.nuevo

    mv /tmp/reinoaromas.nuevo "$NGINX_SITE"
    rm -f /tmp/reverb-proxy.conf

    ok "Proxy insertado antes de 'location /' (línea $linea_ins)"
fi

if ! nginx -t 2>/tmp/nginx-test.log; then
    cat /tmp/nginx-test.log >&2
    if [[ -f "$NGINX_SITE.bak.$marca" ]]; then
        cp -a "$NGINX_SITE.bak.$marca" "$NGINX_SITE"
        aviso "Configuración revertida desde la copia."
    fi
    fatal "nginx -t falló. No se recargó nada: el sitio sigue sirviendo con la configuración anterior."
fi
ok "nginx -t OK"

systemctl reload nginx
ok "nginx recargado"

# ---------------------------------------------------------------------------
# 4. Arrancar
# ---------------------------------------------------------------------------
log "Arrancando $SERVICIO"

# Reverb lee las credenciales de la config CACHEADA, no del .env. Sin esto se
# arranca con los valores viejos y el 404 sigue igual: el síntoma no cambia,
# que es lo que hace perder una tarde.
sudo -u www-data HOME=/tmp "$PHP" "$APP_DIR/artisan" config:clear >/dev/null
sudo -u www-data HOME=/tmp "$PHP" "$APP_DIR/artisan" config:cache >/dev/null
ok "Config recacheada"

systemctl enable "$SERVICIO" >/dev/null 2>&1
systemctl restart "$SERVICIO"

# El worker de colas es el que EMITE los broadcasts, y también tiene la config
# vieja en memoria. Sin reiniciarlo seguiría publicando contra el puerto anterior.
if systemctl list-unit-files | grep -q '^reino-queue.service'; then
    systemctl restart reino-queue
    ok "reino-queue reiniciado (tenía la config vieja en memoria)"
fi

# Un fallo de arranque tarda un instante en reflejarse en is-active.
for _ in 1 2 3 4 5 6 7 8 9 10; do
    systemctl is-active --quiet "$SERVICIO" && break
    sleep 1
done

if ! systemctl is-active --quiet "$SERVICIO"; then
    journalctl -u "$SERVICIO" -n 30 --no-pager >&2
    fatal "$SERVICIO no arrancó. El log está arriba."
fi
ok "$SERVICIO activo"

# ---------------------------------------------------------------------------
# 5. Verificar de punta a punta
# ---------------------------------------------------------------------------
log "Verificando"

ss -tlnp | grep -qE "127\.0\.0\.1:$PUERTO " \
    && ok "Escuchando en 127.0.0.1:$PUERTO (no expuesto al mundo)" \
    || aviso "No aparece escuchando en 127.0.0.1:$PUERTO — revisá REVERB_SERVER_HOST."

# El handshake sin credenciales debe dar 401, NO 404.
#
# Es la comprobación que importa: un 404 significa que nginx mandó la petición a
# Laravel en vez de a Reverb, que es el bug original. Un 401 prueba que Reverb
# recibió y contestó.
codigo=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 \
    'https://reinoaromas.tech/app/prueba-invalida?protocol=7&client=js&version=8.4.0' || echo 000)

case "$codigo" in
    401|400) ok "El proxy llega a Reverb (HTTP $codigo: rechaza la clave falsa, que es lo correcto)" ;;
    404)     aviso "HTTP 404: nginx sigue mandando /app/ a Laravel. El bloque del proxy no está haciendo efecto — revisá que quedara ANTES de 'location /'." ;;
    000)     aviso "Sin respuesta desde fuera. Puede ser el firewall (ufw allow 443/tcp) o DNS." ;;
    *)       aviso "HTTP $codigo, inesperado. Revisá: journalctl -u $SERVICIO -n 50" ;;
esac

log "Listo"
cat <<FIN
  Estado:   systemctl status $SERVICIO
  Logs:     journalctl -u $SERVICIO -f
  Reinicio: systemctl restart $SERVICIO

  Prueba real: abrí la bandeja en el navegador y mandá un mensaje al WhatsApp
  del negocio. Tiene que aparecer sin recargar, con la insignia "En vivo" en
  verde y el sonido de aviso.

  Si la insignia sigue gris, mirá la consola del navegador: un 401 en
  /broadcasting/auth es sesión o canal, no Reverb.
FIN
