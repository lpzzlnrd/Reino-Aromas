#!/bin/sh
# =============================================================================
# entrypoint.sh — Reino Aromas CRM
#
# El volumen storage-data se monta sobre /var/www/html/storage. Un volumen
# nombrado nuevo arranca VACÍO, y Laravel explota si no existen
# storage/framework/{views,cache,sessions} — "Please provide a valid cache path".
#
# Este script recrea la estructura y los permisos antes de arrancar el proceso
# real (php-fpm, queue:work o schedule:work). Es idempotente.
# =============================================================================
set -e

STORAGE=/var/www/html/storage

mkdir -p \
	"$STORAGE/app/private" \
	"$STORAGE/app/public" \
	"$STORAGE/framework/cache/data" \
	"$STORAGE/framework/sessions" \
	"$STORAGE/framework/testing" \
	"$STORAGE/framework/views" \
	"$STORAGE/logs"

chown -R www-data:www-data "$STORAGE" /var/www/html/bootstrap/cache
chmod -R 775 "$STORAGE" /var/www/html/bootstrap/cache

exec "$@"
