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

    /**
     * Сеансы одного события — это одно событие, а не пятнадцать.
     *
     * Структурные источники заводят строку на каждый сеанс: у парка
     * скалодромов «1000 Узлов» их 149 при одном событии, у квест-комнаты 754
     * при пяти квестах. Пока каталог считал строки, аттракционы стояли в его
     * начале, а концертные залы с настоящей афишей — в конце, и адрес
     * ночного клуба на проспекте Революции, 56 читался как парк.
     */
    public function test_index_counts_sessions_of_one_event_as_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-12 14:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        // Аттракцион: одно событие, много ежедневных сеансов.
        $park = $this->createVenue($vrn->id, 'Парк скалодромов', 'park-skalodromov');
        $parkGroup = $this->createEventGroup($vrn->id, $community->id, 'park');
        for ($day = 13; $day <= 22; $day++) {
            $this->createEvent($vrn->id, $park->id, $community->id, 'Билеты в парк', "2026-07-{$day} 12:00:00")
                ->forceFill(['event_group_id' => $parkGroup])->save();
        }

        // Клуб: три разных концерта, по одному сеансу.
        $club = $this->createVenue($vrn->id, 'Ночной клуб', 'nochnoi-klub');
        foreach (['Мураками' => 14, 'Шура' => 15, 'Markul' => 16] as $title => $day) {
            $group = $this->createEventGroup($vrn->id, $community->id, 'club-'.$day);
            $this->createEvent($vrn->id, $club->id, $community->id, $title, "2026-07-{$day} 19:00:00")
                ->forceFill(['event_group_id' => $group])->save();
        }

        $data = collect($this->getJson('/api/web/venues?city_id='.$vrn->id)->assertOk()->json('data'))
            ->keyBy('name');

        $this->assertSame(1, $data['Парк скалодромов']['events_count'], 'десять сеансов = одно событие');
        $this->assertSame(1, $data['Парк скалодромов']['upcoming_total']);
        $this->assertSame(3, $data['Ночной клуб']['events_count'], 'три концерта = три события');
        $this->assertSame(3, $data['Ночной клуб']['upcoming_total']);

        // И, главное, клуб теперь выше аттракциона в каталоге.
        $names = collect($this->getJson('/api/web/venues?city_id='.$vrn->id)->json('data'))->pluck('name');
        $this->assertTrue(
            $names->search('Ночной клуб') < $names->search('Парк скалодромов'),
            'настоящая афиша обгоняет ежедневные сеансы',
        );
    }

    /** Событие без группы считается само по себе, а не теряется в NULL. */
    public function test_index_counts_ungrouped_events_individually(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-12 14:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);
        $venue = $this->createVenue($vrn->id, 'Без групп', 'bez-grupp');

        $this->createEvent($vrn->id, $venue->id, $community->id, 'Первое', '2026-07-14 19:00:00');
        $this->createEvent($vrn->id, $venue->id, $community->id, 'Второе', '2026-07-15 19:00:00');

        $this->getJson('/api/web/venues?city_id='.$vrn->id)
            ->assertOk()
            ->assertJsonPath('data.0.events_count', 2)
            ->assertJsonPath('data.0.upcoming_total', 2);
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

    /* ============ detail: тип, описание, контакты, ритм, ближайшее ============ */

    /**
     * Площадка без описания, без source_meta и без единого события обязана
     * отдавать страницу, а не 500: таких в базе большинство, и именно они —
     * главная цель этой выдачи.
     */
    public function test_show_new_fields_are_null_safe_on_bare_venue(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Голая площадка', 'golaya');

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonPath('data.kind_label', null)
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.description_source', null)
            ->assertJsonPath('data.links', [])
            ->assertJsonPath('data.rhythm', null)
            ->assertJsonPath('data.next_event', null)
            ->assertJsonPath('data.upcoming_total', 0)
            ->assertJsonPath('data.past_total', 0)
            ->assertJsonPath('data.social_accounts', [])
            ->assertJsonPath('data.events_count', 0);
    }

    /** kind — категория каталога («Музеи»), на странице места нужен «Музей». */
    public function test_show_kind_label_is_singular_and_null_for_unknown_kind(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);

        $museum = $this->createVenue($vrn->id, 'Музей Крамского', 'muzey-kramskogo');
        $this->updateVenue($museum->id, ['kind' => 'Музеи']);

        $trampoline = $this->createVenue($vrn->id, 'Батутный центр', 'batutnyi');
        $this->updateVenue($trampoline->id, ['kind' => 'Батутные центры']);

        $this->getJson('/api/web/venues/' . $museum->id)
            ->assertOk()
            ->assertJsonPath('data.kind', 'Музеи')
            ->assertJsonPath('data.kind_label', 'Музей');

        // незнакомый тип не превращается в заглушку «Площадка» — просто null
        $this->getJson('/api/web/venues/' . $trampoline->id)
            ->assertOk()
            ->assertJsonPath('data.kind_label', null);
    }

    public function test_show_description_prefers_own_text_over_portrait(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Зелёный театр', 'zelenyi-teatr');
        $this->updateVenue($venue->id, [
            'description' => 'Летняя сцена в парке «Динамо».',
            'tg_portrait' => 'Сюда приходят за концертами под открытым небом.',
        ]);

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonPath('data.description', 'Летняя сцена в парке «Динамо».')
            ->assertJsonPath('data.description_source', 'own');
    }

    public function test_show_description_falls_back_to_tg_portrait(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Клуб 12', 'klub-12');
        $this->updateVenue($venue->id, [
            'description' => '   ', // пробелы — то же, что пусто
            'tg_portrait' => 'По субботам столы сдвигают и танцуют до закрытия.',
        ]);

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonPath('data.description', 'По субботам столы сдвигают и танцуют до закрытия.')
            ->assertJsonPath('data.description_source', 'portrait');
    }

    /**
     * Заготовка «{Тип} в городе.» описанием не считается.
     *
     * Её проставили пачкой 23 активным площадкам, и по description_source она
     * неотличима от написанного человеком, а уезжает в og:description и в
     * schema.org/Place — то есть в выдачу под видом описания места.
     */
    public function test_show_drops_template_description(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);

        $stub = $this->createVenue($vrn->id, 'С заготовкой', 's-zagotovkoy');
        $this->updateVenue($stub->id, ['description' => 'Площадка в городе.']);

        $this->getJson('/api/web/venues/' . $stub->id)
            ->assertOk()
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.description_source', null);

        // портрет при этом остаётся запасным вариантом — заготовка ему не мешает
        $withPortrait = $this->createVenue($vrn->id, 'С портретом', 's-portretom');
        $this->updateVenue($withPortrait->id, [
            'description' => 'Клуб в городе.',
            'tg_portrait' => 'По субботам столы сдвигают и танцуют до закрытия.',
        ]);

        $this->getJson('/api/web/venues/' . $withPortrait->id)
            ->assertOk()
            ->assertJsonPath('data.description', 'По субботам столы сдвигают и танцуют до закрытия.')
            ->assertJsonPath('data.description_source', 'portrait');

        // живой короткий текст, похожий по началу, режется НЕ должен
        $real = $this->createVenue($vrn->id, 'С живым текстом', 's-zhivym');
        $this->updateVenue($real->id, ['description' => 'Бар в городе Боброве.']);

        $this->getJson('/api/web/venues/' . $real->id)
            ->assertOk()
            ->assertJsonPath('data.description', 'Бар в городе Боброве.')
            ->assertJsonPath('data.description_source', 'own');
    }

    /**
     * Контракт ссылок. В сегодняшней базе таких ключей в source_meta нет ни у
     * одной площадки (там только трассировка происхождения), поэтому тест
     * задаёт их руками — он фиксирует форму ответа на момент, когда парсер
     * начнёт контакты собирать.
     */
    public function test_show_links_are_built_from_source_meta_contacts(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Дом актёра', 'dom-aktera');
        $this->updateVenue($venue->id, [
            'source_meta' => json_encode([
                'origin' => 'cold_resolve',
                'resolved_via' => 'osm_poi',
                'site' => 'https://www.domaktera.ru/afisha',
                'vk' => 'https://vk.com/domaktera',
                'telegram' => 'https://t.me/domaktera',
                'phone' => '+7 (473) 222-33-44',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $links = collect($this->getJson('/api/web/venues/' . $venue->id)->assertOk()->json('data.links'))
            ->keyBy('type');

        $this->assertSame(['site', 'vk', 'telegram', 'phone'], $links->keys()->all());
        $this->assertSame('https://www.domaktera.ru/afisha', $links['site']['url']);
        $this->assertSame('domaktera.ru', $links['site']['label'], 'подписью сайта служит домен, а не голый url');
        $this->assertSame('ВКонтакте', $links['vk']['label']);
        $this->assertSame('tel:+74732223344', $links['phone']['url']);
        $this->assertSame('+7 (473) 222-33-44', $links['phone']['label']);
    }

    /** Трассировка происхождения ссылками не притворяется, битый url не отдаём. */
    public function test_show_links_ignore_provenance_keys_and_broken_urls(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Площадка из холода', 'iz-holoda');
        $this->updateVenue($venue->id, [
            'source_meta' => json_encode([
                'origin' => 'events_materialize',
                'cluster_key' => 'fias:abc',
                'raw_name' => 'ДК Железнодорожников',
                'site' => 'domaktera.ru',      // без схемы — не ссылка
                'phone' => '222-33',           // не номер
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonPath('data.links', []);
    }

    public function test_show_rhythm_reports_cadence_and_last_event(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Юбилейный', 'yubileinyi');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        // 12 событий с 10 февраля по 25 июля — два в месяц, окно наблюдения
        // 175 дней (5.75 месяца) → 12 / 5.75 ≈ 2
        foreach (['02', '03', '04', '05', '06', '07'] as $month) {
            foreach (['10', '25'] as $day) {
                $this->createEvent($vrn->id, $venue->id, $community->id, "Концерт {$month}-{$day}", "2026-{$month}-{$day} 19:00:00");
            }
        }

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonPath('data.rhythm.events_per_month', 2)
            ->assertJsonPath('data.rhythm.last_event_at', '2026-07-25')
            ->assertJsonPath('data.rhythm.is_dormant', false);
    }

    /** Полгода тишины и ни одного анонса — площадка спит. */
    public function test_show_rhythm_marks_dormant_venue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Заброшенный ДК', 'zabroshennyi-dk');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $this->createEvent($vrn->id, $venue->id, $community->id, 'Новогодний огонёк', '2026-01-15 19:00:00');

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonPath('data.rhythm.events_per_month', null) // одно событие за полгода — не ритм
            ->assertJsonPath('data.rhythm.last_event_at', '2026-01-15')
            ->assertJsonPath('data.rhythm.is_dormant', true);
    }

    /** Место, которое молчало полгода и объявило концерт, спящим не считается. */
    public function test_show_rhythm_is_not_dormant_when_future_event_announced(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Вернувшийся клуб', 'vernuvshiysya');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $this->createEvent($vrn->id, $venue->id, $community->id, 'Старый концерт', '2025-12-20 19:00:00');
        $this->createEvent($vrn->id, $venue->id, $community->id, 'Возвращение', '2026-08-20 19:00:00');

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonPath('data.rhythm.last_event_at', '2025-12-20')
            ->assertJsonPath('data.rhythm.is_dormant', false);
    }

    /**
     * Ночная щель между предикатами: событие сегодня в 01:30 МСК уже не
     * предстоящее (граница ленты — полночь сессии БД, то есть 03:00 МСК) и ещё
     * не прошедшее (grace-час от «сейчас»). Оно не обязано считаться прошедшим —
     * концерт впереди, — но назвать место спящим за полчаса до его начала
     * нельзя. Сторож для этого и стоит: is_dormant считается по any_day, самой
     * свежей ИЗВЕСТНОЙ дате, а не по last_event_at.
     *
     * Тест работает только потому, что «сейчас» приходит в предикат прошлого
     * связанным параметром из PHP: с постгресовым now() Carbon::setTestNow()
     * не двигал бы границу, и тест зеленел бы по настоящим часам.
     */
    public function test_show_rhythm_does_not_call_venue_dormant_on_the_night_of_its_event(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 01:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Ночной клуб', 'nochnoi-klub');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $this->createEvent($vrn->id, $venue->id, $community->id, 'Зимний концерт', '2026-01-15 19:00:00');
        $this->createEvent($vrn->id, $venue->id, $community->id, 'Ночной концерт', '2026-08-04 01:30:00');

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            // концерт ещё не состоялся — в прошлом честно январь
            ->assertJsonPath('data.rhythm.last_event_at', '2026-01-15')
            ->assertJsonPath('data.past_total', 1)
            // но дата сегодня известна, значит место не спит
            ->assertJsonPath('data.rhythm.is_dormant', false)
            // а лента ту же ночь предстоящей уже не считает — страница с ней не спорит
            ->assertJsonPath('data.next_event', null);
    }

    /**
     * Ритм обязан видеть события, заведённые одной датой без времени.
     *
     * Так живёт весь Музей И.А. Бунина: у 231 события на 33 площадках
     * start_time пуст, а start_date стоит. Пока прошлое считалось отрицанием
     * «предстоящего», такие строки давали NULL (start_time >= ? → NULL, NOT
     * NULL → NULL) и молча выпадали из FILTER: страница писала «здесь давно
     * тихо», а блок «Здесь уже проходило» двумя секциями ниже показывал
     * события трёхнедельной давности. Теперь прошлое считается тем же
     * предикатом, что отдаёт /past-events, — и блоки не спорят.
     */
    public function test_show_rhythm_counts_date_only_past_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Музей И.А. Бунина', 'muzey-bunina');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        foreach (['2026-06-04', '2026-07-02', '2026-07-16'] as $day) {
            $this->createDateOnlyEvent($vrn->id, $venue->id, $community->id, 'Квартирник ' . $day, $day);
        }

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonPath('data.rhythm.last_event_at', '2026-07-16')
            ->assertJsonPath('data.rhythm.is_dormant', false)
            ->assertJsonPath('data.past_total', 3);

        // и то же самое видит блок «Здесь уже проходило» — сторож их согласия
        $this->getJson('/api/web/venues/' . $venue->id . '/past-events')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    /**
     * past_total — серверный гейт блока прошлого. Считает СОБЫТИЯ (по группам),
     * тогда как /past-events листает СЕАНСЫ построчно: число под заголовком и
     * длина списка отвечают на разные вопросы, и это записано в контракте.
     */
    public function test_show_past_total_counts_events_while_past_events_lists_sessions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Квест-комната', 'kvest');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        // один квест, четыре прошедших сеанса
        $group = $this->createEventGroup($vrn->id, $community->id, 'kvest');
        foreach (['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04'] as $day) {
            $this->createEvent($vrn->id, $venue->id, $community->id, 'Квест', $day . ' 18:00:00')
                ->forceFill(['event_group_id' => $group])->save();
        }
        // и один отдельный концерт
        $this->createEvent($vrn->id, $venue->id, $community->id, 'Концерт', '2026-07-20 19:00:00');
        // будущее в прошлое не просачивается
        $this->createEvent($vrn->id, $venue->id, $community->id, 'Будущее', '2026-08-20 19:00:00');

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonPath('data.past_total', 2)
            ->assertJsonPath('data.upcoming_total', 1);

        $this->getJson('/api/web/venues/' . $venue->id . '/past-events')
            ->assertOk()
            ->assertJsonPath('meta.total', 5);
    }

    /** Площадка с одной только будущей афишей: прошлого нет, но и не спит. */
    public function test_show_rhythm_on_venue_with_only_future_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Новая сцена', 'novaya-stsena');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $this->createEvent($vrn->id, $venue->id, $community->id, 'Открытие', '2026-08-20 19:00:00');

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonPath('data.rhythm.events_per_month', null)
            ->assertJsonPath('data.rhythm.last_event_at', null)
            ->assertJsonPath('data.rhythm.is_dormant', false);
    }

    /**
     * Ритм считает СОБЫТИЯ, а не сеансы. У квест-комнаты 754 строки на пять
     * квестов: по строкам «ритм» такого места был бы 125 событий в месяц, и
     * страница врала бы о нём сильнее, чем если бы молчала.
     */
    public function test_show_rhythm_counts_sessions_of_one_event_as_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Квест-комната', 'kvest-komnata');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        // три квеста, у каждого по 20 ежедневных сеансов = 60 строк в базе
        foreach (['03', '04', '05'] as $i => $month) {
            $group = $this->createEventGroup($vrn->id, $community->id, 'kvest-' . $month);
            for ($day = 1; $day <= 20; $day++) {
                $this->createEvent(
                    $vrn->id,
                    $venue->id,
                    $community->id,
                    'Квест ' . $i,
                    sprintf('2026-%s-%02d 18:00:00', $month, $day),
                )->forceFill(['event_group_id' => $group])->save();
            }
        }

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            // 3 квеста за 156 дней (5.1 месяца) ≈ 1 в месяц; по строкам вышло бы 12
            ->assertJsonPath('data.rhythm.events_per_month', 1)
            ->assertJsonPath('data.rhythm.last_event_at', '2026-05-20');
    }

    public function test_show_next_event_repeats_catalog_logic_and_carries_url(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Юбилейный', 'yubileinyi');
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $this->createEvent($vrn->id, $venue->id, $community->id, 'Прошедший', '2026-07-30 19:00:00');
        $this->createEvent($vrn->id, $venue->id, $community->id, 'Поздний', '2026-08-20 19:00:00');
        $sooner = $this->createEvent($vrn->id, $venue->id, $community->id, 'Ближний', '2026-08-10 18:00:00');

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonPath('data.next_event.id', $sooner->id)
            ->assertJsonPath('data.next_event.title', 'Ближний')
            ->assertJsonPath('data.next_event.start_at', '2026-08-10T18:00:00+03:00')
            ->assertJsonPath('data.next_event.start_date', '2026-08-10')
            ->assertJsonPath('data.next_event.url', '/events/' . $sooner->id)
            ->assertJsonPath('data.upcoming_total', 2);
    }

    /** Старые поля detail не переименованы и не потеряны — фронт уже их читает. */
    public function test_show_keeps_existing_contract_fields(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Юбилейный', 'yubileinyi');

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'id', 'slug', 'name', 'kind', 'description', 'address', 'street', 'house',
                'house_fias_id', 'city' => ['id', 'name', 'slug'], 'lat', 'lng',
                'cover_image_url', 'avatar_url', 'events_count', 'genre_profile',
            ]]);
    }

    /* ============ nearby (соседние площадки) ============ */

    public function test_nearby_puts_venues_with_future_events_first_then_by_distance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $base = $this->createVenue($vrn->id, 'Точка отсчёта', 'tochka', 39.0, 51.0);

        // 222 м, но афиши нет — ближе всех и всё равно последний
        $close = $this->createVenue($vrn->id, 'Музей рядом', 'muzey-ryadom', 39.0, 51.002);
        $this->createEvent($vrn->id, $close->id, $community->id, 'Выставка прошла', '2026-07-01 12:00:00');

        // 1113 м, есть будущее
        $far = $this->createVenue($vrn->id, 'Дальний клуб', 'dalniy-klub', 39.0, 51.010);
        $this->createEvent($vrn->id, $far->id, $community->id, 'Концерт', '2026-08-15 19:00:00');

        // 557 м, есть будущее
        $mid = $this->createVenue($vrn->id, 'Средний зал', 'sredniy-zal', 39.0, 51.005);
        $this->createEvent($vrn->id, $mid->id, $community->id, 'Спектакль', '2026-08-12 19:00:00');

        $data = $this->getJson('/api/web/venues/' . $base->id . '/nearby')->assertOk()->json('data');

        $this->assertSame(
            ['Средний зал', 'Дальний клуб', 'Музей рядом'],
            array_column($data, 'name'),
            'сначала те, куда можно пойти, и уже внутри — по расстоянию',
        );
        $this->assertEqualsWithDelta(557, $data[0]['distance_m'], 15);
        $this->assertSame(1, $data[0]['upcoming_total']);
        $this->assertSame(0, $data[2]['upcoming_total']);
    }

    public function test_nearby_excludes_self_eventless_venues_and_other_cities(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $base = $this->createVenue($vrn->id, 'Точка отсчёта', 'tochka', 39.0, 51.0);
        // у самой площадки события есть — она всё равно не сосед сама себе
        $this->createEvent($vrn->id, $base->id, $community->id, 'Своё событие', '2026-08-10 19:00:00');

        $good = $this->createVenue($vrn->id, 'Живой сосед', 'zhivoy', 39.0, 51.003);
        $this->createEvent($vrn->id, $good->id, $community->id, 'Концерт', '2026-08-11 19:00:00');

        // ни одного события — тупик для пользователя, в выдачу не берём
        $this->createVenue($vrn->id, 'Пустой сосед', 'pustoy', 39.0, 51.001);

        // события есть, но ни одно не видно в вебе — то же самое, что пусто
        $invisible = $this->createVenue($vrn->id, 'Невидимый сосед', 'nevidimyi', 39.0, 51.0015);
        $deleted = $this->createEvent($vrn->id, $invisible->id, $community->id, 'Удалённое', '2026-08-12 19:00:00');
        $deleted->delete();
        $kids = $this->createEvent($vrn->id, $invisible->id, $community->id, 'Детское', '2026-08-13 10:00:00');
        $kids->audience = 'kids';
        $kids->save();

        // чужой город, координаты рядом — «соседей» ищем только по своему городу
        $mskCommunity = Community::create(['name' => 'Мск', 'city_id' => $msk->id]);
        $alien = $this->createVenue($msk->id, 'Чужой город', 'chuzhoy', 39.0, 51.0005);
        $this->createEvent($msk->id, $alien->id, $mskCommunity->id, 'Московское', '2026-08-14 19:00:00');

        $data = $this->getJson('/api/web/venues/' . $base->id . '/nearby')->assertOk()->json('data');

        $this->assertSame(['Живой сосед'], array_column($data, 'name'));
    }

    public function test_nearby_limit_defaults_to_six_and_caps_at_twelve(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $base = $this->createVenue($vrn->id, 'Точка отсчёта', 'tochka', 39.0, 51.0);

        // 14 соседей в шаге ~22 м друг от друга — все в дефолтном радиусе
        for ($i = 1; $i <= 14; $i++) {
            $name = sprintf('Сосед %02d', $i);
            $v = $this->createVenue($vrn->id, $name, 'sosed-' . $i, 39.0, 51.0 + $i * 0.0002);
            $this->createEvent($vrn->id, $v->id, $community->id, 'Событие ' . $i, '2026-08-10 19:00:00');
        }

        $default = $this->getJson('/api/web/venues/' . $base->id . '/nearby')->assertOk()->json('data');
        $this->assertCount(6, $default);
        $this->assertSame('Сосед 01', $default[0]['name'], 'первым идёт ближайший');

        $limited = $this->getJson('/api/web/venues/' . $base->id . '/nearby?limit=2')->assertOk()->json('data');
        $this->assertSame(['Сосед 01', 'Сосед 02'], array_column($limited, 'name'));

        // потолок — 12: запрос сотни соседей его не поднимает
        $capped = $this->getJson('/api/web/venues/' . $base->id . '/nearby?limit=100')->assertOk()->json('data');
        $this->assertCount(12, $capped);
    }

    public function test_nearby_radius_cuts_off_distant_venues(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00', 'Europe/Moscow'));

        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $base = $this->createVenue($vrn->id, 'Точка отсчёта', 'tochka', 39.0, 51.0);

        // ~11 км — за дефолтным радиусом 3000 м, но внутри расширенного
        $outer = $this->createVenue($vrn->id, 'За городом', 'za-gorodom', 39.0, 51.1);
        $this->createEvent($vrn->id, $outer->id, $community->id, 'Загородное', '2026-08-16 19:00:00');

        $this->getJson('/api/web/venues/' . $base->id . '/nearby')
            ->assertOk()
            ->assertJsonPath('data', []);

        $wide = $this->getJson('/api/web/venues/' . $base->id . '/nearby?radius_m=20000')->assertOk()->json('data');
        $this->assertSame(['За городом'], array_column($wide, 'name'));
        $this->assertEqualsWithDelta(11130, $wide[0]['distance_m'], 200);
    }

    public function test_nearby_returns_empty_for_venue_without_coordinates(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = Community::create(['name' => 'Тест', 'city_id' => $vrn->id]);

        $base = $this->createVenue($vrn->id, 'Без точки', 'bez-tochki', 39.0, 51.0);
        DB::table('venues')->where('id', $base->id)->update(['location' => null]);

        $neighbour = $this->createVenue($vrn->id, 'Сосед', 'sosed', 39.0, 51.002);
        $this->createEvent($vrn->id, $neighbour->id, $community->id, 'Концерт',
            Carbon::now('Europe/Moscow')->addDays(5)->format('Y-m-d H:i:s'));

        $this->getJson('/api/web/venues/' . $base->id . '/nearby')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_nearby_404_for_unknown_venue(): void
    {
        $this->getJson('/api/web/venues/9999999/nearby')->assertStatus(404);
    }

    /* ============ social_accounts (аккаунты места в соцсетях) ============ */

    public function test_show_social_accounts_returns_community_links_and_freshness(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'ТЕАТР. АКТ', 'teatr-akt');

        $community = $this->attachCommunityToVenue($vrn->id, $venue->id, 'ТЕАТР. АКТ', 'https://vk.cc/ava.jpg');
        $this->attachSocialLink($community->id, 'vk', 'https://vk.com/teatract');
        $this->addContextPost($community->id, '2026-07-20 08:00:00');
        $this->addContextPost($community->id, '2026-08-01 09:30:00');

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.social_accounts')
            ->assertJsonPath('data.social_accounts.0.community.id', $community->id)
            ->assertJsonPath('data.social_accounts.0.community.name', 'ТЕАТР. АКТ')
            ->assertJsonPath('data.social_accounts.0.community.avatar_url', 'https://vk.cc/ava.jpg')
            ->assertJsonPath('data.social_accounts.0.links.0.network', 'vk')
            ->assertJsonPath('data.social_accounts.0.links.0.url', 'https://vk.com/teatract')
            ->assertJsonPath('data.social_accounts.0.links.0.label', 'ВКонтакте')
            ->assertJsonPath('data.social_accounts.0.last_post_at', '2026-08-01');
    }

    /**
     * Свежесть — московская дата. context_posts.published_at лежит в UTC без
     * таймзоны, и пост в 21:30 UTC — это уже половина первого ночи следующего
     * дня по Москве. Весь остальной ответ (календарь, ритм, ближайшее) считает
     * дни по МСК, и «обновлено вчера» не должно означать разные вчера.
     */
    public function test_show_social_accounts_last_post_at_is_a_moscow_date(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'LOFT36', 'loft36');

        $community = $this->attachCommunityToVenue($vrn->id, $venue->id, 'LOFT36');
        $this->addContextPost($community->id, '2026-08-01 21:30:00'); // = 2026-08-02 00:30 МСК

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonPath('data.social_accounts.0.last_post_at', '2026-08-02');
    }

    /**
     * Гейт качества: чёрный список и проверенно мёртвые ссылки на страницу не
     * попадают. Ссылка в никуда со страницы места хуже, чем её отсутствие.
     */
    public function test_show_social_accounts_quality_gate_drops_black_and_dead_links(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Дом актёра', 'dom-aktera');

        $community = $this->attachCommunityToVenue($vrn->id, $venue->id, 'Дом актёра');
        $this->attachSocialLink($community->id, 'vk', 'https://vk.com/live', 'active', true);
        $this->attachSocialLink($community->id, 'telegram', 'https://t.me/black', 'black', true);
        $this->attachSocialLink($community->id, 'site', 'https://dead.example', 'active', false);

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.social_accounts.0.links')
            ->assertJsonPath('data.social_accounts.0.links.0.url', 'https://vk.com/live');
    }

    /** Непроверенная ссылка (last_is_active IS NULL) — не то же, что мёртвая. */
    public function test_show_social_accounts_keeps_unverified_link(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Новое место', 'novoe-mesto');

        $community = $this->attachCommunityToVenue($vrn->id, $venue->id, 'Новое место');
        $this->attachSocialLink($community->id, 'telegram', 'https://t.me/novoe', 'active', null);

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.social_accounts.0.links')
            ->assertJsonPath('data.social_accounts.0.links.0.network', 'telegram')
            ->assertJsonPath('data.social_accounts.0.links.0.label', 'Telegram');
    }

    /** Нет сообществ (55 площадок из 106) — пустой массив, а не null. */
    public function test_show_social_accounts_is_empty_array_without_communities(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $bare = $this->createVenue($vrn->id, 'Без источников', 'bez-istochnikov');

        $this->getJson('/api/web/venues/' . $bare->id)
            ->assertOk()
            ->assertJsonPath('data.social_accounts', []);

        // отвязанное (удалённое) сообщество источником быть перестаёт
        $archived = $this->createVenue($vrn->id, 'С архивным', 's-arhivnym');
        $community = $this->attachCommunityToVenue($vrn->id, $archived->id, 'Ушедшее сообщество');
        $this->attachSocialLink($community->id, 'vk', 'https://vk.com/gone');
        $community->delete();

        $this->getJson('/api/web/venues/' . $archived->id)
            ->assertOk()
            ->assertJsonPath('data.social_accounts', []);
    }

    /**
     * Трассировка происхождения источником не становится.
     *
     * from_community_id лежит в source_meta у 56 площадок из 121 — это след
     * того, кто площадку породил, а не утверждение «отсюда мы берём её афишу».
     * Сообщество с тех пор могли отвязать, слить или переназначить: сегодня у 7
     * площадок этот id указывает на сообщество, которое живёт уже при ДРУГОМ
     * месте (venue 33 «Сити-парк „Град“» → сообщество 70, у которого
     * venue_id = 87). Прочитай мы source_meta — страница назвала бы источником
     * чужое сообщество. Связь только через FK communities.venue_id.
     */
    public function test_show_social_accounts_ignore_source_meta_provenance(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);

        $grad  = $this->createVenue($vrn->id, 'Сити-парк «Град»', 'grad');
        $other = $this->createVenue($vrn->id, 'Другое место', 'drugoe');

        // сообщество переназначено на другую площадку, а след в source_meta остался
        $moved = $this->attachCommunityToVenue($vrn->id, $other->id, 'Переехавшее сообщество');
        $this->attachSocialLink($moved->id, 'vk', 'https://vk.com/moved');
        $this->addContextPost($moved->id, '2026-08-01 09:00:00');

        $this->updateVenue($grad->id, [
            'source_meta' => json_encode([
                'origin'              => 'venues_backfill',
                'from_community_id'   => $moved->id,
                'from_community_name' => 'Переехавшее сообщество',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->getJson('/api/web/venues/' . $grad->id)
            ->assertOk()
            ->assertJsonPath('data.social_accounts', []);

        // сторож фикстуры: сообщество живое и на своей площадке по FK видно
        $this->getJson('/api/web/venues/' . $other->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.social_accounts')
            ->assertJsonPath('data.social_accounts.0.community.id', $moved->id);
    }

    /**
     * Тот же запрет со стороны сообщества-сироты: след в source_meta ведёт на
     * сообщество, которое не привязано вообще ни к одной площадке. Источником
     * оно от этого не становится — связь живёт только в FK.
     */
    public function test_show_social_accounts_ignores_unlinked_community_from_source_meta(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'По следу', 'po-sledu');

        // сообщество живое и со ссылкой, но venue_id у него не проставлен
        $orphan = Community::create(['name' => 'Только след', 'city_id' => $vrn->id]);
        $this->attachSocialLink($orphan->id, 'vk', 'https://vk.com/sled');
        $this->updateVenue($venue->id, [
            'source_meta' => json_encode([
                'origin'            => 'community_backfill',
                'from_community_id' => $orphan->id,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonPath('data.social_accounts', []);
    }

    /**
     * Источник без свежести всё равно называется: мы правда собираем отсюда
     * афишу, просто давно ничего не прочитали. Скрыть его — умолчать об
     * атрибуции, а это ровно то, чего агрегатору делать нельзя.
     */
    public function test_show_social_accounts_keeps_community_without_posts(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);

        $silent = $this->createVenue($vrn->id, 'Молчун', 'molchun');
        $noPosts = $this->attachCommunityToVenue($vrn->id, $silent->id, 'Сообщество без постов');
        $this->attachSocialLink($noPosts->id, 'vk', 'https://vk.com/silent');

        $this->getJson('/api/web/venues/' . $silent->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.social_accounts')
            ->assertJsonPath('data.social_accounts.0.community.name', 'Сообщество без постов')
            ->assertJsonPath('data.social_accounts.0.last_post_at', null);

    }

    /**
     * Погашенный уборкой пост свежесть всё равно даёт.
     *
     * context:cleanup гасит посты старше 30 дней, не породившие событий, — это
     * TTL хранилища, а не отзыв факта: пост мы прочитали. Если такие не
     * считать, у «ТЕАТР. АКТ» свежесть съезжает с декабря 2025 на август 2024,
     * и страница сообщает, что источник молчит два года, — при живом источнике.
     */
    public function test_show_social_accounts_freshness_counts_pruned_posts(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'С отозванным', 's-otozvannym');

        $community = $this->attachCommunityToVenue($vrn->id, $venue->id, 'Сообщество с погашенным постом');
        $this->addContextPost($community->id, '2026-06-01 09:00:00');
        $this->addContextPost($community->id, '2026-08-01 09:00:00', deleted: true);

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.social_accounts')
            ->assertJsonPath('data.social_accounts.0.last_post_at', '2026-08-01');
    }

    /** Сообщество без живых ссылок остаётся источником — просто некликабельным. */
    public function test_show_social_accounts_keeps_community_without_links(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Площадка', 'ploschadka');

        $community = $this->attachCommunityToVenue($vrn->id, $venue->id, 'Сообщество без ссылок');
        $this->attachSocialLink($community->id, 'vk', 'https://vk.com/dead', 'active', false);
        $this->addContextPost($community->id, '2026-08-01 09:00:00');

        $this->getJson('/api/web/venues/' . $venue->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.social_accounts')
            ->assertJsonPath('data.social_accounts.0.community.name', 'Сообщество без ссылок')
            ->assertJsonPath('data.social_accounts.0.links', [])
            ->assertJsonPath('data.social_accounts.0.last_post_at', '2026-08-01');
    }

    /** Порядок обязан быть один и тот же между запросами, иначе блок прыгает. */
    public function test_show_social_accounts_order_is_deterministic(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Многоисточниковая', 'mnogo');

        $old = $this->attachCommunityToVenue($vrn->id, $venue->id, 'Старый');
        $this->addContextPost($old->id, '2026-08-01 09:00:00');

        $mute1 = $this->attachCommunityToVenue($vrn->id, $venue->id, 'Молчун раньше');
        $fresh = $this->attachCommunityToVenue($vrn->id, $venue->id, 'Свежий');
        $this->addContextPost($fresh->id, '2026-08-03 09:00:00');
        $mute2 = $this->attachCommunityToVenue($vrn->id, $venue->id, 'Молчун позже');

        $names = collect($this->getJson('/api/web/venues/' . $venue->id)->assertOk()->json('data.social_accounts'))
            ->pluck('community.name')->all();

        // свежие сверху; без даты — в конец и там по id
        $this->assertSame(['Свежий', 'Старый', 'Молчун раньше', 'Молчун позже'], $names);
        $this->assertTrue($mute1->id < $mute2->id, 'порядок молчунов проверяется по возрастанию id');
    }

    /** Три сообщества стоят столько же запросов, сколько одно. */
    public function test_show_social_accounts_are_eager_loaded_without_n_plus_one(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);

        $one = $this->createVenue($vrn->id, 'С одним', 's-odnim');
        $single = $this->attachCommunityToVenue($vrn->id, $one->id, 'Единственное');
        $this->attachSocialLink($single->id, 'vk', 'https://vk.com/one');
        $this->addContextPost($single->id, '2026-08-01 09:00:00');

        $many = $this->createVenue($vrn->id, 'С тремя', 's-tremya');
        foreach (['vk', 'telegram', 'site'] as $i => $network) {
            $c = $this->attachCommunityToVenue($vrn->id, $many->id, 'Источник ' . $i);
            $this->attachSocialLink($c->id, $network, 'https://example.test/' . $network);
            $this->addContextPost($c->id, '2026-08-0' . ($i + 1) . ' 09:00:00');
        }

        $this->getJson('/api/web/venues/' . $one->id)->assertOk(); // прогрев

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/web/venues/' . $one->id)->assertOk();
        $queriesOne = count(DB::getQueryLog());
        DB::disableQueryLog();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/web/venues/' . $many->id)->assertOk()->assertJsonCount(3, 'data.social_accounts');
        $queriesThree = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $queriesOne,
            $queriesThree,
            "Число запросов растёт с числом аккаунтов ({$queriesOne} → {$queriesThree}): грузятся не батчем (N+1)",
        );
    }

    /** Сообщество-источник площадки: связь ставит FK communities.venue_id. */
    private function attachCommunityToVenue(int $cityId, int $venueId, string $name, ?string $avatarUrl = null): Community
    {
        $community = Community::create(['name' => $name, 'city_id' => $cityId, 'avatar_url' => $avatarUrl]);
        // venue_id нет в $fillable: связь проставляет парсер, не веб-слой
        DB::table('communities')->where('id', $community->id)->update(['venue_id' => $venueId]);

        return $community->refresh();
    }

    private function socialNetworkId(string $slug): int
    {
        $id = DB::table('social_networks')->where('slug', $slug)->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('social_networks')->insertGetId([
            'name'       => $slug,
            'slug'       => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attachSocialLink(
        int $communityId,
        string $networkSlug,
        string $url,
        string $status = 'active',
        ?bool $lastIsActive = null,
    ): int {
        return (int) DB::table('community_social_links')->insertGetId([
            'community_id'      => $communityId,
            'social_network_id' => $this->socialNetworkId($networkSlug),
            'url'               => $url,
            'status'            => $status,
            'last_is_active'    => $lastIsActive,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    /** Прочитанный пост источника; $publishedAtUtc — 'Y-m-d H:i:s' в UTC. */
    private function addContextPost(int $communityId, string $publishedAtUtc, bool $deleted = false): void
    {
        DB::table('context_posts')->insert([
            'community_id' => $communityId,
            'published_at' => $publishedAtUtc,
            'status'       => 'active',
            'created_at'   => now(),
            'updated_at'   => now(),
            'deleted_at'   => $deleted ? now() : null,
        ]);
    }

    /** Правка полей площадки мимо Eloquent: tg_portrait/kind не в $fillable. */
    private function updateVenue(int $venueId, array $columns): void
    {
        DB::table('venues')->where('id', $venueId)->update($columns + ['updated_at' => now()]);
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
    /** Группа событий — та единица, которой каталог считает афишу. */
    private function createEventGroup(int $cityId, int $communityId, string $key): int
    {
        return (int) DB::table('event_groups')->insertGetId([
            'community_id' => $communityId,
            'city_id' => $cityId,
            'group_key' => 'grp-'.$key,
            'title_norm' => $key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Событие, заведённое одной ДАТОЙ без времени. Так живёт заметная часть
     * базы (231 событие на 33 площадках): у музеев в посте стоит «16 июля»,
     * часа нет. Именно на таких строках трёхзначная логика SQL и подводит.
     */
    private function createDateOnlyEvent(int $cityId, int $venueId, int $communityId, string $title, string $dateMsk): Event
    {
        $event = new Event();
        $event->community_id = $communityId;
        $event->venue_id     = $venueId;
        $event->title        = $title;
        $event->status       = 'active';
        $event->city_id      = $cityId;
        $event->start_time   = null;
        $event->start_date   = $dateMsk;
        $event->save();

        return $event;
    }

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
