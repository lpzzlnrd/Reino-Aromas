# Imagen liviana para development — solo PHP-FPM + extensiones mínimas
# No compila assets (eso lo hace el contenedor vite)

FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        libpng-dev libjpeg-turbo-dev freetype-dev \
        zip libzip-dev icu-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring bcmath gd zip intl pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# En dev el código se monta como volumen, así que composer install
# lo ejecuta el desarrollador o el entrypoint — no aquí.

EXPOSE 8000

# Artisan serve escucha en 0.0.0.0 para ser alcanzable desde el host
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
