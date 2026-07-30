<?php

namespace App\Support;

final class DatabaseSafety
{
    public const HINT_LINES = [
        'Тесты запускайте из папки kudab-infra:',
        '  make test',
        '  make test-filter FILTER=WebEventsTest',
        '  make test-fresh',
        'Все три работают на отдельной базе kudab_test.',
    ];

    public static function looksLikeTestDatabase(string $name): bool
    {
        $name = trim($name);

        if ($name === '') {
            return false;
        }

        if ($name === ':memory:') {
            return true;
        }

        return str_contains(mb_strtolower($name), 'test');
    }

    public static function wipeAllowedByOperator(): bool
    {
        return in_array(mb_strtolower(self::envValue('KUDAB_ALLOW_DB_WIPE')), ['1', 'true', 'yes'], true);
    }

    public static function databaseNameFromEnvFiles(string $projectRoot): string
    {
        $direct = self::envValue('DB_DATABASE');

        if ($direct !== '') {
            return $direct;
        }

        $appEnv = self::envValue('APP_ENV');

        if ($appEnv === '') {
            $appEnv = self::readKey($projectRoot.'/.env', 'APP_ENV');
        }

        if ($appEnv !== '' && is_file($projectRoot.'/.env.'.$appEnv)) {
            return self::readKey($projectRoot.'/.env.'.$appEnv, 'DB_DATABASE');
        }

        return self::readKey($projectRoot.'/.env', 'DB_DATABASE');
    }

    public static function envValue(string $key): string
    {
        foreach ([$_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        $fromGetenv = getenv($key);

        return is_string($fromGetenv) ? trim($fromGetenv) : '';
    }

    private static function readKey(string $file, string $key): string
    {
        if (! is_file($file) || ! is_readable($file)) {
            return '';
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return '';
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_starts_with($line, $key.'=')) {
                continue;
            }

            return trim(trim(substr($line, strlen($key) + 1)), "\"'");
        }

        return '';
    }
}
