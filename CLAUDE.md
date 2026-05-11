# CLAUDE.md — kudab-api

## ⛔ КРИТИЧНО: НЕ ЗАПУСКАЙ ТЕСТЫ НАПРЯМУЮ ЧЕРЕЗ artisan test

**phpunit.xml в этом сервисе НЕ настраивает test-БД** (sqlite/in-memory
закомментированы). Тесты используют `RefreshDatabase` trait, которая
делает `migrate:fresh` = **TRUNCATE+migrate ВСЕХ таблиц**.

Если ты запустишь:
```
docker exec kudab-api php artisan test
docker compose exec kudab-api php artisan test --filter=X
```
без правильного env-override'а — тесты подключатся к **рабочей dev-pgsql
`kudab`** и уничтожат её. **Это уже происходило** (2026-05-11).

### Правильные способы запуска тестов

Всегда через Makefile из инфра-репо (`/home/maks/projects/kudab-infra/`):

```bash
make test                       # все тесты на pgsql kudab_test
make test-filter FILTER=Foo     # фильтр
make test-fresh                 # migrate:fresh+seed на kudab_test
```

Эти цели используют `test-db-init` → отдельная БД `kudab_test`.

### Safety guard

В `tests/TestCase.php` есть assertion в `setUp()` — отказывает запускаться
если `DB::connection()->getDatabaseName()` НЕ содержит `test` (или не
`:memory:`). Это страхует от случайного запуска тестов на dev-БД.
**Не убирай этот guard.** Он стоил нам одного полного reindex'а
(восстановление через `make reindex` после уничтожения dev-БД).

---

Для общей документации по сервисам см. CLAUDE.md в корне инфра-репо
и `services/kudab-parser/CLAUDE.md`.
