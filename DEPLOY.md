# Deploy — Reino Aromas CRM

Deploy directo sobre el VPS de Hostinger (sin Docker), con redeploy automático
en cada push a `main` vía GitHub Actions.

## Cómo funciona

```
push a main
    │
    ▼
GitHub Actions  ──ssh──▶  VPS
                            git reset --hard origin/main
                            composer install --no-dev
                            npm ci && npm run build
                            php artisan migrate --force
                            cachés + queue:restart
                            systemctl reload php8.4-fpm nginx
    │
    ▼
smoke test: https://reinoaromas.tech/
```

El deploy activa modo mantenimiento antes de migrar y lo desactiva al final.
Si algo falla a mitad, un `trap` saca el sitio de mantenimiento.

## Preparar el VPS (una sola vez)

### 1. Paquetes

```bash
sudo apt update
sudo apt install -y nginx mysql-server git unzip \
  php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node 22 (el build de Vite lo necesita)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

PHP 8.4 es obligatorio: `composer.json` pide `^8.4` y las dependencias de
Symfony 8 abortan con 8.3.

### 2. Clonar y configurar

```bash
sudo mkdir -p /var/www/reinoaromas
sudo chown -R $USER:$USER /var/www/reinoaromas
git clone https://github.com/lpzzlnrd/Reino-Aromas.git /var/www/reinoaromas
cd /var/www/reinoaromas

cp .env.example .env
nano .env      # ver sección siguiente

composer install --optimize-autoloader --no-dev
npm ci && npm run build
php artisan key:generate
php artisan migrate --force
php artisan storage:link

sudo chown -R www-data:www-data /var/www/reinoaromas
sudo chmod -R 775 storage bootstrap/cache
```

### 3. El `.env` de producción

Valores que **tienen** que cambiar respecto al `.env.example`:

```ini
APP_ENV=production
APP_DEBUG=false          # con true se filtra el .env entero en los errores
APP_URL=https://reinoaromas.tech
APP_KEY=                 # php artisan key:generate

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=reino_aromas
DB_USERNAME=
DB_PASSWORD=

SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=.reinoaromas.tech
SANCTUM_STATEFUL_DOMAINS=reinoaromas.tech,www.reinoaromas.tech

LOG_LEVEL=warning

