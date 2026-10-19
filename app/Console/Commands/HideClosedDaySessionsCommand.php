<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Снять сеанс выставки без часа в выходной день её площадки (`venues.closed_weekdays`): выставка заведена днём
 * на каждую дату, и сайт показывал бы закрытый понедельник и писал «ежедневно». Событие с часом остаётся:
 * в выходной музея бывает лекция или концерт.
 */
class HideClosedDaySessionsCommand extends Command
{
    protected $signature = 'events:hide-closed-days';

    protected $description = 'Снять сеансы выставок без часа в выходные дни их площадок';

    public function handle(): int
    {
        $ids = DB::table('events as e')
            ->join('venues as v', 'v.id', '=', 'e.venue_id')
            ->whereNull('e.deleted_at')
            ->whereNull('e.start_time')
            ->where('e.time_precision', 'date')
            ->whereNotNull('v.closed_weekdays')
            ->where('e.start_date', '>=', now()->setTimezone('Europe/Moscow')->toDateString())
            ->whereRaw('v.closed_weekdays @> jsonb_build_array(extract(isodow from e.start_date)::int)')
            ->pluck('e.id');

        if ($ids->isNotEmpty()) {
            DB::table('events')->whereIn('id', $ids)->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
        $this->info('Снято сеансов: '.$ids->count());

        return self::SUCCESS;
    }
}
