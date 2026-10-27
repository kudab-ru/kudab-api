<?php

declare(strict_types=1);

namespace App\Services\Occasions;

use App\Models\City;
use App\Services\Weather\CityWeather;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Страница «Сегодня»: сегодня и завтра, каждый день собирает SliceBuilder. Сегодня без того, что уже прошло;
 * рубрики на один день у канала нет, главное собирает сайт, см. OccasionPage::sitePick.
 */
final class DayPage
{
    private const TZ = 'Europe/Moscow';

    /** на сайте «Главное» листается вбок: видно пять карточек, остальные за краем */
    private const PICKS = 8;

    private const CACHE_SECONDS = 300;

    private const REL = ['сегодня', 'завтра'];

    public function __construct(
        private readonly SliceBuilder $slices,
        private readonly CityWeather $weather,
    ) {}

    /**
     * Вкладки дней и один из них целиком: `want` — его дата, иначе сегодня.
     *
     * @return array{tabs: list<array<string, mixed>>, current: array<string, mixed>}
     */
    public function show(City $city, Carbon $now, ?string $want): array
    {
        $today = $now->copy()->setTimezone(self::TZ)->startOfDay();
        $all = [];
        foreach (self::REL as $i => $rel) {
            $all[] = $this->day($city, $today->copy()->addDays($i), $i === 0) + ['rel' => $rel];
        }

        return [
            'tabs' => array_map(fn (array $d) => [
                'w' => $d['w'], 'from' => $d['from'], 'to' => $d['to'], 'label' => $d['label'], 'short' => $d['short'],
                'rel' => $d['rel'], 'days_left' => $d['days_left'], 'total' => $d['total'],
                'holiday' => $d['holiday'] === null ? null : array_diff_key($d['holiday'], ['ids' => true]),
            ], $all),
            'current' => $this->withWeather($city, $this->slices->withoutPicks(collect($all)->firstWhere('w', $want) ?? $all[0]), $now),
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function withWeather(City $city, array $current, Carbon $now): array
    {
        return $current + ['weather' => $this->weather->forDays(
            $city, Carbon::parse($current['from'], 'Europe/Moscow'), Carbon::parse($current['to'], 'Europe/Moscow')->endOfDay(), $now,
        )];
    }

    /** @return array<string, mixed> */
    private function day(City $city, Carbon $day, bool $today): array
    {
        return Cache::remember(
            'day-page:v3:'.$city->slug.':'.$day->toDateString().':'.(int) $today,
            self::CACHE_SECONDS,
            fn () => ['w' => $day->toDateString(), 'weekday' => $day->copy()->locale('ru')->dayName]
                + $this->slices->build($this->theme(), $city, $day->copy()->startOfDay(), $day->copy()->endOfDay(), $day, Carbon::now(self::TZ), $today),
        );
    }

    private function theme(): array
    {
        return ['page' => 'segodnya', 'site_pick' => true, 'picks' => self::PICKS, 'slug' => 'segodnya', 'title' => 'Сегодня', 'pick' => 'all', 'slim_rows' => true];
    }
}
