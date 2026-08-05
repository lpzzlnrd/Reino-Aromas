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

### 7. Permisos para el deploy automático

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
```

## Notas

- **`APP_DEBUG=false` siempre** en producción.
- **Backups**: `mysqldump` por cron. No están cubiertos por este setup.
- **Webhooks de Meta**: apuntan a `https://reinoaromas.tech/...` y exigen TLS
  válido, que certbot ya provee.
- El deploy corre `npm ci && npm run build` en el VPS. Con 4GB de RAM va bien;
  si en el futuro el build empieza a morir por OOM, hay que moverlo a Actions
  y subir `public/build` por rsync.
