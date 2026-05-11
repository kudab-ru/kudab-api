<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety guard: предотвращает случайное использование dev/prod БД
     * в тестах.
     *
     * **Контекст** (2026-05-11): `WebEventsTest` использует
     * `RefreshDatabase` trait, которая делает `migrate:fresh` =
     * TRUNCATE+migrate всех таблиц. Если тесты запущены через
     * `php artisan test` без правильного env-override'а (sqlite memory
     * или `kudab_test`), они подключатся к рабочей dev-БД и **уничтожат
     * её**. Это уже происходило один раз.
     *
     * Правильные способы запуска тестов:
     *  - `make test`         — pgsql `kudab_test` (через test-db-init);
     *  - `make test-filter FILTER=...`;
     *  - `make test-fresh`   — pgsql `kudab_test` + migrate:fresh+seed.
     *
     * Этот guard аварийно прерывает тесты если детектит подключение
     * к НЕ-test БД, чтобы повтор был невозможен.
     */
    protected function setUp(): void
    {
        $this->assertTestDatabase();
        parent::setUp();
    }

    private function assertTestDatabase(): void
    {
        $connection = DB::connection();
        $name = $connection->getDatabaseName();

        // Допустимые имена test-БД:
        //  - 'kudab_test'                 — стандарт make test (pgsql)
        //  - ':memory:'                   — sqlite memory (если ENABLED)
        //  - что-то с '_test' или 'test_' — общая практика
        $allowed = function (string $n): bool {
            if ($n === '' || $n === ':memory:') return true;
            $lower = strtolower($n);
            return str_contains($lower, 'test');
        };

        if ($allowed($name)) return;

        // НЕ-test БД. Аварийно прерываем со внятным сообщением.
        $env = (string) config('app.env');
        $msg = <<<MSG

╔═══════════════════════════════════════════════════════════════════╗
║  ⛔  SAFETY GUARD: тесты подключены к НЕ-test БД                  ║
║                                                                    ║
║  DB name : {$name}
║  APP_ENV : {$env}
║                                                                    ║
║  RefreshDatabase trait сделает TRUNCATE+migrate ВСЕЙ этой базы.   ║
║  Если это dev/prod — данные будут потеряны.                       ║
║                                                                    ║
║  Используйте `make test` (pgsql kudab_test) или `make test-fresh` ║
║  вместо `php artisan test`.                                       ║
╚═══════════════════════════════════════════════════════════════════╝
MSG;
        fwrite(STDERR, $msg . PHP_EOL);
        throw new \RuntimeException("SafetyGuard: refusing to run tests on non-test DB '{$name}'");
    }
}
