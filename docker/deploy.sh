#!/usr/bin/env bash
# =============================================================================
# deploy.sh — Reino Aromas CRM
#
# Corre EN EL VPS. Lo invoca GitHub Actions por SSH en cada push a main,
# y también sirve para deploy manual:  ./docker/deploy.sh
#
# Pasos: pull -> build -> migrate -> recrear contenedores -> cachés -> limpiar
# =============================================================================
set -Eeuo pipefail

# Directorio del repo en el VPS (el padre de docker/).
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

COMPOSE="docker compose"

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }

# Si algo falla a mitad de camino, salir de mantenimiento para no dejar el
# sitio con el 503 puesto indefinidamente.
cleanup_on_error() {
	log "Deploy FALLÓ — saliendo de modo mantenimiento"
	$COMPOSE exec -T app php artisan up || true
}
trap cleanup_on_error ERR

# -----------------------------------------------------------------------------
# 1. Traer el código
# -----------------------------------------------------------------------------
log "Actualizando código desde origin/main"
git fetch --prune origin main
# reset --hard en vez de pull: el VPS es un checkout de solo lectura, no queremos
# que un conflicto local bloquee el deploy.
git reset --hard origin/main
COMMIT="$(git rev-parse --short HEAD)"
echo "Commit desplegado: $COMMIT"

# -----------------------------------------------------------------------------
# 2. Verificar que existe el .env de producción
# -----------------------------------------------------------------------------
if [[ ! -f .env ]]; then
	echo "ERROR: no existe .env en $APP_DIR. Créalo desde .env.example antes del primer deploy." >&2
	exit 1
fi

# -----------------------------------------------------------------------------
# 3. Construir la imagen nueva
# -----------------------------------------------------------------------------
log "Construyendo imagen"
$COMPOSE build --pull app

# -----------------------------------------------------------------------------
# 4. Modo mantenimiento (si la app ya está corriendo)
# -----------------------------------------------------------------------------
if $COMPOSE ps --status running --services 2>/dev/null | grep -qx app; then
	log "Activando modo mantenimiento"
	# Sin --render: el proyecto no tiene resources/views/errors/503.blade.php,
	# así que se usa la vista 503 por defecto de Laravel.
	$COMPOSE exec -T app php artisan down --retry=60 || true
fi

# -----------------------------------------------------------------------------
# 5. Migraciones
# -----------------------------------------------------------------------------
# Se corren con la imagen NUEVA en un contenedor efímero, antes de levantar
# los servicios: así el código nuevo nunca ve un esquema viejo.
log "Ejecutando migraciones"
$COMPOSE run --rm --no-deps app php artisan migrate --force

# -----------------------------------------------------------------------------
# 6. Recrear los contenedores
# -----------------------------------------------------------------------------
# --force-recreate es obligatorio: php-prod.ini tiene opcache.validate_timestamps=0,
# así que un contenedor vivo seguiría sirviendo el bytecode del commit anterior.
log "Recreando contenedores"
$COMPOSE up -d --force-recreate --remove-orphans app queue scheduler
$COMPOSE up -d redis caddy

# -----------------------------------------------------------------------------
# 7. Sincronizar public/ al volumen que sirve Caddy
# -----------------------------------------------------------------------------
# El volumen nombrado public-data solo se auto-inicializa cuando está vacío;
# en deploys posteriores conservaría los assets del build anterior. Copiamos
# desde la imagen y borramos los hashes huérfanos de builds viejos.
log "Sincronizando public/ (assets de Vite)"
# Se monta el volumen en /target de un contenedor temporal creado desde la
# imagen nueva. Ahí /var/www/html/public sí es el contenido de la imagen
# (no el volumen), así que se puede copiar encima.
PUBLIC_VOL="$(docker volume ls -q --filter "name=public-data" | head -1)"
if [[ -z "$PUBLIC_VOL" ]]; then
	echo "ERROR: no encontré el volumen public-data." >&2
	exit 1
fi
docker run --rm \
	--entrypoint sh \
	-v "${PUBLIC_VOL}:/target" \
	reino-aromas:latest \
	-c 'rm -rf /target/build && cp -R /var/www/html/public/. /target/ && chown -R www-data:www-data /target'

# -----------------------------------------------------------------------------
# 8. Recachear configuración
# -----------------------------------------------------------------------------
log "Regenerando cachés de Laravel"
$COMPOSE exec -T app php artisan config:cache
$COMPOSE exec -T app php artisan route:cache
$COMPOSE exec -T app php artisan view:cache
$COMPOSE exec -T app php artisan event:cache
# storage/app/public -> public/storage, por si el volumen es nuevo.
$COMPOSE exec -T app php artisan storage:link --force || true
# Los workers siguen con el código viejo en memoria hasta que reciben la señal.
$COMPOSE exec -T app php artisan queue:restart

# -----------------------------------------------------------------------------
# 9. Salir de mantenimiento
# -----------------------------------------------------------------------------
log "Desactivando modo mantenimiento"
$COMPOSE exec -T app php artisan up
trap - ERR

# -----------------------------------------------------------------------------
# 10. Limpiar imágenes viejas
# -----------------------------------------------------------------------------
log "Limpiando imágenes sin usar"
docker image prune -f

log "Deploy OK — commit $COMMIT"
$COMPOSE ps
