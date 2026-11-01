<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Насколько далеко от центра города событие ещё «в городе» для канала. Город источника тут не помогает:
 * концерт в Боброве приходит от воронежского сообщества.
 */
final class CityRadius
{
    /** Бобров, Лиски и Борисоглебск дальше, Рамонь и Костёнки ближе. */
    public const CHANNEL_KM = 50;

    /**
     * Оставить события не дальше `$km` от центра города; событие без координат не отсекаем.
     * `$venues` — псевдоним присоединённых площадок, их координаты идут, когда у события своих нет.
     */
    public static function apply(Builder $q, int $cityId, float $km, string $events = 'events', ?string $venues = null): void
    {
        $center = self::center($cityId);
        if ($center === null) {
            return;
        }

        $lat = $venues === null ? "{$events}.latitude" : "coalesce({$events}.latitude, {$venues}.latitude)";
        $lng = $venues === null ? "{$events}.longitude" : "coalesce({$events}.longitude, {$venues}.longitude)";
        // градус широты — 111 км, градус долготы короче в cos(широты) раз; на десятках километров точности хватает
        $q->whereRaw(
            "({$lat} is null or {$lng} is null
              or power(({$lat} - ?) * 111.2, 2) + power(({$lng} - ?) * 111.2 * cos(radians(?)), 2) <= power(?, 2))",
            [$center[0], $center[1], $center[0], $km],
        );
    }

    /** @return array{float, float}|null */
    private static function center(int $cityId): ?array
    {
        $row = DB::table('cities')->where('id', $cityId)->first(['latitude', 'longitude']);

        return $row?->latitude === null || $row->longitude === null ? null : [(float) $row->latitude, (float) $row->longitude];
    }
}
