#!/bin/sh
set -e

# Instala dependencias PHP si vendor/ está vacío (primera vez o volumen limpio)
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "[entrypoint] Instalando dependencias Composer..."
    composer install --no-interaction --no-progress --optimize-autoloader
fi

# Genera APP_KEY si no existe
php artisan key:generate --no-interaction --quiet 2>/dev/null || true

# Crea el enlace de storage si no existe
php artisan storage:link --no-interaction --quiet 2>/dev/null || true

echo "[entrypoint] Arrancando Laravel en 0.0.0.0:8080..."
exec php artisan serve --host=0.0.0.0 --port=8080
