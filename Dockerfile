
FROM dunglas/frankenphp:1-php8.5-trixie

# Instalar extensiones PHP necesarias para Laravel y PostgreSQL
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    intl \
    zip \
    bcmath \
    opcache \
    pcntl \
    gd \
    exif

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

COPY . /app

RUN composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction

RUN mkdir -p storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Configurar servidor HTTP
COPY docker/Caddyfile /etc/caddy/Caddyfile

EXPOSE 10000

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