# Meta / WhatsApp & Instagram
META_APP_SECRET=
META_ACCESS_TOKEN=
META_WEBHOOK_VERIFY_TOKEN=
META_INSTAGRAM_ACCOUNT_ID=
META_WHATSAPP_PHONE_NUMBER_ID=
```

### 4. Nginx

`/etc/nginx/sites-available/reinoaromas`:

```nginx
server {
    listen 80;
    server_name reinoaromas.tech www.reinoaromas.tech;
    root /var/www/reinoaromas/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Assets de Vite: llevan hash, se pueden cachear para siempre.
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    location ~ /\.(?!well-known).* { deny all; }

    client_max_body_size 20M;
}
```

```bash
sudo ln -s /etc/nginx/sites-available/reinoaromas /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 5. HTTPS

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d reinoaromas.tech -d www.reinoaromas.tech
```

El DNS debe apuntar al VPS **antes** de correr certbot. La renovación queda
automática por el timer de systemd.

### 6. Colas y scheduler

El CRM tiene 6 Jobs de Meta y un `meta:facebook:sync` cada 5 minutos. Sin esto,
los mensajes de WhatsApp/Instagram no se procesan.

Worker de colas — `/etc/systemd/system/reino-queue.service`:

```ini
[Unit]
Description=Reino Aromas queue worker
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/reinoaromas
ExecStart=/usr/bin/php artisan queue:work --tries=3 --backoff=10 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now reino-queue
```

Scheduler — `sudo crontab -u www-data -e`:

```cron
* * * * * cd /var/www/reinoaromas && php artisan schedule:run >> /dev/null 2>&1
```

### 7. Reverb (WebSockets / tiempo real)

Sin esto la bandeja no se actualiza sola, no suena el aviso de mensaje nuevo, y
cada broadcast falla con **`Pusher error: 404`** en `storage/logs/laravel.log`.
Los mensajes SÍ se guardan en la base: lo único que se pierde es el aviso en
vivo. Ese 404 es Laravel respondiendo a Laravel — nginx manda `/apps/...` a
`index.php`, que no tiene esa ruta.

Todo lo de esta sección lo hace el script:

```bash
sudo bash /var/www/reinoaromas/scripts/setup-reverb.sh
```

Es idempotente y hace copia de `.env` y del site de nginx antes de tocarlos. Si
`nginx -t` falla, revierte y no recarga nada.

#### Los dos pares de variables NO son lo mismo

El error más fácil de cometer. Son cuatro variables que parecen redundantes:

| Variable             | Quién la usa    | Valor en el VPS    |
|----------------------|-----------------|--------------------|
| `REVERB_SERVER_HOST` | El **servidor** | `127.0.0.1`        |
| `REVERB_SERVER_PORT` | El **servidor** | `6001`             |
| `REVERB_HOST`        | El **navegador**| `reinoaromas.tech` |
| `REVERB_PORT`        | El **navegador**| `443`              |

Las `SERVER_*` dicen dónde escucha el proceso de PHP; las otras dos dicen a
dónde se conecta el JavaScript, y van al bundle vía `VITE_REVERB_*`. Entre las
dos está nginx.

`REVERB_SERVER_HOST` **tiene que ser `127.0.0.1`**, no `0.0.0.0`: con `0.0.0.0`
el puerto queda escuchando en la interfaz pública **sin TLS**, y lo único que lo
tapa es el firewall.

**El puerto es 6001 y no el 8080 por defecto de Reverb**: el 8080 lo ocupa
phpMyAdmin en `127.0.0.1:8080`. Comprobar siempre antes de cambiarlo:

```bash
ss -tlnp | grep 6001
```

#### El proxy de nginx no es opcional

Abrir el puerto con `ufw allow 6001` no funciona: el navegador bloquea un
`ws://` iniciado desde una página `https://` (mixed content), y en ese puerto no
hay certificado. La única vía es que nginx reciba en 443, con el certificado que
certbot ya instaló, y pase la conexión al puerto interno. **Así no hay que
tocar el firewall en absoluto.**

El bloque va **antes** de `location /` y **dentro** del `server` de 443:

```nginx
location ~ ^/(app|apps)/ {
    proxy_pass http://127.0.0.1:6001;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;
    proxy_buffering off;
}
```

Detrás de `location /` no serviría: esa ruta acaba en `try_files → index.php` y
gana la primera coincidencia. El patrón cubre `/app/` (el handshake del
WebSocket) y `/apps/` (la API HTTP por donde PHP publica los eventos).

`proxy_read_timeout 3600s` tampoco es adorno: un WebSocket está callado entre
mensajes y el ping de Pusher va cada ~120 s, así que con el timeout por defecto
de 60 s nginx cortaría toda conversación tranquila y el cliente reconectaría en
bucle.

#### Comprobar que funciona

```bash
systemctl status reino-reverb
ss -tlnp | grep 6001

# La prueba que importa. Debe dar 401 (o 400), NUNCA 404.
curl -s -o /dev/null -w '%{http_code}\n' \
  'https://reinoaromas.tech/app/clave-falsa?protocol=7&client=js&version=8.4.0'
```

- **401 / 400** → el proxy llega a Reverb, que rechaza la clave falsa. Correcto.
- **404** → nginx sigue mandando `/app/` a Laravel: el bloque del proxy está mal
  puesto o quedó después de `location /`.
- **000** → no responde desde fuera. Mirar `ufw status` (ver la trampa del
  firewall en las notas) y el DNS.

Después: abrir la bandeja y mandar un mensaje real al WhatsApp del negocio.
Tiene que aparecer sin recargar, con la insignia **"En vivo"** en verde y el
sonido de aviso.

