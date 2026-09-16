<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Telegram\TelegramChatBroadcastItemRepositoryInterface;
use App\Models\Event;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Services\Telegram\PostTiming;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Снять из очереди ожидания то, что уже не выйдет.
 *
 * ЗАЧЕМ. Запись без дня ждёт, когда освободится слот. Её просроченность до сих
 * пор замечала ТОЛЬКО доставка — и только если запись доберётся до головы
 * очереди. У канала с заполненной лентой она туда не добирается никогда:
 * слотов нет, очередь не двигается, а ячейку `feed_limit` запись держит. То
 * есть мёртвая запись занимает живое место и молча урезает ленту.
 *
 * Живой пример со стенда: у боевого канала три записи без дня, одна ждёт с
 * 9 сентября при старте события в тот же вечер.
 *
 * ЧТО СЧИТАЕМ МЁРТВЫМ. Запись без дня можно поставить только в БУДУЩИЙ слот,
 * поэтому смерть — это «событие уже началось» (у многодневки — закрылось), то
 * есть [[PostTiming]]::deadline в прошлом. Правило то же, что у постановки, и
 * это не случайно: ждать дня для поста, который ни в один будущий день уже не
 * встанет, — значит держать место зря.
 *
 * ПОЧЕМУ НЕ ЧАСТЬ НАПОЛНИТЕЛЯ. Уборка и наполнение — разные работы с разной
 * ценой ошибки: наполнитель на стенде боевому каналу ленту не трогает (см.
 * BroadcastSafety), а подметать там можно и нужно. Плюс отдельную команду
 * видно в `schedule:list` и в логе.
 */
class SweepQueueCommand extends Command
{
    protected $signature = 'broadcast:sweep-queue
        {--broadcast= : Только один канал, по id}
        {--dry-run : Показать, что сняли бы, и ничего не писать}';

    protected $description = 'Снять из очереди ожидания записи, чьё событие уже началось или удалено';

    public function handle(TelegramChatBroadcastItemRepositoryInterface $items): int
    {
        $now = Carbon::now();
        $dry = (bool) $this->option('dry-run');

        $query = TelegramChatBroadcast::query();
        if ($this->option('broadcast') !== null) {
            $query->where('id', (int) $this->option('broadcast'));
        }

        $summary = ['checked' => 0, 'gone' => 0, 'passed' => 0];

        foreach ($query->pluck('id') as $broadcastId) {
            $waiting = TelegramChatBroadcastItem::query()
                ->where('broadcast_id', $broadcastId)
                ->where('kind', TelegramChatBroadcastItem::KIND_EVENT)
                ->whereIn('status', [
                    TelegramChatBroadcastItem::STATUS_PENDING,
                    TelegramChatBroadcastItem::STATUS_PLANNED,
                ])
                ->whereNull('publish_at')
                ->whereNull('posted_at')
                ->whereNull('claimed_at')
                ->get();

            foreach ($waiting as $item) {
                $summary['checked']++;

                $event = $item->event_id ? Event::query()->find($item->event_id) : null;

                $reason = match (true) {
                    $event === null => 'события больше нет — снято из очереди ожидания',
                    ! PostTiming::fits($event, $now) => 'событие началось, пока запись ждала свободного дня',
                    default => null,
                };

                if ($reason === null) {
                    continue;
                }

                $this->line(sprintf(
                    '<fg=yellow>#%d</> (событие %s) — %s',
                    $item->id,
                    $item->event_id ?? '?',
                    $reason,
                ));

                if ($dry) {
                    $summary['gone']++;

                    continue;
                }

                $items->markSkipped($item, $reason);
                $summary['gone']++;

                Log::info('broadcast.queue.swept', [
                    'broadcast_id' => $broadcastId,
                    'item_id' => $item->id,
                    'event_id' => $item->event_id,
                    'reason' => $reason,
                ]);
            }
        }

        $summary['passed'] = $summary['checked'] - $summary['gone'];

        $this->info(sprintf(
            'sweep-queue: в ожидании %d, снято %d, живых %d%s',
            $summary['checked'], $summary['gone'], $summary['passed'],
            $dry ? ' [DRY-RUN]' : '',
        ));

        return self::SUCCESS;
    }
}
