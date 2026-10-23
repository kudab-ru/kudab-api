<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Models\City;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Погода для страниц подборок от Норвежского метеорологического института (MET Norway): бесплатно и для
 * коммерческого сайта, с подписью источника на странице. У Яндекс Погоды бесплатного тарифа для сайта нет.
 */
final class CityWeather
{
    private const TZ = 'Europe/Moscow';

    private const URL = 'https://api.met.no/weatherapi/locationforecast/2.0/compact';

    /** без названия сайта в заголовке сервис отвечает отказом */
    private const AGENT = 'kudab.ru (+https://kudab.ru)';

    /** через столько прогноз обновляется, уже после ответа страницы: посетитель источник не ждёт */
    private const FRESH_SECONDS = 600;

    /** столько служит последний удачный прогноз, если источник не отвечает */
    private const STALE_SECONDS = 6 * 3600;

    /** часть дня → с какого часа по какой; температура и небо берутся внутри этих часов */
    private const PARTS = ['morning' => [6, 12], 'day' => [12, 18], 'evening' => [18, 24]];

    /**
     * Дни с `from` по `to` и погода сейчас. Дня в ответе нет, если прогноз до него не достаёт (около девяти
     * дней вперёд); null — источник не ответил, страница обходится без погоды.
     *
     * @return array{now: array{t: int, sky: string}|null, days: list<array<string, mixed>>}|null
     */
    public function forDays(City $city, Carbon $from, Carbon $to, Carbon $now): ?array
    {
        $hours = $this->hours($city);
        if ($hours === null) {
            return null;
        }

        $days = [];
        for ($d = $from->copy()->setTimezone(self::TZ)->startOfDay(); $d->lte($to); $d->addDay()) {
            $day = $hours[$d->toDateString()] ?? [];
            if ($day === []) {
                continue;
            }
            $parts = [];
            foreach (self::PARTS as $key => [$a, $b]) {
                $parts[$key] = $this->part($day, $a, $b);
            }
            $temps = array_column($day, 't');
            $main = $parts['day'] ?? $parts['morning'] ?? $parts['evening'];
            $days[] = [
                'date' => $d->toDateString(),
                't_max' => (int) round(max($temps)),
                't_min' => (int) round(min($temps)),
                'sky' => $main['sky'] ?? $this->sky((string) ($day[array_key_first($day)]['sym'] ?? 'cloudy'), true),
                'parts' => $parts,
            ];
        }

        return ['now' => $this->now($hours, $now), 'days' => $days];
    }

    /**
     * Прогноз по часам московского времени: дата → час → температура и небо на ближайшие часы.
     * Первые дни источник даёт по часам, дальние — раз в шесть часов.
     *
     * @return array<string, array<int, array{t: float, sym: string|null}>>|null
     */
    private function hours(City $city): ?array
    {
        $key = 'city-weather:v2:'.$city->slug;
        $got = Cache::flexible($key, [self::FRESH_SECONDS, self::STALE_SECONDS], function () use ($city, $key) {
            $hours = $this->fetch($city);
            if ($hours !== null) {
                Cache::put("{$key}:good", $hours, self::STALE_SECONDS);
            }

            return ['hours' => $hours ?? Cache::get("{$key}:good")];
        });

        return $got['hours'] ?? null;
    }

    /**
     * @return array<string, array<int, array{t: float, sym: string|null}>>|null
     */
    private function fetch(City $city): ?array
    {
        try {
            $res = Http::connectTimeout(2)
                ->timeout(5)
                ->withHeaders(['User-Agent' => self::AGENT])
                ->get(self::URL, ['lat' => round((float) $city->latitude, 2), 'lon' => round((float) $city->longitude, 2)]);
        } catch (Throwable) {
            return null;
        }
        if (! $res->ok()) {
            return null;
        }

        $hours = [];
        foreach ((array) $res->json('properties.timeseries', []) as $row) {
            $at = Carbon::parse((string) $row['time'])->setTimezone(self::TZ);
            $data = (array) ($row['data'] ?? []);
            $temp = $data['instant']['details']['air_temperature'] ?? null;
            if ($temp === null) {
                continue;
            }
            $hours[$at->toDateString()][$at->hour] = [
                't' => (float) $temp,
                'sym' => $data['next_1_hours']['summary']['symbol_code']
                    ?? $data['next_6_hours']['summary']['symbol_code']
                    ?? $data['next_12_hours']['summary']['symbol_code'] ?? null,
            ];
        }

        return $hours;
    }

    /**
     * Часть дня: температура в её середине, небо — самое «мокрое» за эти часы: дождь вечером важнее ясного начала.
     *
     * @param  array<int, array{t: float, sym: string|null}>  $day
     * @return array{t: int, sky: string}|null
     */
    private function part(array $day, int $from, int $to): ?array
    {
        $inside = array_filter($day, fn (int $h) => $h >= $from && $h < $to, ARRAY_FILTER_USE_KEY);
        if ($inside === []) {
            return null;
        }
        $mid = ($from + $to) / 2;
        $near = array_keys($inside);
        usort($near, fn (int $a, int $b) => abs($a - $mid) <=> abs($b - $mid));
        $skies = array_map(fn (array $h) => $this->sky((string) $h['sym'], $from < 18), array_filter($inside, fn (array $h) => $h['sym'] !== null));

        return [
            't' => (int) round($inside[$near[0]]['t']),
            'sky' => $skies === [] ? 'cloudy' : $this->wettest($skies),
        ];
    }

    /**
     * @param  array<string, array<int, array{t: float, sym: string|null}>>  $hours
     * @return array{t: int, sky: string}|null
     */
    private function now(array $hours, Carbon $now): ?array
    {
        $at = $now->copy()->setTimezone(self::TZ);
        $h = $hours[$at->toDateString()][$at->hour] ?? null;
        if ($h === null) {
            return null;
        }

        return ['t' => (int) round($h['t']), 'sky' => $this->sky((string) $h['sym'], $at->hour >= 7 && $at->hour < 18)];
    }

    /**
     * Код неба сайта из кода MET Norway. День или ночь решает час: у источника «ночь» значит, что промежуток
     * кончается после заката, и дневные часы осенью получали бы луну.
     */
    private function sky(string $code, bool $daytime): string
    {
        $c = strtolower($code);
        $night = ! $daytime;

        return match (true) {
            str_contains($c, 'thunder') => 'storm',
            str_contains($c, 'snow') => 'snow',
            str_contains($c, 'sleet') => 'sleet',
            str_contains($c, 'rain') => str_starts_with($c, 'light') ? 'drizzle' : 'rain',
            str_contains($c, 'fog') => 'fog',
            str_starts_with($c, 'clearsky') || str_starts_with($c, 'fair') => $night ? 'clear-night' : 'clear',
            str_starts_with($c, 'partlycloudy') => $night ? 'partly-night' : 'partly',
            default => 'cloudy',
        };
    }

    /** @param  list<string>|array<int, string>  $skies */
    private function wettest(array $skies): string
    {
        $order = ['storm', 'snow', 'sleet', 'rain', 'drizzle', 'fog', 'cloudy', 'partly', 'partly-night', 'clear', 'clear-night'];
        usort($skies, fn (string $a, string $b) => array_search($a, $order, true) <=> array_search($b, $order, true));

        return $skies[0];
    }
}
