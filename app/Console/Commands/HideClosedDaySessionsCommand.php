<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Снять день выставки в выходной день её площадки (`venues.closed_weekdays`): выставка заведена днём на каждую
 * дату, и сайт показывал бы закрытый понедельник и писал «ежедневно». День выставки узнаём по серии, где есть
 * день без часа, когда площадка открыта. Разовое событие без часа остаётся: в выходной бывает церемония.
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
            ->whereExists(fn ($q) => $q->from('events as s')
                ->whereColumn('s.event_group_id', 'e.event_group_id')
                ->whereColumn('s.start_date', '<>', 'e.start_date')
                ->whereNull('s.start_time')
                ->where('s.time_precision', 'date')
                ->whereNull('s.deleted_at')
                ->whereRaw('not (v.closed_weekdays @> jsonb_build_array(extract(isodow from s.start_date)::int))'))
            ->pluck('e.id');

        if ($ids->isNotEmpty()) {
            DB::table('events')->whereIn('id', $ids)->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
        $this->info('Снято сеансов: '.$ids->count());

        return self::SUCCESS;
    }
}
