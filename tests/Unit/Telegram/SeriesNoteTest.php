<?php

namespace Tests\Unit\Telegram;

use App\Support\Telegram\SeriesNote;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SeriesNoteTest extends TestCase
{
    #[DataProvider('series')]
    public function test_post_names_the_series_like_the_event_page(array $group, string $asOf, ?string $expected): void
    {
        $now = CarbonImmutable::parse('2026-10-06 10:00', 'Europe/Moscow');

        $this->assertSame($expected, SeriesNote::of($group, CarbonImmutable::parse($asOf, 'Europe/Moscow'), $now));
    }

    public static function series(): array
    {
        $today = '2026-10-06 10:00';

        return [
            'вт–вс без понедельника, по нескольку сеансов' => [
                self::group(['2026-10-06' => 1, '2026-10-07' => 1, '2026-10-08' => 2, '2026-10-09' => 2, '2026-10-10' => 3,
                    '2026-10-11' => 1, '2026-10-13' => 1, '2026-10-14' => 1, '2026-10-15' => 1]),
                $today, '9 дней до 15 октября, всего 13 сеансов',
            ],
            'каждый день' => [
                self::group(['2026-10-06' => 1, '2026-10-07' => 1, '2026-10-08' => 1, '2026-10-09' => 1, '2026-10-10' => 1, '2026-10-11' => 1]),
                $today, 'каждый день до 11 октября',
            ],
            'три дня подряд' => [
                self::group(['2026-10-06' => 1, '2026-10-07' => 1, '2026-10-08' => 1]),
                $today, '3 дня подряд, до 8 октября',
            ],
            'два дня в одном месяце' => [self::group(['2026-10-06' => 1, '2026-10-07' => 1]), $today, 'два дня подряд, 6–7 октября'],
            'два дня на стыке месяцев' => [
                self::group(['2026-10-31' => 1, '2026-11-01' => 1]),
                $today, 'два дня подряд, 31 октября и 1 ноября',
            ],
            'показы вразброс' => [
                self::group(['2026-10-06' => 1, '2026-10-20' => 1, '2026-11-15' => 1]),
                $today, '3 показа с 6 октября по 15 ноября',
            ],
            'один показ' => [self::group(['2026-10-06' => 1]), $today, null],
            'квест с расписанием на день вперёд' => [
                self::group(['2026-10-06' => 1], kind: 'daily', count: 6),
                $today, 'идёт каждый день',
            ],
            'пост выходит послезавтра' => [
                self::group(['2026-10-06' => 1, '2026-10-07' => 1, '2026-10-08' => 1, '2026-10-09' => 1, '2026-10-10' => 1, '2026-10-11' => 1]),
                '2026-10-08 10:00', '4 дня подряд, до 11 октября',
            ],
            'выставка идёт дольше выложенных сеансов' => [
                ['until' => '2026-12-06'] + self::group(['2026-10-06' => 1, '2026-10-07' => 2, '2026-10-08' => 1]),
                $today, 'идёт до 6 декабря',
            ],
            'сервер прислал не все дни' => [
                self::group(array_fill_keys(array_map(fn ($d) => sprintf('2026-10-%02d', $d), range(6, 17)), 1), daysCount: 20, lastDay: '2026-10-25'),
                $today, 'каждый день до 25 октября',
            ],
        ];
    }

    /** @param  array<string, int>  $perDay  день => сеансов */
    private static function group(array $perDay, ?string $kind = null, ?int $count = null, ?int $daysCount = null, ?string $lastDay = null): array
    {
        $dates = [];
        foreach ($perDay as $day => $n) {
            $dates[] = ['start_date' => $day] + ($n > 1 ? ['day_count' => $n] : []);
        }

        return [
            'dates' => $dates,
            'count' => $count ?? array_sum($perDay),
            'days_count' => $daysCount ?? count($perDay),
            'last_day' => $lastDay ?? array_key_last($perDay),
            'series_kind' => $kind,
        ];
    }
}
