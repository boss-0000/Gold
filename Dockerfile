# Imagen PHP (php-fpm) para la app, el worker y el init.
FROM php:8.4-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libonig-dev default-mysql-client \
    && docker-php-ext-install pdo_mysql bcmath zip mbstring \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# El código se monta como volumen (ver docker-compose.yml); el init corre
# composer install y las migraciones en el arranque.
