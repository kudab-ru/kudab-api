<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Rink;
use App\Services\Rinks\RinkDays;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RinkDaysTest extends TestCase
{
    private const WED = '2027-01-13';

    private function rink(array $over = []): Rink
    {
        return new Rink($over + [
            'indoor' => false,
            'ice' => 'natural',
            'status' => 'open',
            'schedule' => [['days' => [1, 2, 3, 4, 5, 6, 7], 'label' => 'каждый день', 'times' => ['10:00–21:30']]],
        ]);
    }

    public static function thaw(): array
    {
        return [
            'натуральный лёд' => ['natural', false, 'лёд натуральный, в оттепель может не работать'],
            'лёд неизвестен' => [null, false, 'в оттепель может не работать'],
            'искусственный лёд' => ['artificial', false, null],
            'крытый' => ['natural', true, null],
        ];
    }

    #[Test]
    #[DataProvider('thaw')]
    public function thaw_warns_but_never_closes_a_rink(?string $ice, bool $indoor, ?string $caution): void
    {
        $day = RinkDays::day($this->rink(['ice' => $ice, 'indoor' => $indoor]), CarbonImmutable::parse(self::WED), 4);

        $this->assertTrue($day['open']);
        $this->assertSame($caution, $day['caution']);
    }

    #[Test]
    public function only_the_rinks_own_words_close_it(): void
    {
        $day = RinkDays::day($this->rink(['status' => 'closed', 'status_note' => 'закрыт до морозов']), CarbonImmutable::parse(self::WED), -5);

        $this->assertFalse($day['open']);
        $this->assertTrue($day['closed']);
        $this->assertSame('закрыт до морозов', $day['why']);
    }

    #[Test]
    public function dated_sessions_beat_the_week_and_a_closed_day_beats_both(): void
    {
        $rink = $this->rink(['closures' => [['date' => '2027-01-14', 'note' => 'ледовое шоу, катания нет']]]);
        $dated = [
            self::WED => [['t' => '17:45–18:45', 'url' => 'https://example.test/s1']],
            '2027-01-14' => [['t' => '12:00–13:00', 'url' => null]],
        ];

        $this->assertSame($dated[self::WED], RinkDays::day($rink, CarbonImmutable::parse(self::WED), null, $dated)['slots']);
        $closed = RinkDays::day($rink, CarbonImmutable::parse('2027-01-14'), null, $dated);
        $this->assertFalse($closed['open']);
        $this->assertSame('ледовое шоу, катания нет', $closed['why']);
    }

    #[Test]
    public function a_weekday_holiday_goes_by_sunday_with_a_note_until_its_hours_are_known(): void
    {
        $rink = $this->rink(['indoor' => true, 'schedule' => [
            ['days' => [1, 2, 3, 4, 5], 'label' => 'пн–пт', 'times' => ['13:00–14:00']],
            ['days' => [7], 'label' => 'вс', 'times' => ['15:00–16:00']],
        ]]);
        $tuesday = CarbonImmutable::parse('2027-01-05');

        $guess = RinkDays::day($rink, $tuesday, null);
        $this->assertSame([['t' => '15:00–16:00', 'url' => null]], $guess['slots']);
        $this->assertSame(RinkDays::HOLIDAY_NOTE, $guess['holiday']);

        $set = RinkDays::day($rink->fill(['sessions' => [['date' => '2027-01-05', 'times' => ['12:00–13:00']]]]), $tuesday, null);
        $this->assertSame([['t' => '12:00–13:00', 'url' => null]], $set['slots']);
        $this->assertNull($set['holiday']);
    }

    #[Test]
    public function next_session_skips_days_without_skating(): void
    {
        $rink = $this->rink(['indoor' => true, 'schedule' => [['days' => [6], 'label' => 'сб', 'times' => ['17:45–18:45']]]]);

        $this->assertSame(['date' => '2027-01-16', 'time' => '17:45'], RinkDays::next($rink, CarbonImmutable::parse(self::WED)));
    }
}
