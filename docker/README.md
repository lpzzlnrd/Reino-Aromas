# Deploy — Reino Aromas CRM

Infra de producción: Docker Compose en un VPS de Hostinger, con redeploy
automático en cada push a `main`.

## Arquitectura

```
                    Internet
                       │
                  :80 / :443
                       ▼
              ┌─────────────────┐
              │      caddy      │  TLS automático (Let's Encrypt)
              │  sirve public/  │  estáticos + fastcgi_pass
              └────────┬────────┘
                       │ :9000
                       ▼
              ┌─────────────────┐        ┌──────────────┐
              │       app       │───────▶│    redis     │ cache/colas/sesiones
              │    (php-fpm)    │        └──────────────┘
              └─────────────────┘               ▲
              ┌─────────────────┐               │
              │      queue      │───────────────┤ Jobs de Meta
              │  queue:work     │               │
              └─────────────────┘               │
              ┌─────────────────┐               │
              │    scheduler    │───────────────┘ meta:facebook:sync (5 min)
              │ schedule:work   │
              └─────────────────┘
                       │
                       ▼
              MySQL EXTERNO (gestionado)
```

`app`, `queue` y `scheduler` comparten la misma imagen; solo cambia el comando.

## Volúmenes

| Volumen         | Para qué                                                    |
|-----------------|-------------------------------------------------------------|
| `storage-data`  | `storage/` — logs, uploads, cachés de Laravel               |
| `public-data`   | `public/` — compartido con Caddy para servir estáticos      |
| `redis-data`    | AOF de Redis: los jobs en cola sobreviven un reinicio       |
| `caddy-data`    | **Certificados TLS.** Si se borra, Let's Encrypt re-emite y puede pegar rate limit |

## Puesta en marcha (una sola vez en el VPS)

```bash
# 1. Requisitos
curl -fsSL https://get.docker.com | sh

# 2. Clonar
sudo mkdir -p /srv && cd /srv
git clone https://github.com/lpzzlnrd/Reino-Aromas.git reino-aromas
cd reino-aromas

# 3. Configurar el entorno
cp .env.production.example .env
nano .env      # DB_HOST/USER/PASSWORD del MySQL externo, REDIS_PASSWORD, APP_DOMAIN

# 4. Generar APP_KEY y pegarla en .env
docker compose run --rm --no-deps app php artisan key:generate --show

# 5. Primer arranque
docker compose up -d --build
docker compose run --rm --no-deps app php artisan migrate --force

# 6. Verificar
docker compose ps
docker compose logs -f caddy    # debe mostrar el certificado emitido
```

Antes del paso 5: el DNS de `APP_DOMAIN` debe apuntar ya a la IP del VPS, o
Caddy no puede completar el desafío ACME. Y la IP del VPS tiene que estar
autorizada en el panel del MySQL gestionado.

## Deploy automático

`.github/workflows/deploy.yml` se dispara en cada push a `main`, entra por SSH y
corre `docker/deploy.sh`. Secrets a configurar en
**Settings → Secrets and variables → Actions**:

| Secret        | Ejemplo                     |
|---------------|-----------------------------|
| `VPS_HOST`    | `reinoaromas.com`           |
| `VPS_USER`    | `deploy`                    |
| `VPS_SSH_KEY` | contenido de la llave privada, con los `BEGIN`/`END` |
| `VPS_PORT`    | `22` (opcional)             |
| `VPS_APP_DIR` | `/srv/reino-aromas`         |

Llave SSH para el deploy (desde tu máquina):

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/reino_deploy
ssh-copy-id -i ~/.ssh/reino_deploy.pub deploy@TU_IP
cat ~/.ssh/reino_deploy          # esto va en el secret VPS_SSH_KEY
```

El usuario `deploy` necesita estar en el grupo `docker`:
`sudo usermod -aG docker deploy`

### Qué hace `deploy.sh`

1. `git reset --hard origin/main` (checkout limpio, sin conflictos locales)
2. `docker compose build app`
3. `php artisan down` — modo mantenimiento
4. Migraciones con la imagen **nueva**, antes de levantar los servicios
5. `up -d --force-recreate` — obligatorio: `opcache.validate_timestamps=0`
   hace que un contenedor vivo siga sirviendo el bytecode viejo
6. Copia `public/` al volumen que sirve Caddy y borra los hashes huérfanos
7. `config:cache`, `route:cache`, `view:cache`, `event:cache`, `queue:restart`
8. `php artisan up` + `docker image prune`

Si algo falla a mitad, el `trap` saca el sitio de mantenimiento.

Deploy manual: `cd /srv/reino-aromas && ./docker/deploy.sh`

## Operación diaria

```bash
docker compose logs -f app queue          # logs en vivo
docker compose exec app php artisan tinker
docker compose restart queue              # reiniciar workers
docker compose exec app php artisan queue:failed
```

## Notas

- **`APP_DEBUG=false` siempre.** Con `true` se filtra el `.env` entero en la
  página de error.
- **Backups de MySQL**: los gestiona el proveedor del MySQL externo. Verifica
  que estén activos; este compose no los cubre.
- **Webhooks de Meta**: apuntan a `https://APP_DOMAIN/...`. Requieren TLS
  válido, que Caddy ya provee.
- **Redis usa `maxmemory-policy noeviction`** a propósito: con `allkeys-lru`
  Redis podría descartar jobs de la cola bajo presión de memoria.
- **Broadcasting está en `null`**: nada en el código usa Echo/Soketi todavía.
  Cuando se implemente, hay que añadir el servicio `soketi` al compose y poner
  `BROADCAST_CONNECTION=pusher`.
