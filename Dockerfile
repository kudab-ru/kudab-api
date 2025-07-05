FROM php:8.2-fpm-alpine

# Установка системных зависимостей
RUN apk update && apk add --no-cache \
    bash \
    postgresql-dev \
    git \
    unzip \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    libxml2-dev

# PHP-расширения
RUN docker-php-ext-install pdo pdo_pgsql zip intl bcmath

# Установка Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Копируем только composer.json и composer.lock для кеширования установки зависимостей
COPY composer.json composer.lock ./

RUN composer install --no-scripts --no-autoloader

# Копируем остальной код
COPY . .

# Устанавливаем зависимости с автозагрузчиком
RUN composer install --optimize-autoloader --no-dev

# Настраиваем права для storage и bootstrap/cache
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

USER www-data

ENTRYPOINT ["/entrypoint.sh"]

CMD ["php-fpm"]
