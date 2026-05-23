<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature-тесты для venue-endpoints (PR4):
 *  - GET /api/web/venues (каталог + фильтры);
 *  - GET /api/web/venues/{id} (детальная карточка);
 *  - GET /api/web/venues/map (geojson FeatureCollection);
 *  - venue embedded в event-payload через WebEventResource.
 */
class WebVenuesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_index_filters_venues_by_city_id(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);

        $this->createVenue($vrn->id, 'Юбилейный', 'yubileinyi');
        $this->createVenue($msk->id, 'Олимпийский', 'olimpiyskii');

        $response = $this->getJson('/api/web/venues?city_id=' . $vrn->id);

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Юбилейный')
            ->assertJsonPath('data.0.city_slug', 'voronezh');
    }

    public function test_index_filters_by_name_q(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $this->createVenue($vrn->id, 'Юбилейный', 'yubileinyi');
        $this->createVenue($vrn->id, 'Зелёный театр', 'zelenyi-teatr');
        $this->createVenue($vrn->id, 'МТС Live Холл', 'mts-live-holl');

        $response = $this->getJson('/api/web/venues?city_id=' . $vrn->id . '&q=Зелён');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Зелёный театр');
    }

    public function test_show_returns_venue_with_city_relation(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Юбилейный', 'yubileinyi');

        $response = $this->getJson('/api/web/venues/' . $venue->id);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $venue->id)
            ->assertJsonPath('data.slug', 'yubileinyi')
            ->assertJsonPath('data.name', 'Юбилейный')
            ->assertJsonPath('data.city.id', $vrn->id)
            ->assertJsonPath('data.city.slug', 'voronezh');
    }

    public function test_show_returns_404_for_unknown_venue(): void
    {
        $response = $this->getJson('/api/web/venues/9999999');
        $response->assertStatus(404);
    }

    public function test_map_returns_geojson_feature_collection(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $this->createVenue($vrn->id, 'Юбилейный', 'yubileinyi', 39.1933, 51.6742);

        $response = $this->getJson('/api/web/venues/map?city_id=' . $vrn->id);

        $response
            ->assertOk()
            ->assertJsonPath('type', 'FeatureCollection')
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.type', 'Feature')
            ->assertJsonPath('features.0.geometry.type', 'Point')
            ->assertJsonPath('features.0.properties.name', 'Юбилейный');
    }

    public function test_event_embeds_venue_badge(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Юбилейный', 'yubileinyi');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $event = new Event();
        $event->community_id = $community->id;
        $event->venue_id     = $venue->id;
        $event->title        = 'Тест-концерт';
        $event->status       = 'active';
        $event->city_id      = $vrn->id;
        $event->start_time   = now()->addDays(7);
        $event->start_date   = $event->start_time->toDateString();
        $event->save();

        $response = $this->getJson('/api/web/events/' . $event->id);

        $response
            ->assertOk()
            ->assertJsonPath('data.venue.id', $venue->id)
            ->assertJsonPath('data.venue.slug', 'yubileinyi')
            ->assertJsonPath('data.venue.name', 'Юбилейный');
    }

    private function insertCity(string $name, string $slug, string $status, float $lng, float $lat): City
    {
        $now = now();
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            [$name, 'RU', $lng, $lat, $status, $slug, $now, $now]
        );
        return City::query()->where('slug', $slug)->firstOrFail();
    }

    private function createVenue(int $cityId, string $name, string $slug, float $lng = 39.0, float $lat = 51.0): Venue
    {
        DB::insert(
            'INSERT INTO venues (city_id, name, slug, status, location, created_at, updated_at)
             VALUES (?, ?, ?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?)',
            [$cityId, $name, $slug, 'active', $lng, $lat, now(), now()]
        );
        return Venue::query()->where('slug', $slug)->where('city_id', $cityId)->firstOrFail();
    }
}
