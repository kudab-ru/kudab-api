<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\GrowthReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Заранее считает отчёт страницы «Аналитика» и кладёт в кэш.
 *
 * Сборка стоит восьми обращений к Метрике и Вебмастеру и занимает около
 * минуты — nginx рвёт такой запрос раньше, чем тот успевает ответить.
 * Поэтому страница НИКОГДА не собирает отчёт сама: она читает кэш, а
 * наполняет его расписание — раз в три часа и отдельным минутным тиком,
 * когда владелец нажал «Обновить».
 */
class AnalyticsWarmCommand extends Command
{
    protected $signature = 'analytics:warm
        {--requested-only : Считать, только если владелец нажал «Обновить»}';

    protected $description = 'Пересчитать отчёт аналитики роста и положить в кэш';

    /** Ключ и срок жизни общие с AdminAnalyticsController. */
    public const KEY = 'admin:analytics:growth';

    public const TTL_HOURS = 6;

    /** Просьба пересчитать, оставленная ручкой. */
    public const REQUEST_FLAG = 'admin:analytics:growth:requested';

    public function handle(GrowthReport $report): int
    {
        // Ручное обновление идёт через планировщик, а не через очередь:
        // очередь у kudab-api никто не разбирает — Horizon в этой сборке
        // крутит парсер. Планировщик же тикает каждую минуту и проверен.
        if ($this->option('requested-only') && ! Cache::pull(self::REQUEST_FLAG)) {
            return self::SUCCESS;
        }

        $started = microtime(true);

        try {
            $payload = $report->build();
        } catch (\Throwable $e) {
            // Прошлый отчёт не трогаем: устаревшие числа лучше пустой страницы.
            $this->error('Отчёт собрать не вышло: '.mb_substr($e->getMessage(), 0, 200));
            Log::error('analytics.warm.failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        Cache::put(self::KEY, $payload, now()->addHours(self::TTL_HOURS));

        $seconds = round(microtime(true) - $started, 1);
        $errors = count($payload['meta']['errors']);

        $this->info("Отчёт пересчитан за {$seconds} с, секций с отказом: {$errors}");

        if ($errors > 0) {
            Log::warning('analytics.warm.partial', ['errors' => $payload['meta']['errors']]);
        }

        return self::SUCCESS;
    }
}
