# kudab-api

Бэкенд-платформа для [kudab.ru](https://kudab.ru) — агрегатора мероприятий. REST API на Laravel/PHP: бизнес-логика, агрегация событий, фильтрация, ранжирование и интеграция с внешними сервисами.

## Стек

PHP 8.3, Laravel 12, PostgreSQL, Redis, Docker

## Возможности

- REST API для событий, афиши и пользовательских сценариев
- Фильтрация и поиск по городам, категориям, датам и ключевым словам
- Ранжирование событий по качеству, актуальности и полноте данных
- Интеграция с парсером событий, Telegram-ботом и внешними сервисами
- Ролевая модель доступа

## Архитектура

Проект построен по схеме **Controllers → Services → Repositories** (Слоистая архитектура / **Layered Architecture**) с разделением бизнес-логики и слоя данных.

## Запуск

Проект запускается через Docker. Оркестрация и docker-compose находятся в репозитории [kudab-infra](https://github.com/kudab-ru/kudab-infra), kudab-api подключается как подмодуль.

```bash
# Клонировать infra-репозиторий с подмодулями
git clone --recurse-submodules https://github.com/kudab-ru/kudab-infra.git
cd kudab-infra

# Скопировать конфигурацию
cp .env.example .env

# Поднять контейнеры
docker-compose up -d

# Миграции и сидеры
docker-compose exec api php artisan migrate --seed
```

## Тесты

```bash
docker-compose exec api php artisan test
```

Покрыты: валидация данных событий, фильтрация и поиск, ранжирование, API-эндпоинты.

## Связанные репозитории

- [kudab-infra](https://github.com/kudab-ru/kudab-infra) — оркестрация и деплой
- [kudab-parser](https://github.com/kudab-ru/kudab-parser) — парсер событий (приватный)
