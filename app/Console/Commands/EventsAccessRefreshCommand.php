<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telegram\Scoring\EventAccess;
use App\Services\Telegram\Scoring\EventInterestSignals;
use App\Support\EventStart;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Пересчитать «как попасть» (`events.access`) у будущих и идущих событий. Тема, площадка и цена у события
 * меняются после разбора, поэтому пересчёт идёт по расписанию, а не при создании.
 */
class EventsAccessRefreshCommand extends Command
{
    protected $signature = 'events:access:refresh';

    protected $description = 'Пересчитать категорию «как попасть» у будущих событий';

    public function handle(EventInterestSignals $signals): int
    {
        $changed = 0;
        $from = now()->setTimezone('Europe/Moscow')->startOfDay();

        DB::table('events')
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where(fn ($w) => EventStart::notBefore($w, $from, 'events'))
                ->orWhere('end_time', '>=', now()->utc()))
            ->select(['id', 'title', 'price_status', 'price_min', 'price_text', 'description', 'access'])
            ->chunkById(500, function ($rows) use ($signals, &$changed) {
                $byId = $signals->forEvents($rows->pluck('id')->map(fn ($id) => (int) $id)->all());
                foreach ($rows as $row) {
                    $access = EventAccess::of($byId[(int) $row->id] ?? [], $row);
                    if ($access !== $row->access) {
                        DB::table('events')->where('id', $row->id)->update(['access' => $access]);
                        $changed++;
                    }
                }
            });

        $this->info('Категория поменялась у '.$changed);

        return self::SUCCESS;
    }
}
