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

    /* ============ next_event / upcoming_total (обогащение каталога) ============ */

    public function test_index_next_event_is_nearest_upcoming_and_past_excluded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-12 14:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Юбилейный', 'yubileinyi');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $this->createEvent($vrn->id, $venue->id, $community->id, 'Прошедший концерт', '2026-07-05 19:00:00');
        $later  = $this->createEvent($vrn->id, $venue->id, $community->id, 'Поздний концерт', '2026-07-20 19:00:00');
        $sooner = $this->createEvent($vrn->id, $venue->id, $community->id, 'Ближний концерт', '2026-07-14 18:00:00');

        $response = $this->getJson('/api/web/venues?city_id=' . $vrn->id);

        $response
            ->assertOk()
            ->assertJsonPath('data.0.upcoming_total', 2)
            ->assertJsonPath('data.0.next_event.id', $sooner->id)
            ->assertJsonPath('data.0.next_event.title', 'Ближний концерт')
            ->assertJsonPath('data.0.next_event.start_date', '2026-07-14');

        // start_at — ISO8601 в МСК (как start_at событий веб-ленты)
        $startAt = $response->json('data.0.next_event.start_at');
        $this->assertSame('2026-07-14T18:00:00+03:00', $startAt);
        $this->assertNotSame($later->id, $response->json('data.0.next_event.id'));
    }

    public function test_index_event_started_earlier_today_counts_as_upcoming(): void
    {
        // сейчас 14:00 МСК; событие началось сегодня в 10:00 МСК —
        // граница «предстоящего» = полночь, событие остаётся в выдаче
        Carbon::setTestNow(Carbon::parse('2026-07-12 14:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Юбилейный', 'yubileinyi');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $today = $this->createEvent($vrn->id, $venue->id, $community->id, 'Выставка сегодня', '2026-07-12 10:00:00');
        $this->createEvent($vrn->id, $venue->id, $community->id, 'Концерт завтра', '2026-07-13 19:00:00');

        $response = $this->getJson('/api/web/venues?city_id=' . $vrn->id);

        $response
            ->assertOk()
            ->assertJsonPath('data.0.upcoming_total', 2)
            ->assertJsonPath('data.0.next_event.id', $today->id)
            ->assertJsonPath('data.0.next_event.start_date', '2026-07-12');
    }

    public function test_index_web_invisible_events_excluded_from_next_event_and_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-12 14:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Юбилейный', 'yubileinyi');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        // все «невидимые» — РАНЬШЕ видимого: если бы фильтр не работал,
        // именно они стали бы next_event
        $kids = $this->createEvent($vrn->id, $venue->id, $community->id, 'Детский утренник', '2026-07-13 10:00:00');
        $kids->audience = 'kids';
        $kids->save();

        $ceremony = $this->createEvent($vrn->id, $venue->id, $community->id, 'Церемония', '2026-07-13 12:00:00');
        $ceremony->content_kind = 'official';
        $ceremony->save();

        $deleted = $this->createEvent($vrn->id, $venue->id, $community->id, 'Удалённое', '2026-07-13 13:00:00');
        $deleted->delete(); // soft-delete

        $blacklisted = $this->createEvent($vrn->id, $venue->id, $community->id, 'Из чёрного источника', '2026-07-13 14:00:00');
        $this->attachBlackSource($blacklisted->id, $community->id);

        $visible = $this->createEvent($vrn->id, $venue->id, $community->id, 'Видимый концерт', '2026-07-15 19:00:00');

        $response = $this->getJson('/api/web/venues?city_id=' . $vrn->id);

        $response
            ->assertOk()
            ->assertJsonPath('data.0.upcoming_total', 1)
            ->assertJsonPath('data.0.next_event.id', $visible->id)
            ->assertJsonPath('data.0.next_event.title', 'Видимый концерт');
    }

    public function test_index_venue_without_events_has_null_next_event_and_zero_total(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $this->createVenue($vrn->id, 'Пустая площадка', 'pustaya');

        $response = $this->getJson('/api/web/venues?city_id=' . $vrn->id);

        $response
            ->assertOk()
            ->assertJsonPath('data.0.upcoming_total', 0)
            ->assertJsonPath('data.0.next_event', null);
    }

    public function test_index_upcoming_enrichment_is_batched_no_n_plus_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-12 14:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $small = $this->createVenue($vrn->id, 'Одиночка', 'odinochka');
        $this->createEvent($vrn->id, $small->id, $community->id, 'Событие 0', '2026-07-14 19:00:00');

        // страница из 1 площадки vs страница из 6 — число SQL-запросов
        // должно совпасть (enrichment батчевый, не по площадке)
        $this->getJson('/api/web/venues?city_id=' . $vrn->id); // прогрев

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/web/venues?city_id=' . $vrn->id)->assertOk();
        $queriesOneVenue = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 1; $i <= 5; $i++) {
            $v = $this->createVenue($vrn->id, 'Площадка ' . $i, 'ploschadka-' . $i);
            $this->createEvent($vrn->id, $v->id, $community->id, 'Событие ' . $i, '2026-07-1' . (3 + ($i % 5)) . ' 19:00:00');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/web/venues?city_id=' . $vrn->id);
        $queriesSixVenues = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()->assertJsonCount(6, 'data');
        $this->assertSame(
            $queriesOneVenue,
            $queriesSixVenues,
            "Число запросов растёт с числом площадок ({$queriesOneVenue} → {$queriesSixVenues}): enrichment не батчевый (N+1)"
        );
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

    /** Событие на площадке; $startTimeMsk — 'Y-m-d H:i:s' в Europe/Moscow. */
    private function createEvent(int $cityId, int $venueId, int $communityId, string $title, string $startTimeMsk): Event
    {
        $start = Carbon::parse($startTimeMsk, 'Europe/Moscow');

        $event = new Event();
        $event->community_id = $communityId;
        $event->venue_id     = $venueId;
        $event->title        = $title;
        $event->status       = 'active';
        $event->city_id      = $cityId;
        // start_time хранится как UTC-инстант (паритет с парсером/прод-БД: 18:00 МСК →
        // 15:00 UTC). Без ->utc() Eloquent при UTC-сессии кладёт МСК-настенное как UTC
        // (+3ч) → start_at в next_event/ленте уезжает; ассерт 18:00+03:00 корректен.
        $event->start_time   = $start->copy()->utc();
        $event->start_date   = $start->toDateString();
        $event->save();

        return $event;
    }

    /**
     * Единственный source события — с black-ссылкой ⇒ событие скрыто
     * blacklist-гейтом веб-выдачи (Event::scopeWebNotBlacklisted).
     * Вставки через DB::table — мимо Eloquent-хука EventSource::creating
     * (он тянет SocialMediaApiFactory, тесту не нужен).
     */
    private function attachBlackSource(int $eventId, int $communityId): void
    {
        $now = now();

        $snId = DB::table('social_networks')->insertGetId([
            'name'       => 'vk',
            'slug'       => 'vk-test-' . $eventId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $linkId = DB::table('community_social_links')->insertGetId([
            'community_id'      => $communityId,
            'social_network_id' => $snId,
            'url'               => 'https://vk.com/black_' . $eventId,
            'status'            => 'black',
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        DB::table('event_sources')->insert([
            'event_id'         => $eventId,
            'social_link_id'   => $linkId,
            'source'           => 'vk',
            'post_external_id' => 'black-post-' . $eventId,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }
}
