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
            'broadcast:enqueue-due%s — checked=%d due=%d enqueued=%d (no_city=%d queue_busy=%d no_candidate=%d no_reviewer=%d)',
            $dryRun ? ' [dry-run]' : '',
            $s['checked'],
            $s['due'],
            $s['enqueued'],
            $s['skipped_no_city'],
            $s['skipped_queue_busy'],
            $s['no_candidate'],
            $s['skipped_no_reviewer'],
        ));

        return self::SUCCESS;
    }
}
