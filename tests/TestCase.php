<?php

namespace Tests;

use App\Support\DatabaseSafety;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Опасное место: трейт RefreshDatabase выполняет migrate:fresh, то есть сносит
 * все таблицы той базы, к которой подключены тесты. Проверка имени базы стоит
 * ДО parent::setUp() и при любом сомнении запрещает запуск.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->refuseNonTestDatabaseBeforeBoot();

        parent::setUp();

        $this->refuseNonTestDatabaseAfterBoot();
    }

    private function refuseNonTestDatabaseBeforeBoot(): void
    {
        $name = DatabaseSafety::databaseNameFromEnvFiles(dirname(__DIR__));

        if (DatabaseSafety::looksLikeTestDatabase($name)) {
            return;
        }

        $this->refuse($name === ''
            ? 'имя базы определить не удалось'
            : "база «{$name}» не тестовая");
    }

    private function refuseNonTestDatabaseAfterBoot(): void
    {
        $name = (string) DB::connection()->getDatabaseName();

        if (DatabaseSafety::looksLikeTestDatabase($name)) {
            return;
        }

        $this->refuse("после запуска приложения база оказалась «{$name}», она не тестовая");
    }

    private function refuse(string $reason): never
    {
        $message = implode(PHP_EOL, array_merge(
            [
                '',
                'Тесты остановлены: '.$reason.'.',
                'Тесты стирают базу целиком, поэтому запуск отменён.',
                '',
            ],
            DatabaseSafety::HINT_LINES,
            [
                'Внутри контейнера то же самое: php artisan test --env=testing',
                '',
            ]
        ));

        fwrite(STDERR, $message.PHP_EOL);

        throw new RuntimeException(trim($message));
    }
}
