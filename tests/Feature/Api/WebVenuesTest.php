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

    /* ============ genre_profile («Здесь бывает») ============ */

    public function test_show_genre_profile_ranks_and_gates_by_count(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Зелёный театр', 'zelenyi-teatr');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $music   = $this->createInterest('music', 'Музыка');
        $standup = $this->createInterest('standup', 'Стендап и юмор');
        $theatre = $this->createInterest('theatre', 'Театр и спектакли');
        $cinema  = $this->createInterest('cinema', 'Кино и показы');
        $lecture = $this->createInterest('lecture', 'Образование и лекции');

        // denom = 16 различных тегированных событий. Строго убывающие счётчики,
        // чтобы порядок был детерминирован. cinema/lecture (по 2, доля 12%) —
        // ниже гейта, в профиль не попадают.
        $this->makeTaggedEvents($vrn->id, $venue->id, $community->id, $music, 5);
        $this->makeTaggedEvents($vrn->id, $venue->id, $community->id, $standup, 4);
        $this->makeTaggedEvents($vrn->id, $venue->id, $community->id, $theatre, 3);
        $this->makeTaggedEvents($vrn->id, $venue->id, $community->id, $cinema, 2);
        $this->makeTaggedEvents($vrn->id, $venue->id, $community->id, $lecture, 2);

        $response = $this->getJson('/api/web/venues/' . $venue->id);

        $response
            ->assertOk()
            ->assertJsonCount(3, 'data.genre_profile')
            ->assertJsonPath('data.genre_profile.0.slug', 'music')
            ->assertJsonPath('data.genre_profile.0.name', 'Музыка')
            ->assertJsonPath('data.genre_profile.0.count', 5)
            ->assertJsonPath('data.genre_profile.1.slug', 'standup')
            ->assertJsonPath('data.genre_profile.2.slug', 'theatre');
    }

    public function test_show_genre_profile_share_branch_keeps_dominant_two_event_genre(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Клуб 12', 'klub-12');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $music   = $this->createInterest('music', 'Музыка');
        $standup = $this->createInterest('standup', 'Стендап и юмор');
        $theatre = $this->createInterest('theatre', 'Театр и спектакли');

        // denom = 4. music = 2/4 = 50% (доминирует при 2 событиях → проходит).
        // standup/theatre по 1 — ниже гейта.
        $this->makeTaggedEvents($vrn->id, $venue->id, $community->id, $music, 2);
        $this->makeTaggedEvents($vrn->id, $venue->id, $community->id, $standup, 1);
        $this->makeTaggedEvents($vrn->id, $venue->id, $community->id, $theatre, 1);

        $response = $this->getJson('/api/web/venues/' . $venue->id);

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.genre_profile')
            ->assertJsonPath('data.genre_profile.0.slug', 'music');
    }

    public function test_show_genre_profile_empty_for_thin_venue(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Тихая площадка', 'tihaya');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        // всего 2 тегированных события (< 3) → профиля нет, блок скрыт
        $music   = $this->createInterest('music', 'Музыка');
        $this->makeTaggedEvents($vrn->id, $venue->id, $community->id, $music, 2);

        $response = $this->getJson('/api/web/venues/' . $venue->id);

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data.genre_profile');
    }

    public function test_show_genre_profile_excludes_web_invisible_events(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Юбилейный', 'yubileinyi');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $music   = $this->createInterest('music', 'Музыка');
        $theatre = $this->createInterest('theatre', 'Театр и спектакли');
        $standup = $this->createInterest('standup', 'Стендап и юмор');

        // 3 видимых музыкальных — единственное, что должно попасть в профиль
        $this->makeTaggedEvents($vrn->id, $venue->id, $community->id, $music, 3);

        // невидимые теги театра/стендапа — не должны считаться
        $deleted = $this->createEvent($vrn->id, $venue->id, $community->id, 'Удалённый спектакль', '2026-07-13 13:00:00');
        $this->tagEvent($deleted->id, $theatre);
        $deleted->delete();

        $blacklisted = $this->createEvent($vrn->id, $venue->id, $community->id, 'Спектакль из чёрного', '2026-07-13 14:00:00');
        $this->tagEvent($blacklisted->id, $theatre);
        $this->attachBlackSource($blacklisted->id, $community->id);

        $kids = $this->createEvent($vrn->id, $venue->id, $community->id, 'Детский стендап', '2026-07-13 10:00:00');
        $kids->audience = 'kids';
        $kids->save();
        $this->tagEvent($kids->id, $standup);

        $response = $this->getJson('/api/web/venues/' . $venue->id);

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.genre_profile')
            ->assertJsonPath('data.genre_profile.0.slug', 'music')
            ->assertJsonPath('data.genre_profile.0.count', 3);
    }

    /* ============ calendar (карта дат для месяц-сетки) ============ */

    public function test_calendar_returns_date_count_map_within_lookback_window(): void
    {
        // окно ленты = [now - 7 дней, будущее]; событие старше недели отсекается,
        // иначе клик по его дню вернул бы пусто (лента режет прошлое)
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Зелёный театр', 'zelenyi-teatr');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $this->createEvent($vrn->id, $venue->id, $community->id, 'Недавний', '2026-07-10 19:00:00'); // в окне (5 дней назад)
        $this->createEvent($vrn->id, $venue->id, $community->id, 'Концерт 1', '2026-07-14 19:00:00');
        $this->createEvent($vrn->id, $venue->id, $community->id, 'Концерт 2', '2026-07-14 21:00:00');
        $this->createEvent($vrn->id, $venue->id, $community->id, 'Спектакль', '2026-07-20 18:00:00');
        $this->createEvent($vrn->id, $venue->id, $community->id, 'Старый', '2026-05-01 12:00:00'); // старше недели → вне окна

        $response = $this->getJson('/api/web/venues/' . $venue->id . '/calendar');

        $response->assertOk()->assertExactJson([
            'data' => [
                '2026-07-10' => 1,
                '2026-07-14' => 2,
                '2026-07-20' => 1,
            ],
        ]);
    }

    public function test_calendar_excludes_web_invisible_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 12:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Юбилейный', 'yubileinyi');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        // 2 видимых события в один день — единственное, что должно попасть
        $this->createEvent($vrn->id, $venue->id, $community->id, 'Видимый 1', '2026-07-14 19:00:00');
        $this->createEvent($vrn->id, $venue->id, $community->id, 'Видимый 2', '2026-07-14 21:00:00');

        $deleted = $this->createEvent($vrn->id, $venue->id, $community->id, 'Удалённый', '2026-07-15 19:00:00');
        $deleted->delete();

        $kids = $this->createEvent($vrn->id, $venue->id, $community->id, 'Детский', '2026-07-16 10:00:00');
        $kids->audience = 'kids';
        $kids->save();

        $blacklisted = $this->createEvent($vrn->id, $venue->id, $community->id, 'Из чёрного', '2026-07-17 14:00:00');
        $this->attachBlackSource($blacklisted->id, $community->id);

        $response = $this->getJson('/api/web/venues/' . $venue->id . '/calendar');

        $response->assertOk()->assertExactJson([
            'data' => ['2026-07-14' => 2],
        ]);
    }

    public function test_calendar_returns_404_for_unknown_venue(): void
    {
        $response = $this->getJson('/api/web/venues/9999999/calendar');
        $response->assertStatus(404);
    }

    /* ============ past-events («Здесь уже проходило», all-time в обход lookback) ============ */

    public function test_past_events_surfaces_old_events_and_hydrates_poster(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Музей Крамского', 'muzey-kramskogo');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        // событие 30 дней назад — старше окна lookback(7д): в ленте бы не показалось
        $old = $this->createEvent($vrn->id, $venue->id, $community->id, 'Прошлая выставка',
            Carbon::now('Europe/Moscow')->subDays(30)->format('Y-m-d H:i:s'));
        $this->attachSourceWithImages($old->id, $community->id, ['https://img/poster.jpg']);

        $response = $this->getJson('/api/web/venues/' . $venue->id . '/past-events');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Прошлая выставка')
            ->assertJsonPath('data.0.is_past', true)
            ->assertJsonPath('data.0.poster', 'https://img/poster.jpg');
    }

    public function test_past_events_isolated_from_main_feed(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Музей', 'muzey');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $old = $this->createEvent($vrn->id, $venue->id, $community->id, 'Старое событие',
            Carbon::now('Europe/Moscow')->subDays(30)->format('Y-m-d H:i:s'));

        // в главной ленте (окно 7д) старого события НЕТ
        $feed = $this->getJson('/api/web/events?venue_id=' . $venue->id);
        $feed->assertOk();
        $this->assertNotContains($old->id, collect($feed->json('data'))->pluck('id')->all());

        // в past-events — ЕСТЬ (обход lookback изолирован от ленты)
        $this->getJson('/api/web/venues/' . $venue->id . '/past-events')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $old->id);
    }

    public function test_past_events_excludes_web_invisible(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Музей', 'muzey');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $visible = $this->createEvent($vrn->id, $venue->id, $community->id, 'Видимое прошлое',
            Carbon::now('Europe/Moscow')->subDays(30)->format('Y-m-d H:i:s'));

        $deleted = $this->createEvent($vrn->id, $venue->id, $community->id, 'Удалённое',
            Carbon::now('Europe/Moscow')->subDays(31)->format('Y-m-d H:i:s'));
        $deleted->delete();

        $kids = $this->createEvent($vrn->id, $venue->id, $community->id, 'Детское',
            Carbon::now('Europe/Moscow')->subDays(32)->format('Y-m-d H:i:s'));
        $kids->audience = 'kids';
        $kids->save();

        $black = $this->createEvent($vrn->id, $venue->id, $community->id, 'Из чёрного',
            Carbon::now('Europe/Moscow')->subDays(33)->format('Y-m-d H:i:s'));
        $this->attachBlackSource($black->id, $community->id);

        $this->getJson('/api/web/venues/' . $venue->id . '/past-events')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_past_events_ordered_recent_first(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Музей', 'muzey');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $mid    = $this->createEvent($vrn->id, $venue->id, $community->id, 'Середина',
            Carbon::now('Europe/Moscow')->subDays(20)->format('Y-m-d H:i:s'));
        $recent = $this->createEvent($vrn->id, $venue->id, $community->id, 'Недавнее',
            Carbon::now('Europe/Moscow')->subDays(5)->format('Y-m-d H:i:s'));
        $old    = $this->createEvent($vrn->id, $venue->id, $community->id, 'Давнее',
            Carbon::now('Europe/Moscow')->subDays(40)->format('Y-m-d H:i:s'));

        $ids = collect($this->getJson('/api/web/venues/' . $venue->id . '/past-events')->json('data'))
            ->pluck('id')->all();

        $this->assertSame([$recent->id, $mid->id, $old->id], $ids);
    }

    public function test_past_events_empty_when_only_future(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Активная', 'aktivnaya');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $this->createEvent($vrn->id, $venue->id, $community->id, 'Будущее',
            Carbon::now('Europe/Moscow')->addDays(7)->format('Y-m-d H:i:s'));

        $this->getJson('/api/web/venues/' . $venue->id . '/past-events')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_past_events_active_venue_in_inactive_city_is_empty(): void
    {
        $off = $this->insertCity('Спящий', 'spyashiy', 'inactive', 39.0, 51.0);
        $venue = $this->createVenue($off->id, 'Площадка в спящем', 'v-spyashem');
        $community = Community::create(['name' => 'Тест', 'city_id' => $off->id]);

        $this->createEvent($off->id, $venue->id, $community->id, 'Прошлое в спящем',
            Carbon::now('Europe/Moscow')->subDays(20)->format('Y-m-d H:i:s'));

        // площадка active → не 404, но город inactive → пусто (ct.status='active')
        $this->getJson('/api/web/venues/' . $venue->id . '/past-events')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_past_events_404_for_unknown_venue(): void
    {
        $this->getJson('/api/web/venues/9999999/past-events')->assertStatus(404);
    }

    /** Нормальный (active) source с картинками — для гидрации poster/images. */
    private function attachSourceWithImages(int $eventId, int $communityId, array $images): void
    {
        $now = now();

        $snId = DB::table('social_networks')->insertGetId([
            'name'       => 'vk',
            'slug'       => 'vk-img-' . $eventId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $linkId = DB::table('community_social_links')->insertGetId([
            'community_id'      => $communityId,
            'social_network_id' => $snId,
            'url'               => 'https://vk.com/src_' . $eventId,
            'status'            => 'active',
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        DB::table('event_sources')->insert([
            'event_id'         => $eventId,
            'social_link_id'   => $linkId,
            'source'           => 'vk',
            'post_external_id' => 'src-post-' . $eventId,
            'images'           => json_encode($images),
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    private function createInterest(string $slug, string $name): int
    {
        return (int) DB::table('interests')->insertGetId([
            'name'       => $name,
            'slug'       => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tagEvent(int $eventId, int $interestId): void
    {
        DB::table('event_interest')->insert([
            'event_id'    => $eventId,
            'interest_id' => $interestId,
            'rank'        => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /** N видимых событий на площадке, каждое с одним тегом $interestId. */
    private function makeTaggedEvents(int $cityId, int $venueId, int $communityId, int $interestId, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $e = $this->createEvent(
                $cityId,
                $venueId,
                $communityId,
                'Событие ' . $interestId . '-' . $i,
                '2026-07-' . str_pad((string) (10 + ($i % 18)), 2, '0', STR_PAD_LEFT) . ' 19:00:00'
            );
            $this->tagEvent($e->id, $interestId);
        }
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
