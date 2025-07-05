#!/bin/sh

# 1. Устанавливаем зависимости, если их нет
if [ ! -d "vendor" ]; then
  mkdir -p vendor
  chown -R $(id -u):$(id -g) vendor
fi

composer install --no-interaction --prefer-dist --optimize-autoloader

# 2. Генерируем ключ, если не установлен
if ! grep -q "^APP_KEY=base64:" .env; then
  php artisan key:generate --force
fi

# 3. Миграции (опционально, для dev)
php artisan migrate --force || true

# 4. Запускаем php-fpm
exec php-fpm
