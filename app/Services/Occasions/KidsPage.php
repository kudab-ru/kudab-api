<?php

declare(strict_types=1);

namespace App\Services\Occasions;

use App\Models\City;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Страница «С детьми»: четыре недели от сегодняшнего дня, каждую собирает SliceBuilder из того, что детское
 * по правилу фильтра ленты `kids=1` (Event::scopeWebKids). Главное выбирает сайт, в сезон рядом ёлки и каникулы.
 */
final class KidsPage
{
    private const TZ = 'Europe/Moscow';

    private const WEEKS = 4;

    // см. WeekendPage::PICKS
    private const PICKS = 8;

    private const CACHE_SECONDS = 300;

    // см. WeekendPage::STALE_SECONDS
    private const STALE_SECONDS = 3600;

    private const REL = ['эта неделя', 'следующая неделя', 'через две недели', 'через три недели'];

    /** праздники, которые целиком детские; у новогодних каникул и остальных детское только частью */
    private const HOLIDAYS = ['elki', 'kanikuly-s-detmi', '1-iyunya'];

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
        // главное прошлой недели дальше не повторяется, см. WeekendPage::show
        $used = [];
        for ($i = 0; $i < self::WEEKS; $i++) {
            $w = $this->week($city, $today->copy()->addWeeks($i)) + ['rel' => self::REL[$i]];
            $w['picks'] = array_slice(array_values(array_filter($w['picks'],
                fn (array $p) => ! isset($used[$this->slices->sameTitle($p['title'])]))), 0, self::PICKS);
            foreach ($w['picks'] as $p) {
                $used[$this->slices->sameTitle($p['title'])] = true;
            }
            $all[] = $w;
        }

        return [
            'tabs' => array_map(fn (array $w) => [
                'w' => $w['w'], 'from' => $w['from'], 'to' => $w['to'], 'label' => $w['label'], 'short' => $w['short'],
                'rel' => $w['rel'], 'days_left' => $w['days_left'], 'total' => $w['total'],
                'holiday' => $w['holiday'] === null ? null : array_diff_key($w['holiday'], ['ids' => true]),
            ], $all),
            'current' => $this->slices->withoutPicks(collect($all)->firstWhere('w', $want) ?? $all[0]),
        ];
    }

    /** @return array<string, mixed> */
    private function week(City $city, Carbon $from): array
    {
        return Cache::flexible(
            'kids-page:v4:'.$city->slug.':'.$from->toDateString(),
            [self::CACHE_SECONDS, self::STALE_SECONDS],
            function () use ($city, $from) {
                $w = ['w' => $from->toDateString()] + $this->slices->build(
                    $this->theme(), $city, $from, $from->copy()->addDays(6)->endOfDay(), $from, Carbon::now(self::TZ), true,
                );
                // здесь всё детское по правилу `kids`, кнопка «Детям» ничего бы не отбирала
                $w['kinds'] = array_values(array_filter($w['kinds'], fn (array $k) => $k['key'] !== 'for-kids'));
                $w['kids'] = $w['total'];
                // постер строке нужен для «Скоро подходит» в шапке, остальное карточка под мышью приносит сама
                $w['days'] = array_map(fn (array $d) => ['events' => array_map($this->slim(...), $d['events'])] + $d, $w['days']);
                $w['both'] = array_map($this->slim(...), $w['both']);

                return $this->lasting($w);
            },
        );
    }

    /**
     * Что идёт без часа больше одного дня (выставки, океанариум), стоит одной строкой в `both`, а не в каждом дне.
     * SliceBuilder переносит туда только открытое все дни подряд, а за неделю у музея бывает выходной.
     *
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>
     */
    private function lasting(array $w): array
    {
        $at = [];
        foreach ($w['days'] as $d => $day) {
            foreach ($day['events'] as $i => $e) {
                $at[$this->slices->sameTitle($e['title'])][] = [$d, $i];
            }
        }
        foreach ($at as $places) {
            $rows = array_map(fn (array $p) => $w['days'][$p[0]]['events'][$p[1]], $places);
            if (array_filter($rows, fn (array $r) => $r['times'] !== []) !== [] || (count($rows) < 2 && $rows[0]['until'] === null)) {
                continue;
            }
            $w['both'][] = ['until' => max([...array_column($rows, 'date'), ...array_filter(array_column($rows, 'until'))])] + $rows[0];
            foreach ($places as [$d, $i]) {
                unset($w['days'][$d]['events'][$i]);
            }
        }
        foreach ($w['days'] as $d => $day) {
            $w['days'][$d]['events'] = array_values($day['events']);
        }
        usort($w['both'], fn (array $a, array $b) => $b['rank'] <=> $a['rank']);

        return $w;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function slim(array $row): array
    {
        return array_diff_key($row, ['images' => true, 'short_description' => true]);
    }

    /** @return array<string, mixed> */
    private function theme(): array
    {
        return ['slug' => 's-detmi', 'page' => 's-detmi', 'title' => 'С детьми', 'kids' => true,
            'site_pick' => true, 'picks' => self::PICKS + 3, 'holidays' => self::HOLIDAYS,
            'question' => 'Куда сходить с детьми {city}?', 'free_question' => 'Что бесплатно?'];
    }
}
