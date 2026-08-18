#!/usr/bin/env bash
#
# Restaura la base del CRM desde un backup.
#
# Por qué existe: un backup que nunca se restauró no es un backup, es una
# esperanza. El día que haga falta, nadie quiere improvisar comandos con la
# base caída y el cliente esperando.
#
# Uso:
#   restore-mysql --list                      # ver backups disponibles
#   restore-mysql /var/backups/reinoaromas/daily/reinoaromas_2026-08-18_0315.sql.gz
#   restore-mysql --dry-run <archivo>         # comprueba el archivo sin tocar la base
#
# ESTE SCRIPT SOBRESCRIBE LA BASE. Pide confirmación escrita antes de hacerlo.
#
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/reinoaromas}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/reinoaromas}"

log() { echo "[$(date '+%H:%M:%S')] $*"; }
fallo() { echo "ERROR: $*" >&2; exit 1; }

env_val() {
    grep -E "^$1=" "${APP_DIR}/.env" 2>/dev/null \
        | tail -1 | cut -d= -f2- \
        | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//" -e 's/\r$//'
}

# ---------------------------------------------------------------------------
# --list: qué hay disponible
# ---------------------------------------------------------------------------
if [[ "${1:-}" == "--list" ]]; then
    echo "Backups en ${BACKUP_DIR}:"
    echo ""
    for dir in daily weekly; do
        echo "  ${dir}:"
        if compgen -G "${BACKUP_DIR}/${dir}/*.sql.gz" > /dev/null; then
            # -h para tamaños legibles; ordenado por nombre = por fecha.
            ls -lh "${BACKUP_DIR}/${dir}"/*.sql.gz | awk '{print "    " $9 "  (" $5 ")"}'
        else
            echo "    (vacío)"
        fi
    done
    exit 0
fi

DRY_RUN=0
if [[ "${1:-}" == "--dry-run" ]]; then
    DRY_RUN=1
    shift
fi

ARCHIVO="${1:-}"
[[ -n "${ARCHIVO}" ]] || fallo "Falta el archivo. Usá --list para ver los disponibles."
[[ -f "${ARCHIVO}" ]] || fallo "No existe: ${ARCHIVO}"

# ---------------------------------------------------------------------------
# Comprobar el archivo ANTES de tocar la base
#
# Restaurar un dump corrupto sobre una base viva es la peor combinación
# posible: se pierde lo que había y no se recupera lo que se quería.
# ---------------------------------------------------------------------------
log "Comprobando ${ARCHIVO}..."

gzip -t "${ARCHIVO}" 2>/dev/null || fallo "El archivo está corrupto (gzip -t falló)."

if ! zcat "${ARCHIVO}" | grep -qE "CREATE TABLE .(messages|contacts)."; then
    fallo "El dump no contiene las tablas del CRM. ¿Es el archivo correcto?"
fi

TABLAS=$(zcat "${ARCHIVO}" | grep -c "^CREATE TABLE" || true)
log "Archivo válido: ${TABLAS} tablas."

if [[ "${DRY_RUN}" -eq 1 ]]; then
    log "Simulación: no se tocó la base. Quitá --dry-run para restaurar."
    exit 0
fi

DB_NAME="$(env_val DB_DATABASE)"
DB_USER="$(env_val DB_USERNAME)"
DB_PASS="$(env_val DB_PASSWORD)"
DB_HOST="$(env_val DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_val DB_PORT)"; DB_PORT="${DB_PORT:-3306}"

[[ -n "${DB_NAME}" ]] || fallo "DB_DATABASE está vacío en el .env"

# ---------------------------------------------------------------------------
# Confirmación
# ---------------------------------------------------------------------------
echo ""
echo "  Vas a SOBRESCRIBIR la base '${DB_NAME}' en ${DB_HOST}:${DB_PORT}"
echo "  con el contenido de:"
echo "    ${ARCHIVO}"
echo ""
echo "  Todo lo que haya en la base ahora se PIERDE."
echo ""
read -r -p "  Escribí el nombre de la base para confirmar: " CONFIRMACION

[[ "${CONFIRMACION}" == "${DB_NAME}" ]] || fallo "Cancelado (no coincide)."

export MYSQL_PWD="${DB_PASS}"

# ---------------------------------------------------------------------------
# Red de seguridad: un dump del estado ACTUAL antes de sobrescribirlo
#
# Si el archivo elegido resulta ser el equivocado, esto es lo único que permite
# volver atrás.
# ---------------------------------------------------------------------------
PREVIO="/tmp/pre-restore_${DB_NAME}_$(date '+%Y-%m-%d_%H%M').sql.gz"
log "Guardando el estado actual en ${PREVIO} por si hay que volver..."

if mysqldump --host="${DB_HOST}" --port="${DB_PORT}" --user="${DB_USER}" \
        --single-transaction --quick --routines --triggers \
        "${DB_NAME}" 2>/dev/null | gzip > "${PREVIO}"; then
    log "Copia previa guardada."
else
    log "AVISO: no se pudo guardar la copia previa (¿la base no existe todavía?)."
    rm -f "${PREVIO}"
fi

# ---------------------------------------------------------------------------
# La app en mantenimiento mientras se restaura
#
# Sin esto, un agente escribiendo durante la restauración generaría filas que el
# dump va a sobrescribir a medias.
# ---------------------------------------------------------------------------
if [[ -f "${APP_DIR}/artisan" ]]; then
    log "Poniendo la app en mantenimiento..."
    (cd "${APP_DIR}" && sudo -u www-data /usr/bin/php8.4 artisan down) || \
        log "AVISO: no se pudo activar el modo mantenimiento; se continúa."
fi

log "Restaurando..."

if zcat "${ARCHIVO}" | mysql --host="${DB_HOST}" --port="${DB_PORT}" \
        --user="${DB_USER}" "${DB_NAME}"; then
    log "Restauración completada."
else
    log "LA RESTAURACIÓN FALLÓ."
    [[ -f "${PREVIO}" ]] && log "Para volver atrás: $0 ${PREVIO}"
fi

unset MYSQL_PWD

# El modo mantenimiento se levanta SIEMPRE, incluso si la restauración falló:
# dejar el sitio caído sin avisar es peor que mostrar una base a medias.
if [[ -f "${APP_DIR}/artisan" ]]; then
    log "Levantando la app..."
    (cd "${APP_DIR}" && sudo -u www-data /usr/bin/php8.4 artisan up) || \
        log "AVISO: la app quedó en mantenimiento. Corré 'artisan up' a mano."

    # El caché de configuración puede tener valores viejos si el .env cambió.
    (cd "${APP_DIR}" && sudo -u www-data /usr/bin/php8.4 artisan config:clear) || true
fi

log "Listo. Comprobá el sitio y revisá que los datos estén completos."
[[ -f "${PREVIO}" ]] && log "La copia previa sigue en ${PREVIO} (borrala cuando confirmes)."
