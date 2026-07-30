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

### Защита от повтора (два слоя, не убирай)

Случай повторился 2026-07-30: первый сторож пропускал удар, потому что
переменная окружения `DB_DATABASE` в контейнере пустая (имя базы Laravel
берёт из файла `.env`), а пустое значение считалось «тестовым».

1. `tests/TestCase.php` — проверка до `parent::setUp()`. Имя базы берётся из
   переменной окружения, а если её нет, читается из `.env` (или `.env.<APP_ENV>`,
   как это делает сам Laravel). Всё, что не содержит `test` и не `:memory:`,
   а также неизвестное имя, запуск запрещает.
2. `app/Providers/AppServiceProvider.php` — `migrate:fresh`, `migrate:refresh`,
   `migrate:reset` и `db:wipe` запрещены на любой не-тестовой базе, независимо
   от `APP_ENV`. Обычный `migrate` и `migrate:rollback` работают как раньше.
   Разовое снятие запрета: `KUDAB_ALLOW_DB_WIPE=1` перед командой.

Логика имени базы лежит в `app/Support/DatabaseSafety.php`, такая же копия
есть в парсере.

---

Для общей документации по сервисам см. CLAUDE.md в корне инфра-репо
и `services/kudab-parser/CLAUDE.md`.
