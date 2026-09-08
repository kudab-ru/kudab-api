<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Развод стенда и боевых телеграм-каналов.
 *
 * Локальная база — дамп прода, и в telegram.chats лежат НАСТОЯЩИЕ
 * telegram_chat_id боевых каналов (@kudab_vrn и соседние). Пока эта защита
 * не появилась, поднятый dev-стенд планировал реальные посты в них: стоило
 * разблокировать очередь, как планировщик тут же поставил пост в @kudab_vrn.
 * Не отправился он только потому, что bot-cron в тот момент был остановлен
 * руками.
 *
 * Чинить это правкой данных нельзя: следующий импорт дампа вернёт боевые
 * идентификаторы обратно, поэтому решение живёт в коде. Разрешено постить
 * там, где APP_ENV=production (у прода он задан явно в docker-compose.prod.yml)
 * либо где база тестовая — см. unrestricted(), там же почему не по APP_ENV
 * и не по runningUnitTests().
 *
 * Приём тот же, что у DatabaseSafety: по умолчанию запрещено, разрешение
 * выдаётся явно и поимённо.
 */
final class BroadcastSafety
{
    /** Куда стенду разрешено постить: список telegram_chat_id через запятую. */
    public const ALLOW_KEY = 'KUDAB_DEV_BROADCAST_CHAT_IDS';

    public const HINT_LINES = [
        'Стенду запрещено постить в телеграм-каналы: в базе лежат боевые id.',
        'Чтобы проверить рассылку на своём канале, добавь в kudab-infra/.env:',
        '  '.self::ALLOW_KEY.'=-1001234567890',
        'Несколько каналов — через запятую. На проде ключ не нужен и не читается.',
    ];

    /**
     * Можно ли из текущего окружения постить в этот чат.
     *
     * На проде — всегда да, поведение не меняется. На стенде — только если
     * идентификатор назван в KUDAB_DEV_BROADCAST_CHAT_IDS.
     */
    public static function postingAllowed(int $telegramChatId): bool
    {
        if (self::unrestricted()) {
            return true;
        }

        return in_array($telegramChatId, self::allowedChatIds(), true);
    }

    /**
     * Где ограничение не действует.
     *
     * production — там оно и не нужно, это и есть настоящая рассылка.
     * Тесты — они поднимают свои чаты в kudab_test, боевых идентификаторов там
     * нет по построению; запрет только сломал бы 16 тестов очереди, ничего не
     * защитив. Прицел ограничения — именно стенд с дампом прода.
     *
     * Про тесты спрашиваем по ИМЕНИ БАЗЫ, а не по APP_ENV, и не через
     * runningUnitTests(). Обе эти проверки здесь врут: у dev-контейнера в
     * окружении стоит APP_ENV=development, а <env name="APP_ENV" value="testing">
     * в phpunit.xml объявлен без force="true" и уже заданную переменную не
     * перебивает — во время `make test-filter` APP_ENV остаётся development,
     * а Application::runningUnitTests() смотрит ровно на него же.
     *
     * Имя базы — признак по существу: боевые telegram_chat_id попадают на стенд
     * вместе с дампом прода, а на kudab_test их взяться неоткуда. Тот же признак,
     * которым пользуется DatabaseSafety, чтобы не дать тестам стереть рабочую базу.
     */
    public static function unrestricted(): bool
    {
        if (mb_strtolower(trim((string) config('app.env'))) === 'production') {
            return true;
        }

        try {
            return DatabaseSafety::looksLikeTestDatabase(
                (string) DB::connection()->getDatabaseName(),
            );
        } catch (\Throwable) {
            // База недоступна — считаем, что постить нельзя. Отказ безопаснее.
            return false;
        }
    }

    /**
     * @return list<int>
     */
    public static function allowedChatIds(): array
    {
        $raw = DatabaseSafety::envValue(self::ALLOW_KEY);

        if ($raw === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            // Идентификаторы каналов отрицательные, поэтому просто is_numeric.
            if ($part !== '' && is_numeric($part)) {
                $ids[] = (int) $part;
            }
        }

        return $ids;
    }
}
