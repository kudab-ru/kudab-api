<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramChatBroadcastService;
use Illuminate\Console\Command;

/**
 * P0 автопостинг, фаза 1 — автонаполнение очереди.
 *
 * Для каждого enabled+due city-канала (расписание в chat_broadcasts.settings.period)
 * подбирает событие города и кладёт в очередь (status=pending), если очередь пуста.
 * Сам постинг делает bot-cron, который поллит /broadcast/single/run/poll.
 * Тонкий адаптер над TelegramChatBroadcastService::enqueueDueForAllChannels.
 */
class BroadcastEnqueueDueCommand extends Command
{
    protected $signature = 'broadcast:enqueue-due {--dry-run : Посчитать кандидатов, но не писать в очередь}';

    protected $description = 'Автонаполнение очереди city-каналов под автопостинг (P0, фаза 1)';

    public function handle(TelegramChatBroadcastService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $s = $service->enqueueDueForAllChannels(now(), $dryRun);

        $this->info(sprintf(
            'broadcast:enqueue-due%s — checked=%d due=%d enqueued=%d (no_city=%d queue_busy=%d no_candidate=%d no_reviewer=%d not_allowed=%d)',
            $dryRun ? ' [dry-run]' : '',
            $s['checked'],
            $s['due'],
            $s['enqueued'],
            $s['skipped_no_city'],
            $s['skipped_queue_busy'],
            $s['no_candidate'],
            $s['skipped_no_reviewer'],
            $s['skipped_not_allowed'],
        ));

        // not_allowed — это НЕ голодание: так и задумано, что стенд не постит
        // в боевые каналы (App\Support\BroadcastSafety). Говорим прямо, иначе
        // «due=1 enqueued=0» на стенде выглядит поломкой.
        if ($s['skipped_not_allowed'] > 0) {
            $this->line("  {$s['skipped_not_allowed']} канал(ов) пропущено: стенду боевые каналы запрещены (".\App\Support\BroadcastSafety::ALLOW_KEY.' в .env разрешает свой)');
        }

        // Голодание: due-каналы, которые должны были опубликовать, но не смогли.
        $starved = $s['skipped_no_city'] + $s['no_candidate'] + $s['skipped_no_reviewer'];
        if ($starved > 0) {
            $this->warn("⚠ {$starved} due-канал(ов) не опубликовали (см. broadcast.enqueue.* в логах: no_city/no_candidate/no_reviewer)");
        }

        return self::SUCCESS;
    }
}
