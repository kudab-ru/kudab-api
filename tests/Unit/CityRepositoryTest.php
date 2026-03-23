<?php

namespace Tests\Unit;

use App\Models\City;
use App\Repositories\CityRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CityRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_paginate_returns_only_active_cities_by_default(): void
    {
        $this->insertCity('Москва', 'moskva', 'RU', 'active', 37.6176, 55.7558);
        $this->insertCity('Санкт-Петербург', 'spb', 'RU', 'active', 30.3351, 59.9343);
        $this->insertCity('Воронеж', 'voronezh', 'RU', 'disabled', 39.2003, 51.6608);

        $repo = app(CityRepository::class);
        $page = $repo->paginate([], 50);

        $items = collect($page->items());

        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing(['moskva', 'spb'], $items->pluck('slug')->all());
        $this->assertTrue($items->every(fn (City $city) => $city->status === 'active'));
    }

    public function test_paginate_filters_by_country_and_q_case_insensitive(): void
    {
        $this->insertCity('Москва', 'moskva', 'RU', 'active', 37.6176, 55.7558);
        $this->insertCity('Минск', 'minsk', 'BY', 'active', 27.5615, 53.9045);
        $this->insertCity('Воронеж', 'voronezh', 'RU', 'active', 39.2003, 51.6608);

        $repo = app(CityRepository::class);
        $page = $repo->paginate([
            'country' => 'ru',
            'q' => 'МОСК',
        ], 50);

        $items = collect($page->items());

        $this->assertCount(1, $items);
        $this->assertSame('moskva', $items->first()->slug);
        $this->assertSame('RU', $items->first()->country_code);
    }

    public function test_paginate_with_coordinates_orders_cities_by_distance(): void
    {
        $this->insertCity('Москва', 'moskva', 'RU', 'active', 37.6176, 55.7558);
        $this->insertCity('Санкт-Петербург', 'spb', 'RU', 'active', 30.3351, 59.9343);

        $repo = app(CityRepository::class);
        $page = $repo->paginate([
            'lat' => 55.7558,
            'lon' => 37.6176,
        ], 50);

        $items = collect($page->items());

        $this->assertGreaterThanOrEqual(2, $items->count());
        $this->assertSame('moskva', $items->first()->slug);
        $this->assertNotNull($items->first()->distance_m);
        $this->assertNotNull($items->last()->distance_m);
        $this->assertLessThan($items->last()->distance_m, $items->first()->distance_m);
    }

    private function insertCity(
        string $name,
        string $slug,
        string $countryCode,
        string $status,
        float $lng,
        float $lat
    ): City {
        $now = now();

        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            [$name, $countryCode, $lng, $lat, $status, $slug, $now, $now]
        );

        return City::query()->where('slug', $slug)->firstOrFail();
    }
}
