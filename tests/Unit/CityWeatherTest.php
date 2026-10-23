<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\City;
use App\Services\Weather\CityWeather;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CityWeatherTest extends TestCase
{
    private const SAT = '2026-10-24';

    private const SUN = '2026-10-25';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-23 15:00', 'Europe/Moscow'));
    }

    #[Test]
    public function part_of_day_takes_wettest_sky_and_reads_day_by_hour(): void
    {
        Http::fake(['api.met.no/*' => Http::response($this->forecast())]);

        $days = collect($this->weekend()['days'])->keyBy('date');

        // в 16 и 17 часов источник уже ставит ночные значки, но это ещё день
        $this->assertSame('partly', $days[self::SAT]['parts']['day']['sky']);
        $this->assertSame('clear-night', $days[self::SAT]['parts']['evening']['sky']);
        $this->assertSame('rain', $days[self::SUN]['parts']['day']['sky']);
        $this->assertSame(14, $days[self::SUN]['parts']['day']['t']);
    }

    #[Test]
    public function last_good_forecast_stays_when_source_stops_answering(): void
    {
        $this->withoutDefer();
        Http::fake(['api.met.no/*' => Http::sequence()->push($this->forecast())->whenEmpty(Http::response('', 500))]);

        $this->assertNotEmpty($this->weekend()['days']);
        $this->travel(11)->minutes();
        $this->weekend();
        $this->travel(11)->minutes();

        $this->assertNotEmpty($this->weekend()['days'] ?? []);
    }

    /** @return array<string, mixed>|null */
    private function weekend(): ?array
    {
        $city = (new City)->forceFill(['slug' => 'voronezh', 'latitude' => 51.66, 'longitude' => 39.2]);

        return app(CityWeather::class)->forDays(
            $city,
            Carbon::parse(self::SAT, 'Europe/Moscow'),
            Carbon::parse(self::SUN, 'Europe/Moscow')->endOfDay(),
            Carbon::now(),
        );
    }

    /** @return array<string, mixed> */
    private function forecast(): array
    {
        $rows = [
            [self::SAT.' 13:00', 11.6, 'fair_day'],
            [self::SAT.' 16:00', 10.2, 'partlycloudy_night'],
            [self::SAT.' 17:00', 9.4, 'clearsky_night'],
            [self::SAT.' 20:00', 7.1, 'clearsky_night'],
            [self::SUN.' 13:00', 12.8, 'rain'],
            [self::SUN.' 15:00', 14.2, 'cloudy'],
            [self::SUN.' 17:00', 11.9, 'fair_day'],
        ];

        return ['properties' => ['timeseries' => array_map(fn (array $r) => [
            'time' => Carbon::parse($r[0], 'Europe/Moscow')->utc()->toIso8601ZuluString(),
            'data' => [
                'instant' => ['details' => ['air_temperature' => $r[1]]],
                'next_1_hours' => ['summary' => ['symbol_code' => $r[2]]],
            ],
        ], $rows)]];
    }
}
