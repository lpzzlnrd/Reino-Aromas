# =============================================================================
# Dockerfile — Reino Aromas CRM (Producción)
#
# Imagen multi-stage:
#   stage 1 (node-builder): compila el frontend Vue con Vite
#   stage 2 (php-base):     instala extensiones PHP necesarias
#   stage 3 (app):          imagen final — PHP-FPM + assets compilados
#
# Nginx corre FUERA de esta imagen (en su propio contenedor en docker-compose)
# y hace proxy a PHP-FPM en el puerto 9000.
# =============================================================================

# -----------------------------------------------------------------------------
# Stage 1: compilar assets Vue/Vite
# -----------------------------------------------------------------------------
FROM node:22-alpine AS node-builder

WORKDIR /app

# Copiar manifiestos primero para aprovechar la caché de capas Docker.
# Si package.json no cambia, npm install no se re-ejecuta en builds posteriores.
COPY package.json package-lock.json* ./
RUN npm ci --ignore-scripts

# Copiar el resto del código fuente necesario para el build
COPY vite.config.js ./
# Los tsconfig viven en la raíz del repo, no en resources/views.
COPY tsconfig*.json ./
COPY resources/css ./resources/css
COPY resources/js  ./resources/js
COPY resources/views/src ./resources/views/src
# El @font-face de styles.css referencia ../public/assets/fonts/*, así que
# Vite necesita esos archivos presentes o el build falla al resolverlos.
COPY resources/views/public ./resources/views/public
# Las vistas Blade deben estar presentes: los @source de app.css las escanean
# para generar las clases Tailwind del login. Sin ellas el login sale sin estilos.
COPY resources/views/*.blade.php ./resources/views/
COPY resources/views/auth ./resources/views/auth

# Generar los assets hasheados en public/build/
RUN npm run build


# -----------------------------------------------------------------------------
# Stage 2: base PHP con extensiones
# -----------------------------------------------------------------------------
# PHP 8.4: las dependencias de Symfony 8.x en composer.lock exigen >= 8.4.
# Con 8.3 el platform_check.php de Composer aborta y el contenedor no arranca.
FROM php:8.4-fpm-alpine AS php-base

# Instalar dependencias del sistema y extensiones PHP requeridas por Laravel
RUN apk add --no-cache \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        freetype-dev \
        zip \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache

# Redis para colas en producción
RUN pecl install redis && docker-php-ext-enable redis

# Copiar configuración PHP optimizada para producción
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/99-app.ini


# -----------------------------------------------------------------------------
# Stage 3: imagen final
# -----------------------------------------------------------------------------
FROM php-base AS app

WORKDIR /var/www/html

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Código fuente de Laravel
COPY . .

# Traer los assets compilados del stage 1
COPY --from=node-builder /app/public/build ./public/build

# Instalar dependencias PHP sin dev-dependencies y con autoloader optimizado
RUN composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --no-progress

# Permisos correctos para storage y bootstrap/cache
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# PHP-FPM escucha en el puerto 9000 (Nginx hace proxy aquí)
EXPOSE 9000

CMD ["php-fpm"]
