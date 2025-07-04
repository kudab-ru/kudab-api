# Dockerfile для Laravel 12 (PHP 8.2 + Alpine + Postgres)
FROM php:8.2-fpm-alpine

# Установить зависимости
RUN apk add --no-cache --update \
    postgresql-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libzip-dev \
    freetype-dev \
    icu-dev \
    zlib-dev \
    oniguruma-dev \
    libxml2-dev \
    git \
    bash

# Установить расширения PHP
RUN docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install pdo pdo_pgsql gd zip intl opcache bcmath xml

# Установить Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Копировать composer.json и composer.lock
COPY composer.json composer.lock ./

# Установить зависимости (без dev-зависимостей)
RUN composer install --no-dev --optimize-autoloader

# Копировать остальной код
COPY . .

# Права доступа
RUN chown -R www-data:www-data /var/www/html

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
