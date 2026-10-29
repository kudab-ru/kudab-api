<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\City;
use App\Models\Rink;
use App\Services\Rinks\RinkPage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RinkTeaserTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_teaser_counts_rinks_open_on_any_day_of_the_range_and_only_in_winter(): void
    {
        // у города обязательна точка на карте, через `City::create` её не задать
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, now(), now())',
            ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh'],
        );
        $city = City::query()->where('slug', 'voronezh')->firstOrFail();
        foreach (['sat' => 6, 'sun' => 7, 'mon' => 1] as $slug => $day) {
            Rink::query()->create([
                'city_id' => $city->id, 'slug' => $slug, 'name' => $slug, 'indoor' => true, 'status' => 'open',
                'schedule' => [['days' => [$day], 'label' => '', 'times' => ['12:00–13:00']]],
                'prices' => ['entry' => $day * 100],
            ]);
        }
        $page = app(RinkPage::class);

        $sat = CarbonImmutable::parse('2027-01-16', 'Europe/Moscow');
        $this->assertSame(['count' => 3, 'open' => 2, 'entry_from' => 100], $page->teaser($city, $sat, $sat->addDay()));
        $this->assertNull($page->teaser($city, CarbonImmutable::parse('2026-11-14'), CarbonImmutable::parse('2026-11-15')));
    }
}
