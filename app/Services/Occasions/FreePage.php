<?php

declare(strict_types=1);

namespace App\Services\Occasions;

use App\Models\City;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Страница «Бесплатно»: три недели от сегодняшнего дня, каждую собирает SliceBuilder из одних бесплатных событий.
 * Главного нет: сайт показывает неделю карточками, по датам и отдельно то, что можно в любой день.
 * Монеты копилки добавляют к бесплатному платное с входом не дороже 300, 500 или 1000 ₽.
 */
final class FreePage
{
    private const TZ = 'Europe/Moscow';

    private const CACHE_SECONDS = 300;

    private const REL = ['эта неделя', 'следующая неделя', 'через две недели'];

    /** монеты копилки: до скольких рублей за вход; 0 — только бесплатное */
    public const STEPS = [0, 300, 500, 1000];

    public function __construct(
        private readonly SliceBuilder $slices,
        private readonly OccasionPage $page,
    ) {}

    /**
     * Вкладки недель и одна из них целиком: `want` — её первый день, иначе эта неделя. С `budget` в неделе
     * и в числах вкладок есть и недорогое платное.
     *
     * @return array{tabs: list<array<string, mixed>>, current: array<string, mixed>}
     */
    public function show(City $city, Carbon $now, ?string $want, int $budget = 0): array
    {
        $budget = in_array($budget, self::STEPS, true) ? $budget : 0;
        $today = $now->copy()->setTimezone(self::TZ)->startOfDay();
        $weeks = [];
        foreach (self::REL as $i => $rel) {
            $from = $today->copy()->addWeeks($i);
            $weeks[] = ['rel' => $rel, 'from' => $from, 'free' => $this->week($city, $from)];
        }
        $at = array_search($want, array_map(fn (array $x) => $x['free']['w'], $weeks), true);
        $i = $at === false ? 0 : (int) $at;

        $shown = [];
        foreach ($weeks as $j => $x) {
            // недорогое нужно выбранной неделе для монет, остальным — только при бюджете
            $cheap = $j === $i || $budget > 0 ? $this->cheap($city, $x['from']) : [];
            $shown[] = $this->spend($x['free'], $cheap, $budget) + ['rel' => $x['rel'], 'cheap' => $cheap];
        }

        return [
            'tabs' => array_map(fn (array $w) => [
                'w' => $w['w'], 'from' => $w['from'], 'to' => $w['to'], 'label' => $w['label'], 'short' => $w['short'],
                'rel' => $w['rel'], 'days_left' => $w['days_left'], 'total' => $w['total'], 'holiday' => null,
            ], $shown),
            'current' => array_diff_key($shown[$i], ['cheap' => true]) + [
                'budget' => $budget,
                'piggy' => $this->piggy($city, $now, $weeks[$i]['free'], $shown[$i]['cheap']),
            ],
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
     * Платное недели с входом до самой дорогой монеты, по карточке на событие. `day` — день программы
     * с подписями, null — карточка в «можно в любой день».
     *
     * @return list<array<string, mixed>>
     */
    private function cheap(City $city, Carbon $from): array
    {
        return Cache::remember(
            'free-page:cheap:v1:'.$city->slug.':'.$from->toDateString(),
            self::CACHE_SECONDS,
            function () use ($city, $from) {
                $w = $this->once($this->slices->build(
                    ['page' => 'besplatno', 'slug' => 'besplatno', 'title' => 'Бесплатно', 'budget' => max(self::STEPS)],
                    $city, $from, $from->copy()->addDays(6)->endOfDay(), null, Carbon::now(self::TZ), true,
                ));
                $out = [];
                foreach ($w['days'] as $day) {
                    foreach ($day['events'] as $e) {
                        $out[] = ['day' => array_diff_key($day, ['events' => true])] + $e;
                    }
                }
                foreach ($w['both'] as $e) {
                    $out[] = ['day' => null] + $e;
                }

                // добавленное в подборку руками входит и без цены: недорогим считаем только то, где цена есть
                return array_values(array_filter($out, fn (array $e) => ! $e['free'] && (int) ($e['price_min'] ?? 0) > 0));
            },
        );
    }

    /**
     * Неделя при бюджете: к бесплатному добавлено платное не дороже `budget`, в днях по порядку часа.
     *
     * @param  array<string, mixed>  $w
     * @param  list<array<string, mixed>>  $cheap
     * @return array<string, mixed>
     */
    private function spend(array $w, array $cheap, int $budget): array
    {
        $add = $this->within($w, $cheap, $budget);
        if ($add === []) {
            return $w;
        }
        foreach ($add as $e) {
            $day = $e['day'];
            unset($e['day']);
            if ($day === null) {
                $w['both'][] = $e;

                continue;
            }
            $d = array_search($day['date'], array_column($w['days'], 'date'), true);
            if ($d === false) {
                $w['days'][] = $day + ['events' => []];
                $d = array_key_last($w['days']);
            }
            $w['days'][$d]['events'][] = $e;
        }
        usort($w['days'], fn (array $a, array $b) => $a['date'] <=> $b['date']);
        foreach ($w['days'] as $d => $day) {
            // сначала события с часом по порядку, за ними без часа, как в программе
            usort($w['days'][$d]['events'], fn (array $a, array $b) => [$a['times'] === [], $a['times'][0] ?? '']
                <=> [$b['times'] === [], $b['times'][0] ?? '']);
        }
        usort($w['both'], fn (array $a, array $b) => $b['rank'] <=> $a['rank']);
        $w['total'] += count($add);
        $w['kids'] += count(array_filter($add, fn (array $e) => $e['kids']));

        return $w;
    }

    /**
     * Платное не дороже `budget`, кроме событий, которые уже стоят на неделе бесплатными под тем же названием.
     *
     * @param  array<string, mixed>  $w
     * @param  list<array<string, mixed>>  $cheap
     * @return list<array<string, mixed>>
     */
    private function within(array $w, array $cheap, int $budget): array
    {
        if ($budget === 0) {
            return [];
        }
        $taken = [];
        foreach ($this->cards($w) as $e) {
            $taken[$this->slices->sameTitle($e['title'])] = true;
        }

        return array_values(array_filter($cheap, fn (array $e) => (int) $e['price_min'] <= $budget
            && ! isset($taken[$this->slices->sameTitle($e['title'])])));
    }

    /**
     * Копилка недели: `free` — сколько бесплатных, `save` — сколько они стоили бы по обычной цене таких же
     * платных, `steps` — промежуток каждой монеты от прошлой до неё: сколько событий, сумма и самая низкая цена.
     * Сумма, а не средняя: монеты на сайте выбираются в любом сочетании, среднюю сайт считает сам.
     *
     * @param  array<string, mixed>  $w
     * @param  list<array<string, mixed>>  $cheap
     * @return array{free: int, save: array{sum: int, n: int}|null, steps: list<array{max: int, n: int, sum: int, min: int|null}>}
     */
    private function piggy(City $city, Carbon $now, array $w, array $cheap): array
    {
        $steps = [];
        $low = 0;
        foreach (array_slice(self::STEPS, 1) as $max) {
            $prices = array_values(array_filter(
                array_map(fn (array $e) => (int) $e['price_min'], $this->within($w, $cheap, $max)),
                fn (int $p) => $p > $low,
            ));
            $steps[] = ['max' => $max, 'n' => count($prices), 'sum' => array_sum($prices), 'min' => $prices === [] ? null : min($prices)];
            $low = $max;
        }

        return ['free' => count($this->cards($w)), 'save' => $this->save($city, $now, $w), 'steps' => $steps];
    }

    /**
     * @param  array<string, mixed>  $w
     * @return array{sum: int, n: int}|null null — бесплатного на неделе нет или обычные цены неизвестны
     */
    private function save(City $city, Carbon $now, array $w): ?array
    {
        $cards = $this->cards($w);
        $usual = $this->usualPrices($city, $now);
        if ($cards === [] || ! isset($usual[''])) {
            return null;
        }
        $sum = 0;
        foreach ($cards as $e) {
            $sum += $usual[$e['kind']['key']] ?? $usual[''];
        }

        return ['sum' => (int) (round($sum / 100) * 100), 'n' => count($cards)];
    }

    /**
     * Обычная цена входа по видам событий: медиана у платных в городе на полтора месяца вперёд. У вида,
     * где таких меньше трёх, цены нет, за неё берётся общая медиана под ключом ''.
     *
     * @return array<string, int>
     */
    private function usualPrices(City $city, Carbon $now): array
    {
        $day = $now->copy()->setTimezone(self::TZ)->startOfDay();

        return Cache::remember(
            'free-page:prices:v1:'.$city->slug.':'.$day->toDateString(),
            43200,
            function () use ($city, $now, $day) {
                $part = $this->page->assemble(
                    ['slug' => 'besplatno-prices', 'page' => 'besplatno-prices'],
                    $city, $now, [$day, $day->copy()->addDays(45)->endOfDay()], null, null,
                );
                $by = [];
                foreach ($part['rows'] as $r) {
                    if (! $r['free'] && (int) ($r['price_min'] ?? 0) > 0) {
                        $by[$r['kind']['key']][] = (int) $r['price_min'];
                    }
                }
                if ($by === []) {
                    return [];
                }
                $out = ['' => $this->median(array_merge(...array_values($by)))];
                foreach ($by as $kind => $prices) {
                    if (count($prices) >= 3) {
                        $out[$kind] = $this->median($prices);
                    }
                }

                return $out;
            },
        );
    }

    /** @param  non-empty-list<int>  $v */
    private function median(array $v): int
    {
        sort($v);
        $n = count($v);

        return $n % 2 ? $v[intdiv($n, 2)] : intdiv($v[$n / 2 - 1] + $v[$n / 2], 2);
    }

    /**
     * @param  array<string, mixed>  $w
     * @return list<array<string, mixed>>
     */
    private function cards(array $w): array
    {
        return [...array_merge(...array_column($w['days'], 'events')), ...$w['both']];
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
