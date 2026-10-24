<?php

declare(strict_types=1);

namespace App\Services\Occasions;

use App\Models\City;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Страница «Бесплатно»: три недели от сегодняшнего дня, каждую собирает SliceBuilder из одних бесплатных событий.
 * Главного нет: сайт показывает неделю карточками, по датам и отдельно то, что можно в любой день.
 */
final class FreePage
{
    private const TZ = 'Europe/Moscow';

    private const CACHE_SECONDS = 300;

    private const REL = ['эта неделя', 'следующая неделя', 'через две недели'];

    public function __construct(private readonly SliceBuilder $slices) {}

    /**
     * Вкладки недель и одна из них целиком: `want` — её первый день, иначе эта неделя.
     *
     * @return array{tabs: list<array<string, mixed>>, current: array<string, mixed>}
     */
    public function show(City $city, Carbon $now, ?string $want): array
    {
        $today = $now->copy()->setTimezone(self::TZ)->startOfDay();
        $all = [];
        foreach (self::REL as $i => $rel) {
            $all[] = $this->week($city, $today->copy()->addWeeks($i)) + ['rel' => $rel];
        }

        return [
            'tabs' => array_map(fn (array $w) => [
                'w' => $w['w'], 'from' => $w['from'], 'to' => $w['to'], 'label' => $w['label'], 'short' => $w['short'],
                'rel' => $w['rel'], 'days_left' => $w['days_left'], 'total' => $w['total'], 'holiday' => null,
            ], $all),
            'current' => collect($all)->firstWhere('w', $want) ?? $all[0],
        ];
    }

    /** @return array<string, mixed> */
    private function week(City $city, Carbon $from): array
    {
        return Cache::remember(
            'free-page:v1:'.$city->slug.':'.$from->toDateString(),
            self::CACHE_SECONDS,
            fn () => ['w' => $from->toDateString()] + $this->once($this->slices->build(
                ['page' => 'besplatno', 'slug' => 'besplatno', 'title' => 'Бесплатно', 'free' => true],
                $city, $from, $from->copy()->addDays(6)->endOfDay(), null, Carbon::now(self::TZ), true,
            )),
        );
    }

    /**
     * Событие стоит на неделе один раз. Без часа, не на один день и открыто с первого дня недели (выставка) —
     * в `both`, на сайте это «можно в любой день» до последнего дня; у остального другие дни уходят в `also` первого.
     *
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>
     */
    private function once(array $w): array
    {
        $series = [];
        foreach ($w['days'] as $d => $day) {
            foreach ($day['events'] as $i => $e) {
                $series[$this->slices->sameTitle($e['title'])][] = [$d, $i];
            }
        }

        $any = [];
        $drop = [];
        foreach ($series as $at) {
            $rows = array_map(fn (array $p) => $w['days'][$p[0]]['events'][$p[1]], $at);
            $timed = array_filter($rows, fn (array $r) => $r['times'] !== []);
            $open = $rows[0]['date'] === $w['from'];
            if ($timed === [] && $open && (count($rows) > 1 || $rows[0]['until'] !== null)) {
                $last = max([...array_column($rows, 'date'), ...array_filter(array_column($rows, 'until'))]);
                $any[] = ['until' => $last] + $rows[0];
                array_push($drop, ...$at);

                continue;
            }
            [$d, $i] = $at[0];
            $w['days'][$d]['events'][$i]['also'] = array_map(
                fn (array $r) => ['date' => $r['date'], 'time' => $r['times'][0] ?? null], array_slice($rows, 1));
            array_push($drop, ...array_slice($at, 1));
        }
        foreach ($drop as [$d, $i]) {
            unset($w['days'][$d]['events'][$i]);
        }
        foreach ($w['days'] as $d => $day) {
            $w['days'][$d]['events'] = array_values($day['events']);
        }
        $w['both'] = [...$w['both'], ...$any];
        usort($w['both'], fn (array $a, array $b) => $b['rank'] <=> $a['rank']);

        return $w;
    }
}