Si la insignia sigue gris con el `curl` dando 401, el problema no es Reverb:
mirar la consola del navegador. Un 401 en `/broadcasting/auth` es la sesión o el
canal privado, no el WebSocket.

#### Después de cada deploy

`reverb:start` es un proceso de larga vida y se queda con el código y la config
cacheada de antes. **No tiene una señal de reinicio propia** como
`queue:restart`, así que hay que reiniciar la unidad **a mano**:

```bash
sudo systemctl restart reino-reverb
```

**El workflow de deploy NO lo hace, a propósito.** Se intentó el 2026-08-27 y
tumbó el deploy dos veces seguidas (PR #24 y #25). El tiempo real es opcional —
sin él los mensajes se siguen guardando y la bandeja muestra el botón
"Actualizar" — así que no puede estar en el camino crítico del despliegue.

En la práctica solo hace falta reiniciarlo cuando cambian los eventos de
`app/Events/` o la config de Reverb, no en cada deploy.

Lo mismo vale al cambiar credenciales: Reverb las lee de la **config cacheada**,
no del `.env`. Sin `config:cache` arranca con los valores viejos y el síntoma no
cambia en nada.

```bash
sudo -u www-data HOME=/tmp php8.4 artisan config:cache
sudo systemctl restart reino-reverb reino-queue
```

El worker de colas entra ahí porque es **él** quien publica los broadcasts: con
la config vieja en memoria seguiría apuntando al puerto anterior.

### 8. Permisos para el deploy automático

El workflow corre `chown`, `chmod` y `systemctl reload`. Si `VPS_USER` es
`root`, ya funciona. Si es un usuario de deploy, dale sudo sin contraseña
solo para lo necesario — `sudo visudo -f /etc/sudoers.d/deploy`:

```
deploy ALL=(ALL) NOPASSWD: /bin/chown, /bin/chmod, /bin/systemctl reload php8.4-fpm, /bin/systemctl reload nginx
```

El usuario también necesita permiso de escritura en `/var/www/reinoaromas`.

## Secrets de GitHub

En **Settings → Secrets and variables → Actions**:

| Secret        | Ejemplo                  |
|---------------|--------------------------|
| `VPS_HOST`    | IP del VPS o `reinoaromas.tech` |
| `VPS_USER`    | `root` o `deploy`        |
| `VPS_SSH_KEY` | llave privada completa, con los `BEGIN`/`END` |
| `VPS_PORT`    | `22` (opcional)          |
| `VPS_APP_DIR` | `/var/www/reinoaromas`   |

Generar la llave (en tu máquina):

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/reino_deploy
# sin passphrase: Actions no puede escribirla
ssh-copy-id -i ~/.ssh/reino_deploy.pub USUARIO@TU_IP
cat ~/.ssh/reino_deploy          # esto va en VPS_SSH_KEY
```

## Operación diaria

```bash
tail -f storage/logs/laravel.log       # logs de la app
sudo journalctl -u reino-queue -f      # logs del worker
sudo systemctl restart reino-queue     # reiniciar worker
php artisan queue:failed               # jobs fallidos
php artisan down / up                  # mantenimiento manual

sudo journalctl -u reino-reverb -f     # logs del WebSocket
sudo systemctl restart reino-reverb    # reiniciar WebSocket
```

**`Pusher error: 404` en el log de la app** = Reverb caído o el proxy mal
puesto. Los mensajes se siguen guardando; lo que no llega es el aviso en vivo.
Ver la sección 7.

## Notas

- **`APP_DEBUG=false` siempre** en producción.
- **Backups**: `mysqldump` por cron. No están cubiertos por este setup.
- **Webhooks de Meta**: apuntan a `https://reinoaromas.tech/...` y exigen TLS
  válido, que certbot ya provee.
- El deploy corre `npm ci && npm run build` en el VPS. Con 4GB de RAM va bien;
  si en el futuro el build empieza a morir por OOM, hay que moverlo a Actions
  y subir `public/build` por rsync.
