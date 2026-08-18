#!/usr/bin/env bash
#
# Backup de la base de datos del CRM.
#
# Por qué existe: hasta el 2026-08-18 no había NINGUNA copia de la base. Si el
# VPS se perdía, se iban los contactos, las conversaciones, los tickets y todo el
# historial de mensajes.
#
# Instalación en el VPS (ver VPS.md):
#   cp scripts/backup-mysql.sh /usr/local/bin/backup-mysql
#   chmod +x /usr/local/bin/backup-mysql
#   crontab -e  ->  15 3 * * * /usr/local/bin/backup-mysql >> /var/log/backup-mysql.log 2>&1
#
# Uso manual:
#   backup-mysql              # backup normal
#   backup-mysql --verify     # además comprueba que el dump se puede leer
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Configuración
#
# Las credenciales se leen del .env de Laravel en vez de repetirlas aquí: así
# rotar la contraseña de MySQL no obliga a editar dos sitios (y a olvidarse de
# uno).
# ---------------------------------------------------------------------------
APP_DIR="${APP_DIR:-/var/www/reinoaromas}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/reinoaromas}"

# Cuántos conservar. Los diarios cubren la última semana día por día; los
# semanales, un mes hacia atrás. Un problema que tarda más de un mes en
# detectarse ya no se resuelve con un backup.
KEEP_DAILY="${KEEP_DAILY:-7}"
KEEP_WEEKLY="${KEEP_WEEKLY:-4}"

# ---------------------------------------------------------------------------
# Subida remota (OPCIONAL, desactivada por defecto)
#
# IMPORTANTE: un backup que vive en el mismo servidor NO es un backup. Cubre un
# DROP TABLE accidental, pero no que el disco muera o se pierda la instancia.
#
# Para activarlo: instalar rclone, configurar un remoto y poner su nombre aquí.
#   apt install rclone && rclone config
#   RCLONE_REMOTE="backblaze:reinoaromas-backups"
# ---------------------------------------------------------------------------
RCLONE_REMOTE="${RCLONE_REMOTE:-}"

