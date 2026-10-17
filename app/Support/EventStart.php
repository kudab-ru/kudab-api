<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * Начало события для подборок и страниц праздников. У события без часа start_time пуст, а идёт оно
 * весь свой день: в выборке ему ставится полночь этого дня по Москве, границы сравниваются с датой.
 * Границы уходят в запрос в UTC: время без пояса база читает как UTC.
 */
final class EventStart
{
    private const TZ = 'Europe/Moscow';

    /** Начало для выборки и сортировки. */
    public static function sql(string $table): string
    {
        return "COALESCE({$table}.start_time, {$table}.start_date::timestamp AT TIME ZONE '".self::TZ."')";
    }

    /** Начало модели или строки выборки; null — нет и даты. */
    public static function of(object $e): ?Carbon
    {
        if (! empty($e->start_time)) {
            return Carbon::parse($e->start_time);
        }
        $date = $e->start_date ?? null;
        if ($date instanceof DateTimeInterface) {
            $date = $date->format('Y-m-d');
        }

        return $date ? Carbon::parse((string) $date, self::TZ) : null;
    }

    /** Начинается не раньше `$at`; событие без часа — если его день не раньше дня `$at`. */
    public static function notBefore($query, Carbon $at, string $table): void
    {
        $query->where(fn ($w) => $w->where($table.'.start_time', '>=', $at->copy()->utc())
            ->orWhere(fn ($d) => $d->whereNull($table.'.start_time')
                ->where($table.'.start_date', '>=', $at->copy()->setTimezone(self::TZ)->toDateString())));
    }

    /** Начинается не позже `$at`; событие без часа — если его день не позже дня `$at`. */
    public static function notAfter($query, Carbon $at, string $table): void
    {
        $query->where(fn ($w) => $w->where($table.'.start_time', '<=', $at->copy()->utc())
            ->orWhere(fn ($d) => $d->whereNull($table.'.start_time')
                ->where($table.'.start_date', '<=', $at->copy()->setTimezone(self::TZ)->toDateString())));
    }
}
