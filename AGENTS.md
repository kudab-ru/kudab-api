# kudab-api

Бэкенд на PHP 8.2, Laravel 12, PostgreSQL и Redis. Архитектура: Controllers → Services → Repositories. Следовать уже принятому в соседнем коде разделению ответственности, не вводить новые слои без отдельного обоснования.

Общие правила работы, тестов и отчётов — в корневом `AGENTS.md` инфра-репозитория.

## Критично: не запускать тесты напрямую через artisan test

`phpunit.xml` не настраивает отдельную тестовую базу. Тесты используют `RefreshDatabase`, который выполняет `migrate:fresh` и пересоздаёт все таблицы базы, к которой подключился Laravel.

Запуск `php artisan test` без правильного окружения может подключиться к рабочей dev-базе `kudab` и уничтожить её. Такое уже происходило. Примеры опасных команд:

```bash
docker exec kudab-api php artisan test
docker compose exec kudab-api php artisan test --filter=X
```

### Правильный запуск

Всегда запускать тесты через Makefile из корня инфра-репозитория:

```bash
make test
make test-filter FILTER=Foo
make test-fresh
```

Эти цели вызывают `test-db-init` и работают с отдельной PostgreSQL-базой `kudab_test`. На проде Makefile запрещает их запуск.

### Защиту базы не убирать

1. `tests/TestCase.php` до запуска приложения проверяет имя базы из окружения или env-файлов, а после запуска — фактическое подключение. Неизвестное или не тестовое имя запрещает запуск.
2. `app/Providers/AppServiceProvider.php` запрещает `migrate:fresh`, `migrate:refresh`, `migrate:reset` и `db:wipe` на не тестовой базе независимо от `APP_ENV`. Обычные `migrate` и `migrate:rollback` разрешены.

Общая логика проверки имени находится в `app/Support/DatabaseSafety.php`; аналогичная защита есть в парсере.

`KUDAB_ALLOW_DB_WIPE=1` разрешает очистку базы разработки. Использовать этот обход только по явной просьбе владельца, а не для устранения ошибки запуска тестов.

Дополнительные правила связанного сервиса — в `services/kudab-parser/AGENTS.md` и `services/kudab-parser/CLAUDE.md`.
