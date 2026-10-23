<?php

declare(strict_types=1);

namespace App\Services\Occasions;

use App\Models\City;
use App\Support\WeekendWindow;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Страница «На выходных»: четверо ближайших выходных, каждые собирает SliceBuilder. Главное — подборка
 * канала «На выходных» на эти дни или её же автоотбор.
 */
final class WeekendPage
{
    private const TZ = 'Europe/Moscow';

    private const WEEKS = 4;

    private const PICKS = 5;

    private const CACHE_SECONDS = 300;

    private const REL = ['эти выходные', 'следующие выходные', 'через две недели', 'через три недели'];

    public function __construct(private readonly SliceBuilder $slices) {}

    /**
     * Сводка всех выходных для вкладок и одни из них целиком: `want` — дата их субботы, иначе ближайшие.
     *
     * @return array{tabs: list<array<string, mixed>>, current: array<string, mixed>}
     */
    public function show(City $city, Carbon $now, ?string $want): array
    {
        $all = [];
        // главное прошлых выходных дальше не повторяется: иначе спектакль, который идёт дважды в месяц, стоял бы главным каждый раз
        $used = [];
        foreach ($this->windows($now) as $i => [$from, $to]) {
            $w = $this->weekend($city, $from, $to) + ['rel' => self::REL[$i]];
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

    /** @return list<array{0: Carbon, 1: Carbon}> */
    private function windows(Carbon $now): array
    {
        [$from, $to] = WeekendWindow::for($now->copy()->setTimezone(self::TZ));
        $out = [[$from, $to]];
        // в воскресенье ближайшие выходные — один день, следующие считаются от их субботы
        $sat = $from->isSunday() ? $from->copy()->subDay() : $from->copy();
        for ($i = 1; $i < self::WEEKS; $i++) {
            $s = $sat->copy()->addWeeks($i);
            $out[] = [$s->copy()->startOfDay(), $s->copy()->addDay()->endOfDay()];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function weekend(City $city, Carbon $from, Carbon $to): array
    {
        $sat = $from->isSunday() ? $from->copy()->subDay() : $from->copy();

        return Cache::remember(
            'weekend-page:v2:'.$city->slug.':'.$from->toDateString().':'.$to->toDateString(),
            self::CACHE_SECONDS,
            fn () => ['w' => $sat->toDateString()]
                + $this->slices->build($this->theme(), $city, $from, $to, $sat, Carbon::now(self::TZ), false),
        );
    }

    /** Рубрика канала «На выходных»: её отбор главного, её окно и её слаг, по которому ищется её пост. */
    private function theme(): array
    {
        $rubric = (array) collect((array) config('broadcast_digest.themes', []))->firstWhere('slug', 'na-vyhodnyh');

        // пост ищется в неделю этих выходных; без поста главное собирает сайт своими правилами, см. OccasionPage::sitePick
        return ['page' => 'na-vyhodnyh', 'site_pick' => true, 'picks' => self::PICKS + 3]
            + $rubric
            + ['slug' => 'na-vyhodnyh', 'title' => 'На выходных', 'window' => 'weekend', 'pick' => 'all'];
    }
}
