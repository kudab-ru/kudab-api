<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Свой счётчик просмотров карточек событий: страница шлёт сигнал, если человек пробыл на ней
 * три секунды. Не зависит от Метрики; на нём стоит подборка «Что смотрят».
 */
final class EventViews
{
    /** Метку браузер придумывает сам и хранит у себя; в базу идёт только её хэш. */
    public static function visitor(string $mark): string
    {
        return substr(hash('sha256', $mark.'|'.config('app.key')), 0, 16);
    }

    /** Один посетитель — один просмотр события в сутки. */
    public static function record(Request $request, int $eventId, string $mark): bool
    {
        if (! HumanAgent::looksLikePerson($request->userAgent())) {
            return false;
        }

        try {
            return DB::table('event_views')->insertOrIgnore([
                'event_id' => $eventId,
                'visitor' => self::visitor($mark),
                'day' => Carbon::now('Europe/Moscow')->toDateString(),
                'created_at' => now(),
            ]) > 0;
        } catch (Throwable $e) {
            // счётчик не должен ронять страницу
            report($e);

            return false;
        }
    }

    /**
     * Сколько разных людей открывали карточку за последние `$days` суток до `$at`.
     *
     * @param  list<int>  $eventIds
     * @return array<int, int>
     */
    public static function visitors(array $eventIds, int $days, Carbon $at): array
    {
        if ($eventIds === []) {
            return [];
        }

        $to = $at->copy()->setTimezone('Europe/Moscow')->toDateString();
        $from = $at->copy()->setTimezone('Europe/Moscow')->subDays(max(1, $days) - 1)->toDateString();

        return DB::table('event_views')
            ->whereIn('event_id', $eventIds)
            ->whereBetween('day', [$from, $to])
            ->groupBy('event_id')
            ->selectRaw('event_id, count(distinct visitor) as n')
            ->pluck('n', 'event_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
