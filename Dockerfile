FROM php:8.2-fpm-alpine

# зеркало можно оставить — так сборка быстрее
RUN sed -i 's|dl-cdn.alpinelinux.org/alpine|mirrors.aliyun.com/alpine|g' /etc/apk/repositories

# --- runtime пакеты + dev-пакеты (во временном слое .build-deps)
RUN apk update && apk add --no-cache \
    bash git unzip icu-libs libzip libpq \
 && apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS postgresql-dev libzip-dev icu-dev oniguruma-dev libxml2-dev \
 # --- phpredis (именно PECL)
 && pecl install -o -f redis \
 && docker-php-ext-enable redis \
 # --- остальные расширения
 && docker-php-ext-install pdo pdo_pgsql zip intl bcmath pcntl \
 # --- чистим dev-пакеты
 && apk del .build-deps

# composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# сначала зависимости, чтобы слои кешировались лучше
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction

# затем весь код
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction

# права в контейнере (на случай, если томов нет)
RUN mkdir -p storage bootstrap/cache \
 && chmod -R ug+rwX storage bootstrap/cache

# НЕ задаём USER тут — в compose вы зададите user: "${UID}:${GID}"
CMD ["php-fpm"]