VERIFY=0
[[ "${1:-}" == "--verify" ]] && VERIFY=1

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }
fallo() { log "ERROR: $*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Leer credenciales del .env
#
# No se hace `source .env`: ese archivo tiene valores con espacios, comillas y
# almohadillas (la clave RSA de Flows, por ejemplo) que romperían el shell o
# ejecutarían cosas por accidente. Se extrae clave por clave con grep.
# ---------------------------------------------------------------------------
env_val() {
    local clave="$1"
    # Última aparición gana, igual que hace dotenv. Se limpian comillas y el
    # \r que dejan los editores de Windows.
    grep -E "^${clave}=" "${APP_DIR}/.env" 2>/dev/null \
        | tail -1 \
        | cut -d= -f2- \
        | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//" -e 's/\r$//'
}

[[ -f "${APP_DIR}/.env" ]] || fallo "No se encontró ${APP_DIR}/.env"

DB_NAME="$(env_val DB_DATABASE)"
DB_USER="$(env_val DB_USERNAME)"
DB_PASS="$(env_val DB_PASSWORD)"
DB_HOST="$(env_val DB_HOST)"
DB_PORT="$(env_val DB_PORT)"

[[ -n "${DB_NAME}" ]] || fallo "DB_DATABASE está vacío en el .env"
[[ -n "${DB_USER}" ]] || fallo "DB_USERNAME está vacío en el .env"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

# ---------------------------------------------------------------------------
# Preparar destino
# ---------------------------------------------------------------------------
mkdir -p "${BACKUP_DIR}/daily" "${BACKUP_DIR}/weekly"

# Solo root lee los backups: un dump contiene TODOS los datos de los clientes,
# incluidos sus teléfonos y conversaciones.
chmod 700 "${BACKUP_DIR}"

FECHA="$(date '+%Y-%m-%d_%H%M')"
DESTINO="${BACKUP_DIR}/daily/${DB_NAME}_${FECHA}.sql.gz"

log "Backup de '${DB_NAME}' -> ${DESTINO}"

# ---------------------------------------------------------------------------
# El dump
#
# --single-transaction hace el volcado dentro de una transacción, así que NO
# bloquea las tablas: el CRM sigue atendiendo mientras corre. Sin esa opción
# MySQL bloquea y la app se congela unos segundos.
#
# --routines y --triggers porque un dump sin ellos restaura una base que
# parece completa y no lo está.
#
# La contraseña va por MYSQL_PWD y no por -p: los argumentos de un proceso son
# visibles con `ps` para cualquier usuario del sistema.
# ---------------------------------------------------------------------------
export MYSQL_PWD="${DB_PASS}"

# pipefail está activo, así que si mysqldump falla el gzip no oculta el error.
if ! mysqldump \
        --host="${DB_HOST}" \
        --port="${DB_PORT}" \
        --user="${DB_USER}" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --default-character-set=utf8mb4 \
        "${DB_NAME}" 2>/tmp/backup-mysql.err | gzip -9 > "${DESTINO}"; then
    rm -f "${DESTINO}"
    fallo "mysqldump falló: $(tail -3 /tmp/backup-mysql.err)"
fi

unset MYSQL_PWD

# ---------------------------------------------------------------------------
# Comprobar que el dump SIRVE
#
# Un cron que produce archivos de 20 bytes durante tres meses es peor que no
# tener backup: creés que estás cubierto. Estas dos comprobaciones detectan el
# caso.
# ---------------------------------------------------------------------------
TAMANO=$(stat -c%s "${DESTINO}")

# Un .sql.gz de una base real no baja de unos pocos KB. Por debajo de 1KB es un
# dump vacío o truncado.
if [[ "${TAMANO}" -lt 1024 ]]; then
    rm -f "${DESTINO}"
    fallo "El dump pesa ${TAMANO} bytes: está vacío o truncado."
fi

# gzip -t detecta un archivo corrupto o a medio escribir.
gzip -t "${DESTINO}" 2>/dev/null || fallo "El archivo comprimido está corrupto."

if [[ "${VERIFY}" -eq 1 ]]; then
    # Comprobación fuerte: que el SQL se pueda descomprimir y contenga la
    # estructura esperada. No restaura nada, solo lee.
    if ! zcat "${DESTINO}" | grep -qE "CREATE TABLE .(messages|contacts)."; then
        fallo "El dump no contiene las tablas esperadas."
    fi
    log "Verificación: el dump contiene las tablas del CRM."
fi

log "Dump correcto ($(numfmt --to=iec "${TAMANO}" 2>/dev/null || echo "${TAMANO} bytes"))"

# ---------------------------------------------------------------------------
# Copia semanal
#
# Los domingos se guarda una copia aparte, que sobrevive a la rotación diaria.
# Sin esto solo hay una semana de historia.
# ---------------------------------------------------------------------------
if [[ "$(date '+%u')" == "7" ]]; then
    cp "${DESTINO}" "${BACKUP_DIR}/weekly/"
    log "Copia semanal guardada."
fi

# ---------------------------------------------------------------------------
# Rotación
#
# Un cron que solo acumula llena el disco, y un disco al 100% mata los demonios
# (nginx, php-fpm, MySQL) — un fallo mucho peor que el que se quería prevenir.
# ---------------------------------------------------------------------------
rotar() {
    local dir="$1" conservar="$2"
    local total
    total=$(find "${dir}" -maxdepth 1 -name '*.sql.gz' | wc -l)

    if [[ "${total}" -gt "${conservar}" ]]; then
        # Se ordenan por nombre, que empieza por fecha ISO: el orden
        # alfabético ES el cronológico. No se usa `ls -t` porque un `cp`
        # cambia la mtime y desordenaría los semanales.
        find "${dir}" -maxdepth 1 -name '*.sql.gz' \
            | sort \
            | head -n -"${conservar}" \
            | while read -r viejo; do
                rm -f "${viejo}"
                log "Rotado: $(basename "${viejo}")"
            done
    fi
}

rotar "${BACKUP_DIR}/daily" "${KEEP_DAILY}"
rotar "${BACKUP_DIR}/weekly" "${KEEP_WEEKLY}"

# ---------------------------------------------------------------------------
# Subida remota
# ---------------------------------------------------------------------------
if [[ -n "${RCLONE_REMOTE}" ]]; then
    if command -v rclone >/dev/null 2>&1; then
        log "Subiendo a ${RCLONE_REMOTE}..."
        if rclone copy "${DESTINO}" "${RCLONE_REMOTE}/daily/" --no-traverse; then
            log "Subida completada."
        else
            # NO se aborta: el backup local ya existe y es válido. Perder la
            # copia remota es malo, pero peor sería que el script terminara con
            # error y pareciera que no hay backup ninguno.
            log "AVISO: la subida remota falló. El backup local SÍ se creó."
        fi
    else
        log "AVISO: RCLONE_REMOTE está configurado pero rclone no está instalado."
    fi
else
    log "AVISO: sin destino remoto. El backup vive SOLO en este servidor."
    log "       Si se pierde el VPS, se pierde el backup. Ver VPS.md seccion 8."
fi

log "Listo. Backups en ${BACKUP_DIR}:"
log "  diarios:  $(find "${BACKUP_DIR}/daily" -name '*.sql.gz' | wc -l)"
log "  semanales: $(find "${BACKUP_DIR}/weekly" -name '*.sql.gz' | wc -l)"
