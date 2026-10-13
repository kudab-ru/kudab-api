<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use App\Models\Interest;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebEventsTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/128.0 Mobile Safari/537.36';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_web_events_can_be_filtered_by_active_city_slug(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $spb = $this->insertCity('Санкт-Петербург', 'spb', 'active', 30.3351, 59.9343);

        $mskCommunity = $this->createCommunity($msk->id, 'Организатор Москва');
        $spbCommunity = $this->createCommunity($spb->id, 'Организатор Питер');

        $this->createEvent($msk->id, $mskCommunity->id, 'Событие Москва', now()->addDay());
        $this->createEvent($spb->id, $spbCommunity->id, 'Событие Питер', now()->addDay());

        $response = $this->getJson('/api/web/events?city=moskva');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Событие Москва')
            ->assertJsonPath('data.0.city_slug', 'moskva');

        $response->assertJsonMissing([
            'title' => 'Событие Питер',
        ]);
    }

    public function test_web_events_returns_empty_list_for_disabled_city_slug(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'disabled', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор Воронеж');

        $this->createEvent($city->id, $community->id, 'Событие Воронеж', now()->addDay());

        $response = $this->getJson('/api/web/events?city=voronezh');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonCount(0, 'data');

        $response->assertJsonMissing([
            'title' => 'Событие Воронеж',
        ]);
    }

    public function test_web_events_returns_empty_list_for_unknown_city_slug(): void
    {
        $response = $this->getJson('/api/web/events?city=unknown-city');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonCount(0, 'data');
    }

    public function test_web_events_when_today_returns_only_today_events(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 22, 12, 0, 0, 'Europe/Moscow'));

        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор Москва');

        $todayEventTime = Carbon::create(2026, 3, 22, 18, 0, 0, 'Europe/Moscow');
        $tomorrowEventTime = Carbon::create(2026, 3, 23, 18, 0, 0, 'Europe/Moscow');

        $this->createEvent($msk->id, $community->id, 'Сегодняшнее событие', $todayEventTime);
        $this->createEvent($msk->id, $community->id, 'Завтрашнее событие', $tomorrowEventTime);

        $response = $this->getJson('/api/web/events?city=moskva&when=today');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Сегодняшнее событие');

        $response->assertJsonMissing([
            'title' => 'Завтрашнее событие',
        ]);
    }

    public function test_web_events_can_be_filtered_by_community_id(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);

        $wantedCommunity = $this->createCommunity($msk->id, 'Нужный организатор');
        $otherCommunity = $this->createCommunity($msk->id, 'Другой организатор');

        $this->createEvent($msk->id, $wantedCommunity->id, 'Нужное событие', now()->addDay());
        $this->createEvent($msk->id, $otherCommunity->id, 'Чужое событие', now()->addDay());

        $response = $this->getJson('/api/web/events?city=moskva&community_id='.$wantedCommunity->id);

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Нужное событие');

        $response->assertJsonMissing([
            'title' => 'Чужое событие',
        ]);
    }

    public function test_web_events_can_be_filtered_by_venue_id(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $wantedVenue = $this->createVenue($msk->id, 'Нужная площадка', 'nuzhnaya-ploshchadka');
        $otherVenue = $this->createVenue($msk->id, 'Другая площадка', 'drugaya-ploshchadka');

        $this->createEvent($msk->id, $community->id, 'Событие на нужной', now()->addDay(), $wantedVenue->id);
        $this->createEvent($msk->id, $community->id, 'Событие на другой', now()->addDay(), $otherVenue->id);
        $this->createEvent($msk->id, $community->id, 'Событие без площадки', now()->addDay());

        $response = $this->getJson('/api/web/events?city=moskva&venue_id='.$wantedVenue->id);

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Событие на нужной');

        $response->assertJsonMissing(['title' => 'Событие на другой']);
        $response->assertJsonMissing(['title' => 'Событие без площадки']);
    }

    public function test_web_events_can_be_found_by_q_in_title(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);

        $community = $this->createCommunity($msk->id, 'Организатор Москва');

        $this->createEvent($msk->id, $community->id, 'Большой джазовый концерт', now()->addDay());
        $this->createEvent($msk->id, $community->id, 'Лекция по истории', now()->addDays(2));

        $response = $this->getJson('/api/web/events?city=moskva&q=джаз');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Большой джазовый концерт');

        $response->assertJsonMissing([
            'title' => 'Лекция по истории',
        ]);
    }

    public static function searchCases(): array
    {
        return [
            'другая форма слова находится' => ['настолки', 'Вечер настольных игр', 'Ветер, куда нас несёт?'],
            'опечатка прощается' => ['консерт', 'Концерт хора', 'Занятия в консультационном центре'],
            'общее начало слова не в счёт' => ['мафия', 'Воронежская мафия', 'Клод Моне. Магия воды и света'],
            'настолки находят игротеку' => ['настолки', 'Игротека в Котокафе', 'Дискотека'],
            'игротека находит настольные игры' => ['игротека', 'Фестиваль настольных игр', 'Дискотека'],
            'квест находит quest' => ['квест', 'Quest «Тени Готэма»', 'Квартирник'],
            'стендап находит stand-up' => ['стендап', 'Большой Stand-up', 'Концерт хора'],
        ];
    }

    #[DataProvider('searchCases')]
    public function test_search_finds_the_matching_event_and_not_the_lookalike(string $q, string $found, string $other): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($vrn->id, 'Организатор');
        $this->createEvent($vrn->id, $community->id, $found, now()->addDay());
        $this->createEvent($vrn->id, $community->id, $other, now()->addDays(2));

        $data = $this->getJson('/api/web/events?city=voronezh&q='.urlencode($q))->assertOk()->json('data');

        $this->assertSame([$found], array_column($data, 'title'));
    }

    public function test_search_does_not_look_into_the_organizer_description(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $club = $this->createCommunity($vrn->id, 'Научный клуб');
        DB::table('communities')->where('id', $club->id)->update(['description' => 'Лекции, концерты и настольные игры']);
        $this->createEvent($vrn->id, $club->id, 'Лекция о кометах', now()->addDay());

        $this->getJson('/api/web/events?city=voronezh&q='.urlencode('концерт'))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_search_shows_family_events_that_the_feed_hides(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $event = $this->createEvent($vrn->id, $this->createCommunity($vrn->id, 'Котокафе')->id, 'Игротека', now()->addDay());
        $this->setTaxonomy($event->id, 'family', 'entertainment');

        $this->getJson('/api/web/events?city=voronezh&q='.urlencode('игротека'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Игротека');
    }

    public function test_search_finds_events_at_the_venue_by_its_name(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($vrn->id, 'Попкорн Драма', 'popkorn-drama');
        $this->createEvent($vrn->id, $this->createCommunity($vrn->id, 'Qtickets')->id, 'Фиаско', now()->addDay(), $venue->id);

        $this->getJson('/api/web/events?city=voronezh&q='.urlencode('попкорн драма'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Фиаско');
    }

    public function test_search_by_kind_word_finds_events_at_places_of_that_kind_only(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($vrn->id, 'Афиша');
        $square = $this->createVenue($vrn->id, 'Кольцовский сквер', 'skver');
        $cinema = $this->createVenue($vrn->id, 'Синема Парк', 'cinema-park');
        DB::table('venues')->where('id', $square->id)->update(['kind' => 'Парки']);
        DB::table('venues')->where('id', $cinema->id)->update(['kind' => 'Кинотеатры']);
        $this->createEvent($vrn->id, $community->id, 'Ярмарка', now()->addDay(), $square->id);
        $this->createEvent($vrn->id, $community->id, 'Чебурашка', now()->addDays(2), $cinema->id);

        $data = $this->getJson('/api/web/events?city=voronezh&q='.urlencode('парк'))->assertOk()->json('data');

        $this->assertSame(['Ярмарка'], array_column($data, 'title'));
    }

    public function test_search_puts_title_match_above_description_match_with_photo(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($vrn->id, 'Организатор');
        $this->createEvent($vrn->id, $community->id, 'Квиз про кино', now()->addDays(3));
        $bar = $this->createEvent($vrn->id, $community->id, 'Вечер в баре', now()->addDay());
        DB::table('events')->where('id', $bar->id)->update(['description' => 'Сыграем в квиз и послушаем музыку']);
        $this->attachPhoto($bar->id, $community->id);

        $data = $this->getJson('/api/web/events?city=voronezh&q=квиз')->assertOk()->json('data');

        $this->assertSame(['Квиз про кино', 'Вечер в баре'], array_column($data, 'title'));
    }

    public static function soonCases(): array
    {
        return [
            'поиск: сайт шлёт московское время без пояса' => ['/api/web/events?city=voronezh&date_from=2026-10-12+20:07:00', '2026-10-12 21:00'],
            'кнопка «Сейчас»: событие идёт полчаса' => ['/api/web/events?city=voronezh&when=now', '2026-10-12 19:40'],
            'бот: только актуальные' => ['/api/bot/events?only_actual=1', '2026-10-12 21:00'],
        ];
    }

    #[DataProvider('soonCases')]
    public function test_events_of_the_next_hours_are_not_lost_to_the_time_zone(string $url, string $startMsk): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 20:07', 'Europe/Moscow'));
        config(['services.bot.shared_token' => 'test-bot-token']);
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        // момент в UTC: время с московским поясом модель сохранила бы без пояса, и оно уехало бы
        $start = Carbon::parse($startMsk, 'Europe/Moscow')->utc();
        $this->createEvent($vrn->id, $this->createCommunity($vrn->id, 'Квесты')->id, 'Арканум', $start);

        $data = $this->withToken('test-bot-token')->getJson($url)->assertOk()->json('data');

        $this->assertSame(['Арканум'], array_column($data, 'title'));
    }

    public function test_past_search_lists_recent_events_once_per_series(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 12:00', 'Europe/Moscow'));
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $org = $this->createCommunity($vrn->id, 'Котокафе');
        $last = $this->createEvent($vrn->id, $org->id, 'Вечер настольных игр', now()->subDays(3));
        $before = $this->createEvent($vrn->id, $org->id, 'Вечер настольных игр', now()->subDays(10));
        $group = DB::table('event_groups')->insertGetId([
            'community_id' => $org->id, 'city_id' => $vrn->id, 'group_key' => 'eg:test:games',
            'title_norm' => 'вечер настольных игр', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('events')->whereIn('id', [$last->id, $before->id])->update(['event_group_id' => $group]);
        $this->createEvent($vrn->id, $org->id, 'Игротека', now()->subDays(20));
        $this->createEvent($vrn->id, $org->id, 'Турнир по настольным играм', now()->subDays(200));
        $this->createEvent($vrn->id, $org->id, 'Настолки на неделе', now()->addDay());
        $this->createEvent($vrn->id, $org->id, 'Лекция', now()->subDays(2));

        $data = $this->getJson('/api/web/events/past?city=voronezh&q='.urlencode('настолки'))->assertOk()->json('data');

        $this->assertSame([['Вечер настольных игр', 2], ['Игротека', 1]], array_map(fn ($e) => [$e['title'], $e['series_sessions']], $data));
    }

    public function test_suggest_lists_upcoming_events_by_word_start_once_per_series(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $org = $this->createCommunity($vrn->id, 'Котокафе');
        $first = $this->createEvent($vrn->id, $org->id, 'Вечер настольных игр', now()->addDay());
        $second = $this->createEvent($vrn->id, $org->id, 'Вечер настольных игр', now()->addDays(8));
        $group = DB::table('event_groups')->insertGetId([
            'community_id' => $org->id, 'city_id' => $vrn->id, 'group_key' => 'eg:test:games',
            'title_norm' => 'вечер настольных игр', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('events')->whereIn('id', [$first->id, $second->id])->update(['event_group_id' => $group]);
        $this->createEvent($vrn->id, $org->id, 'Настольный турнир', now()->subDay());
        $this->createEvent($vrn->id, $org->id, 'Концерт хора Валаамского монастыря', now()->addDays(2));

        $events = $this->getJson('/api/web/search/suggest?city=voronezh&q='.urlencode('наст'))->assertOk()->json('events');

        $this->assertSame([[$first->id, 'Вечер настольных игр']], array_map(fn ($e) => [$e['id'], $e['title']], $events));
    }

    public static function topicSuggestCases(): array
    {
        return [
            'начало слова в названии темы' => ['игр', ['quiz-games']],
            'середина слова не в счёт' => ['из', []],
        ];
    }

    #[DataProvider('topicSuggestCases')]
    public function test_suggest_offers_topics_whose_word_starts_with_the_query(string $q, array $slugs): void
    {
        $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $this->createInterest('Квизы и игры', 'quiz-games');
        $this->createInterest('Выставки и искусство', 'exhibitions');

        $topics = $this->getJson('/api/web/search/suggest?city=voronezh&q='.urlencode($q))->assertOk()->json('topics');

        $this->assertSame($slugs, array_column($topics, 'slug'));
    }

    public function test_search_typed_in_english_layout_finds_the_russian_word(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $this->createEvent($vrn->id, $this->createCommunity($vrn->id, 'Организатор')->id, 'Вечер настольных игр', now()->addDay());

        $this->getJson('/api/web/events?city=voronezh&q=yfcnjkrb')
            ->assertOk()
            ->assertJsonPath('meta.corrected_q', 'настолки')
            ->assertJsonPath('data.0.title', 'Вечер настольных игр');
    }

    public function test_site_search_is_recorded_with_the_number_found(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($vrn->id, 'Организатор');
        $games = $this->createEvent($vrn->id, $community->id, 'Вечер настольных игр', now()->addDay());
        DB::table('events')->where('id', $games->id)->update(['price_status' => 'free']);
        $this->createEvent($vrn->id, $community->id, 'Лекция', now()->addDays(2));

        $this->withHeader('User-Agent', self::BROWSER)
            ->getJson('/api/web/events?city=voronezh&per_page=25&free=1&q='.urlencode('настолки'))
            ->assertOk();

        $row = DB::table('search_queries')->sole();
        $this->assertSame(['настолки', $vrn->id, 1, ['free' => '1']], [$row->q, (int) $row->city_id, (int) $row->found, json_decode($row->filters, true)]);
    }

    public static function notRecordedSearches(): array
    {
        return [
            'следующая страница' => ['&per_page=25&page=2', self::BROWSER],
            'число на кнопке фильтров' => ['&per_page=1', self::BROWSER],
            'снимок страницы нашим браузером' => ['&per_page=25', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 HeadlessChrome/120.0 Safari/537.36'],
        ];
    }

    #[DataProvider('notRecordedSearches')]
    public function test_repeats_of_a_search_and_robots_are_not_recorded(string $params, string $agent): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $this->createEvent($vrn->id, $this->createCommunity($vrn->id, 'Организатор')->id, 'Вечер настольных игр', now()->addDay());

        $this->withHeader('User-Agent', $agent)
            ->getJson('/api/web/events?city=voronezh&q='.urlencode('настолки').$params)
            ->assertOk();

        $this->assertDatabaseCount('search_queries', 0);
    }

    /* ===================== interests filter (Этап 2) ===================== */

    public function test_filter_by_leaf_slug_returns_only_tagged_events(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $theatre = $this->createInterest('Театр', 'theatre');
        $jazz = $this->createInterest('Джаз', 'jazz');

        $theatreEvent = $this->createEvent($msk->id, $community->id, 'Спектакль', now()->addDay());
        $jazzEvent = $this->createEvent($msk->id, $community->id, 'Концерт', now()->addDays(2));

        $this->tagEventLeafOnly($theatreEvent->id, $theatre->id);
        $this->tagEventLeafOnly($jazzEvent->id, $jazz->id);

        $response = $this->getJson('/api/web/events?city=moskva&interests[]=theatre');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Спектакль');

        $response->assertJsonMissing(['title' => 'Концерт']);
    }

    /**
     * Ключевой тест recursive CTE: ?interests[]=music должен вернуть события
     * с тегом jazz/rock (children of music), даже если самого music-тега у
     * них нет в pivot. Seed обходит retagger (вставка напрямую в pivot
     * только leaf-тегов), иначе тест проверял бы retagger, а не CTE.
     */
    public function test_parent_slug_unfolds_to_children_via_cte(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $music = $this->createInterest('Музыка', 'music');
        $jazz = $this->createInterest('Джаз', 'jazz', $music->id);
        $rock = $this->createInterest('Рок', 'rock', $music->id);
        $theatre = $this->createInterest('Театр', 'theatre');

        $jazzEvent = $this->createEvent($msk->id, $community->id, 'Джаз-концерт', now()->addDay());
        $rockEvent = $this->createEvent($msk->id, $community->id, 'Рок-фест', now()->addDays(2));
        $theatreEvent = $this->createEvent($msk->id, $community->id, 'Спектакль', now()->addDays(3));

        // ОНЛИ leaf-тег: проверяем что CTE сам разворачивает music → {jazz,rock},
        // а не что retagger проставил parent.
        $this->tagEventLeafOnly($jazzEvent->id, $jazz->id);
        $this->tagEventLeafOnly($rockEvent->id, $rock->id);
        $this->tagEventLeafOnly($theatreEvent->id, $theatre->id);

        $response = $this->getJson('/api/web/events?city=moskva&interests[]=music&sort=created_at&dir=asc');

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('Джаз-концерт', $titles);
        $this->assertContains('Рок-фест', $titles);
        $this->assertNotContains('Спектакль', $titles);
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_nonexistent_slug_returns_empty_result(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $this->createEvent($msk->id, $community->id, 'Любое событие', now()->addDay());

        $response = $this->getJson('/api/web/events?city=moskva&interests[]=nonexistent-slug');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_without_interests_filter_returns_all_events(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $jazz = $this->createInterest('Джаз', 'jazz');
        $taggedEvent = $this->createEvent($msk->id, $community->id, 'Концерт', now()->addDay());
        $untaggedEvent = $this->createEvent($msk->id, $community->id, 'Без тегов', now()->addDays(2));
        $this->tagEventLeafOnly($taggedEvent->id, $jazz->id);

        $response = $this->getJson('/api/web/events?city=moskva');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_prefer_puts_chosen_topics_first_and_keeps_the_rest(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($vrn->id, 'Организатор');
        $music = $this->createInterest('Музыка', 'music');
        $this->createEvent($vrn->id, $community->id, 'Спектакль', now()->addDay());
        $concert = $this->createEvent($vrn->id, $community->id, 'Концерт', now()->addDays(3));
        $this->createEvent($vrn->id, $community->id, 'Лекция', now()->addDays(2));
        $this->tagEventLeafOnly($concert->id, $music->id);

        $this->getJson('/api/web/events?city=voronezh&grouped=1&grouped_by_post=1&prefer[]=music')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.preferred_total', 1)
            ->assertJsonPath('data.*.title', ['Концерт', 'Спектакль', 'Лекция'])
            ->assertJsonPath('data.*.preferred', [true, false, false]);
    }

    public function test_event_payload_contains_interests_array(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $jazz = $this->createInterest('Джаз', 'jazz');
        $taggedEvent = $this->createEvent($msk->id, $community->id, 'С тегом', now()->addDay());
        $untaggedEvent = $this->createEvent($msk->id, $community->id, 'Без тегов', now()->addDays(2));
        $this->tagEventLeafOnly($taggedEvent->id, $jazz->id);

        $response = $this->getJson('/api/web/events?city=moskva&sort=created_at&dir=asc');

        $items = $response->json('data');
        $taggedItem = collect($items)->firstWhere('title', 'С тегом');
        $untaggedItem = collect($items)->firstWhere('title', 'Без тегов');

        $this->assertIsArray($taggedItem['interests']);
        $this->assertSame([['slug' => 'jazz', 'name' => 'Джаз']], $taggedItem['interests']);

        $this->assertIsArray($untaggedItem['interests']);
        $this->assertSame([], $untaggedItem['interests'], 'untagged event must have interests: [] (not null, not omitted)');
    }

    public function test_event_payload_contains_age_restriction(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $withAge = $this->createEvent($msk->id, $community->id, 'Концерт 18+', now()->addDay());
        $noAge = $this->createEvent($msk->id, $community->id, 'Лекция', now()->addDays(2));
        DB::table('events')->where('id', $withAge->id)->update(['age_restriction' => 18]);

        $items = $this->getJson('/api/web/events?city=moskva&sort=created_at&dir=asc')->json('data');
        $withItem = collect($items)->firstWhere('title', 'Концерт 18+');
        $noItem = collect($items)->firstWhere('title', 'Лекция');

        $this->assertSame(18, $withItem['age_restriction']);
        $this->assertNull($noItem['age_restriction'], 'событие без маркера → age_restriction: null');
    }

    /* ===== double-write (legacy int + new slug) ===== */

    /**
     * Legacy фронт продолжает работать пока миграция не закончена: int ID
     * фильтрует прямым whereIn без CTE (parent НЕ разворачивается — сохраняем
     * pre-Этап-2 семантику).
     */
    public function test_legacy_int_id_filter_still_works(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $jazz = $this->createInterest('Джаз', 'jazz');
        $theatre = $this->createInterest('Театр', 'theatre');

        $jazzEvent = $this->createEvent($msk->id, $community->id, 'Концерт', now()->addDay());
        $theatreEvent = $this->createEvent($msk->id, $community->id, 'Спектакль', now()->addDays(2));
        $this->tagEventLeafOnly($jazzEvent->id, $jazz->id);
        $this->tagEventLeafOnly($theatreEvent->id, $theatre->id);

        $response = $this->getJson('/api/web/events?city=moskva&interests[]='.$jazz->id);

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Концерт');
    }

    /**
     * Legacy НЕ разворачивает иерархию (parent не цепляет children). Это
     * сохраняет pre-Этап-2 поведение — фронт мигрируется атомарно через
     * замену запросов, без сюрпризов в результатах.
     */
    public function test_legacy_int_id_filter_does_not_unfold_hierarchy(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $music = $this->createInterest('Музыка', 'music');
        $jazz = $this->createInterest('Джаз', 'jazz', $music->id);

        $jazzEvent = $this->createEvent($msk->id, $community->id, 'Джаз-концерт', now()->addDay());
        $this->tagEventLeafOnly($jazzEvent->id, $jazz->id);

        // legacy путь по parent ID не разворачивает в children
        $response = $this->getJson('/api/web/events?city=moskva&interests[]='.$music->id);

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_mixed_int_and_slug_returns_422(): void
    {
        $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $jazz = $this->createInterest('Джаз', 'jazz');

        $response = $this->getJson('/api/web/events?city=moskva&interests[]='.$jazz->id.'&interests[]=theatre');

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['interests']);
    }

    public function test_invalid_interest_format_returns_422(): void
    {
        $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);

        $response = $this->getJson('/api/web/events?city=moskva&interests[]=Театр');

        $response->assertStatus(422);
    }

    public function test_main_feed_hides_kids_and_family_audience(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $kids = $this->createEvent($msk->id, $community->id, 'Спектакль для детей', now()->addDay());
        $family = $this->createEvent($msk->id, $community->id, 'Семейный праздник', now()->addDay());
        $adult = $this->createEvent($msk->id, $community->id, 'Концерт для взрослых', now()->addDay());

        $this->setTaxonomy($kids->id, 'kids', 'culture');
        $this->setTaxonomy($family->id, 'family', 'entertainment');
        $this->setTaxonomy($adult->id, 'general', 'entertainment');

        $response = $this->getJson('/api/web/events?city=moskva');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Концерт для взрослых');

        $response->assertJsonMissing(['title' => 'Спектакль для детей']);
        $response->assertJsonMissing(['title' => 'Семейный праздник']);
    }

    public function test_main_feed_hides_official_and_other_content_kind(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $entertainment = $this->createEvent($msk->id, $community->id, 'Концерт', now()->addDay());
        $official = $this->createEvent($msk->id, $community->id, 'Заседание комиссии', now()->addDay());
        $other = $this->createEvent($msk->id, $community->id, 'Церемония награждения', now()->addDay());
        $religious = $this->createEvent($msk->id, $community->id, 'Литургия', now()->addDay());

        $this->setTaxonomy($entertainment->id, 'general', 'entertainment');
        $this->setTaxonomy($official->id, 'general', 'official');
        $this->setTaxonomy($other->id, 'general', 'other');
        $this->setTaxonomy($religious->id, 'general', 'religious');

        $response = $this->getJson('/api/web/events?city=moskva');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Концерт');
    }

    public function test_main_feed_shows_null_taxonomy_legacy_events(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $this->createEvent($msk->id, $community->id, 'Legacy event без таксономии', now()->addDay());

        $response = $this->getJson('/api/web/events?city=moskva');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Legacy event без таксономии');
    }

    public function test_include_all_overrides_taxonomy_filter(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $kids = $this->createEvent($msk->id, $community->id, 'Детский спектакль', now()->addDay());
        $official = $this->createEvent($msk->id, $community->id, 'Награждение', now()->addDay());
        $general = $this->createEvent($msk->id, $community->id, 'Концерт', now()->addDay());

        $this->setTaxonomy($kids->id, 'kids', 'culture');
        $this->setTaxonomy($official->id, 'general', 'official');
        $this->setTaxonomy($general->id, 'general', 'entertainment');

        $response = $this->getJson('/api/web/events?city=moskva&include_all=1');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(3, 'data');
    }

    /** @return array<string, array{?string, ?int, list<string>, string, bool}> */
    public static function kidsCases(): array
    {
        return [
            'детское, которое лента прячет' => ['kids', null, [], 'Буратино', true],
            'семейное' => ['family', 12, [], 'Снежная королева', true],
            'тема «детям»' => [null, null, ['kids'], 'Мастер-класс для школьников', true],
            'сказка в названии' => [null, null, [], 'Сказки у Лукоморья', true],
            'спектакль 6+' => [null, 6, ['theatre'], 'Маленький принц', true],
            'концерт 6+' => [null, 6, ['music'], 'Сольный концерт', false],
            'детское 16+' => ['kids', 16, [], 'Тени Готэма', false],
            'взрослым' => ['adults', null, ['kids'], 'Девичник', false],
            'обычный спектакль' => ['general', null, ['theatre'], 'Господа Головлёвы', false],
        ];
    }

    #[DataProvider('kidsCases')]
    public function test_kids_filter_picks_what_suits_children(?string $audience, ?int $age, array $topics, string $title, bool $shown): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $event = $this->createEvent($vrn->id, $this->createCommunity($vrn->id, 'Организатор')->id, $title, now()->addDay());
        DB::table('events')->where('id', $event->id)->update(['audience' => $audience, 'age_restriction' => $age]);
        foreach ($topics as $slug) {
            $this->tagEventLeafOnly($event->id, $this->createInterest($slug, $slug)->id);
        }

        $this->getJson('/api/web/events?city=voronezh&kids=1')->assertOk()->assertJsonPath('meta.total', $shown ? 1 : 0);
    }

    public function test_random_with_kids_picks_only_from_kids_events(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($vrn->id, 'Организатор');
        $show = $this->createEvent($vrn->id, $community->id, 'Буратино', now()->addDay());
        $this->setTaxonomy($show->id, 'kids', 'culture');
        $this->createEvent($vrn->id, $community->id, 'Рок-концерт', now()->addDay());

        $this->getJson('/api/web/events/random?city=voronezh&kids=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.title', 'Буратино');
    }

    public function test_random_with_search_picks_from_found_events(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($vrn->id, 'Организатор');
        $this->createEvent($vrn->id, $community->id, 'Квиз про кино', now()->addDay());
        $this->createEvent($vrn->id, $community->id, 'Рок-концерт', now()->addDay());

        $this->getJson('/api/web/events/random?city=voronezh&q=квиз')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.title', 'Квиз про кино');
    }

    /* ===================== related (Interests Этап 3) ===================== */

    public function test_related_ranks_by_shared_interest_count(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $jazz = $this->createInterest('Джаз', 'jazz');
        $music = $this->createInterest('Музыка', 'music');
        $theatre = $this->createInterest('Театр', 'theatre');

        $base = $this->createEvent($msk->id, $community->id, 'База', now()->addDay());
        $this->tagEventLeafOnly($base->id, $jazz->id);
        $this->tagEventLeafOnly($base->id, $music->id);

        $two = $this->createEvent($msk->id, $community->id, 'Два общих', now()->addDays(2));
        $this->tagEventLeafOnly($two->id, $jazz->id);
        $this->tagEventLeafOnly($two->id, $music->id);

        $one = $this->createEvent($msk->id, $community->id, 'Один общий', now()->addDays(3));
        $this->tagEventLeafOnly($one->id, $jazz->id);

        $zero = $this->createEvent($msk->id, $community->id, 'Ноль общих', now()->addDays(4));
        $this->tagEventLeafOnly($zero->id, $theatre->id);

        $response = $this->getJson("/api/web/events/{$base->id}/related");

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Два общих')
            ->assertJsonPath('data.1.title', 'Один общий');

        $response->assertJsonMissing(['title' => 'Ноль общих']);
        $response->assertJsonMissing(['title' => 'База']);
    }

    public function test_related_excludes_self_and_other_city(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $spb = $this->insertCity('Санкт-Петербург', 'spb', 'active', 30.3351, 59.9343);
        $mc = $this->createCommunity($msk->id, 'Орг Москва');
        $sc = $this->createCommunity($spb->id, 'Орг Питер');

        $jazz = $this->createInterest('Джаз', 'jazz');

        $base = $this->createEvent($msk->id, $mc->id, 'База', now()->addDay());
        $this->tagEventLeafOnly($base->id, $jazz->id);

        $sameCity = $this->createEvent($msk->id, $mc->id, 'Тот же город', now()->addDays(2));
        $this->tagEventLeafOnly($sameCity->id, $jazz->id);

        $otherCity = $this->createEvent($spb->id, $sc->id, 'Другой город', now()->addDays(2));
        $this->tagEventLeafOnly($otherCity->id, $jazz->id);

        $response = $this->getJson("/api/web/events/{$base->id}/related");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Тот же город');

        $response->assertJsonMissing(['title' => 'База']);
        $response->assertJsonMissing(['title' => 'Другой город']);
    }

    public function test_related_respects_main_feed_taxonomy_filter(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $jazz = $this->createInterest('Джаз', 'jazz');

        $base = $this->createEvent($msk->id, $community->id, 'База', now()->addDay());
        $this->tagEventLeafOnly($base->id, $jazz->id);

        $kids = $this->createEvent($msk->id, $community->id, 'Детское', now()->addDays(2));
        $this->tagEventLeafOnly($kids->id, $jazz->id);
        $this->setTaxonomy($kids->id, 'kids', 'culture');

        $adult = $this->createEvent($msk->id, $community->id, 'Взрослое', now()->addDays(2));
        $this->tagEventLeafOnly($adult->id, $jazz->id);
        $this->setTaxonomy($adult->id, 'general', 'entertainment');

        $response = $this->getJson("/api/web/events/{$base->id}/related");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Взрослое');

        $response->assertJsonMissing(['title' => 'Детское']);
    }

    public function test_related_empty_when_event_has_no_interests(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $jazz = $this->createInterest('Джаз', 'jazz');

        $base = $this->createEvent($msk->id, $community->id, 'Без интересов', now()->addDay());

        $other = $this->createEvent($msk->id, $community->id, 'С тегом', now()->addDays(2));
        $this->tagEventLeafOnly($other->id, $jazz->id);

        $response = $this->getJson("/api/web/events/{$base->id}/related");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_related_excludes_past_events(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 22, 12, 0, 0, 'Europe/Moscow'));

        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');

        $jazz = $this->createInterest('Джаз', 'jazz');

        $base = $this->createEvent($msk->id, $community->id, 'База', Carbon::create(2026, 3, 23, 18, 0, 0, 'Europe/Moscow'));
        $this->tagEventLeafOnly($base->id, $jazz->id);

        // -10 дней от now → вне future-окна (PAST_LOOKBACK_DAYS=7)
        $past = $this->createEvent($msk->id, $community->id, 'Прошедшее', Carbon::create(2026, 3, 12, 18, 0, 0, 'Europe/Moscow'));
        $this->tagEventLeafOnly($past->id, $jazz->id);

        $future = $this->createEvent($msk->id, $community->id, 'Будущее', Carbon::create(2026, 3, 24, 18, 0, 0, 'Europe/Moscow'));
        $this->tagEventLeafOnly($future->id, $jazz->id);

        $response = $this->getJson("/api/web/events/{$base->id}/related");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Будущее');

        $response->assertJsonMissing(['title' => 'Прошедшее']);
    }

    /* ===================== companions (по площадке/организатору) ===================== */

    public function test_companions_returns_same_venue_events(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $venue = $this->createVenue($msk->id, 'ДК Икс', 'dk-iks');

        $base = $this->createEvent($msk->id, $community->id, 'База', now()->addDay(), $venue->id);
        $this->createEvent($msk->id, $community->id, 'Сосед 1', now()->addDays(2), $venue->id);
        $this->createEvent($msk->id, $community->id, 'Сосед 2', now()->addDays(3), $venue->id);

        $response = $this->getJson("/api/web/events/{$base->id}/companions");

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.scope', 'venue')
            ->assertJsonPath('meta.label', 'Ещё на этой площадке')
            ->assertJsonPath('meta.venue.id', $venue->id);

        $response->assertJsonMissing(['title' => 'База']);
    }

    public function test_companions_falls_back_to_community_when_no_venue(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Театр Один');

        // база без площадки → скоуп community
        $base = $this->createEvent($msk->id, $community->id, 'База', now()->addDay());
        $this->createEvent($msk->id, $community->id, 'От того же организатора', now()->addDays(2));

        $response = $this->getJson("/api/web/events/{$base->id}/companions");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'От того же организатора')
            ->assertJsonPath('meta.scope', 'community')
            ->assertJsonPath('meta.label', 'Ещё у организатора')
            ->assertJsonPath('meta.community_name', 'Театр Один');
    }

    public function test_companions_empty_for_aggregator_community_without_venue(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $agg = $this->createCommunity($msk->id, 'Яндекс.Афиша');
        DB::table('communities')->where('id', $agg->id)->update([
            'verification_meta' => json_encode(['final' => ['kind' => 'aggregator']]),
        ]);

        $base = $this->createEvent($msk->id, $agg->id, 'База', now()->addDay());
        $this->createEvent($msk->id, $agg->id, 'Другое из фида', now()->addDays(2));

        $response = $this->getJson("/api/web/events/{$base->id}/companions");

        // агрегатор без площадки → фолбэка на «организатора» нет (это шум-лента), пусто
        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.scope', null);
    }

    public function test_companions_venue_scope_works_even_for_aggregator_source(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $agg = $this->createCommunity($msk->id, 'Яндекс.Афиша');
        DB::table('communities')->where('id', $agg->id)->update([
            'verification_meta' => json_encode(['final' => ['kind' => 'aggregator']]),
        ]);
        $venue = $this->createVenue($msk->id, 'ДК Икс', 'dk-iks');

        $base = $this->createEvent($msk->id, $agg->id, 'База', now()->addDay(), $venue->id);
        $this->createEvent($msk->id, $agg->id, 'Сосед на площадке', now()->addDays(2), $venue->id);

        // площадка бьёт агрегатор-гейт: venue-scope работает даже если источник — агрегатор
        $response = $this->getJson("/api/web/events/{$base->id}/companions");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Сосед на площадке')
            ->assertJsonPath('meta.scope', 'venue');
    }

    public function test_companions_venue_first_ignores_other_venue_of_same_community(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $venueA = $this->createVenue($msk->id, 'Площадка А', 'ploshadka-a');
        $venueB = $this->createVenue($msk->id, 'Площадка Б', 'ploshadka-b');

        $base = $this->createEvent($msk->id, $community->id, 'База', now()->addDay(), $venueA->id);
        $this->createEvent($msk->id, $community->id, 'Тоже на А', now()->addDays(2), $venueA->id);
        $this->createEvent($msk->id, $community->id, 'На Б того же орга', now()->addDays(2), $venueB->id);

        $response = $this->getJson("/api/web/events/{$base->id}/companions");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Тоже на А');

        $response->assertJsonMissing(['title' => 'На Б того же орга']);
    }

    public function test_companions_excludes_self_and_same_event_group(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $venue = $this->createVenue($msk->id, 'ДК Икс', 'dk-iks');

        $base = $this->createEvent($msk->id, $community->id, 'База', now()->addDay(), $venue->id);
        // повтор того же события (тот же event_group_id) — не сосед
        $repeat = $this->createEvent($msk->id, $community->id, 'База (повтор)', now()->addDays(5), $venue->id);
        $gid = DB::table('event_groups')->insertGetId([
            'community_id' => $community->id,
            'city_id' => $msk->id,
            'group_key' => 'test-companions-grp',
            'title_norm' => 'база',
            'current_event_id' => $base->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('events')->whereIn('id', [$base->id, $repeat->id])->update(['event_group_id' => $gid]);

        $this->createEvent($msk->id, $community->id, 'Настоящий сосед', now()->addDays(2), $venue->id);

        $response = $this->getJson("/api/web/events/{$base->id}/companions");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Настоящий сосед');

        $response->assertJsonMissing(['title' => 'База (повтор)']);
        $response->assertJsonMissing(['title' => 'База']);
    }

    public function test_companions_excludes_past_events(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 22, 12, 0, 0, 'Europe/Moscow'));

        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $venue = $this->createVenue($msk->id, 'ДК Икс', 'dk-iks');

        $base = $this->createEvent($msk->id, $community->id, 'База', Carbon::create(2026, 3, 23, 18, 0, 0, 'Europe/Moscow'), $venue->id);
        // -10 дней от now → вне future-окна (PAST_LOOKBACK_DAYS=7)
        $this->createEvent($msk->id, $community->id, 'Прошедшее', Carbon::create(2026, 3, 12, 18, 0, 0, 'Europe/Moscow'), $venue->id);
        $this->createEvent($msk->id, $community->id, 'Будущее', Carbon::create(2026, 3, 24, 18, 0, 0, 'Europe/Moscow'), $venue->id);

        $response = $this->getJson("/api/web/events/{$base->id}/companions");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Будущее');

        $response->assertJsonMissing(['title' => 'Прошедшее']);
    }

    public function test_companions_respects_main_feed_taxonomy_filter(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $venue = $this->createVenue($msk->id, 'ДК Икс', 'dk-iks');

        $base = $this->createEvent($msk->id, $community->id, 'База', now()->addDay(), $venue->id);

        $kids = $this->createEvent($msk->id, $community->id, 'Детское', now()->addDays(2), $venue->id);
        $this->setTaxonomy($kids->id, 'kids', 'culture');

        $adult = $this->createEvent($msk->id, $community->id, 'Взрослое', now()->addDays(2), $venue->id);
        $this->setTaxonomy($adult->id, 'general', 'entertainment');

        $response = $this->getJson("/api/web/events/{$base->id}/companions");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Взрослое');

        $response->assertJsonMissing(['title' => 'Детское']);
    }

    public function test_companions_empty_when_only_self_at_venue(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $venue = $this->createVenue($msk->id, 'ДК Икс', 'dk-iks');

        $base = $this->createEvent($msk->id, $community->id, 'База', now()->addDay(), $venue->id);

        $response = $this->getJson("/api/web/events/{$base->id}/companions");

        // соседей нет, но скоуп известен — data пуст, meta заполнен (фронт скрывает рейл)
        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.scope', 'venue');
    }

    public function test_companions_nonexistent_id_returns_empty_not_404(): void
    {
        $response = $this->getJson('/api/web/events/999999/companions');

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.scope', null);
    }

    public function test_companions_orders_by_nearest_date(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $venue = $this->createVenue($msk->id, 'ДК Икс', 'dk-iks');

        $base = $this->createEvent($msk->id, $community->id, 'База', now()->addDay(), $venue->id);
        $this->createEvent($msk->id, $community->id, 'Через 4 дня', now()->addDays(4), $venue->id);
        $this->createEvent($msk->id, $community->id, 'Через 2 дня', now()->addDays(2), $venue->id);
        $this->createEvent($msk->id, $community->id, 'Через 3 дня', now()->addDays(3), $venue->id);

        $response = $this->getJson("/api/web/events/{$base->id}/companions");

        $response
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.title', 'Через 2 дня')
            ->assertJsonPath('data.1.title', 'Через 3 дня')
            ->assertJsonPath('data.2.title', 'Через 4 дня');
    }

    /* ===================== next (блоки «что дальше») ===================== */

    public function test_next_keeps_teen_and_adult_shows_away_from_kids_event(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 22, 12, 0, 0, 'Europe/Moscow'));

        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $theatre = $this->createInterest('Театр', 'theatre');
        $kids = $this->createInterest('Детям', 'kids');
        $hall = $this->createVenue($msk->id, 'Саквояж', 'sakvoyazh');

        $base = $this->createEvent($msk->id, $community->id, 'Книжная девочка', Carbon::create(2026, 3, 23, 15, 0, 0, 'Europe/Moscow')->utc(), $hall->id);
        $this->tagEventLeafOnly($base->id, $theatre->id);
        $this->tagEventLeafOnly($base->id, $kids->id);

        $tale = $this->createEvent($msk->id, $community->id, 'Сказка', Carbon::create(2026, 3, 25, 12, 0, 0, 'Europe/Moscow')->utc());
        $this->tagEventLeafOnly($tale->id, $theatre->id);
        $this->tagEventLeafOnly($tale->id, $kids->id);
        $this->setTaxonomy($tale->id, 'kids', 'culture');

        foreach (['Жена Хармса' => 18, 'Как дети' => 12] as $title => $age) {
            $e = $this->createEvent($msk->id, $community->id, $title, Carbon::create(2026, 3, 24, 19, 0, 0, 'Europe/Moscow')->utc());
            $this->tagEventLeafOnly($e->id, $theatre->id);
            DB::table('events')->where('id', $e->id)->update(['age_restriction' => $age]);
        }
        $adults = $this->createEvent($msk->id, $community->id, 'Кафка', Carbon::create(2026, 3, 24, 20, 0, 0, 'Europe/Moscow')->utc());
        $this->tagEventLeafOnly($adults->id, $theatre->id);
        $this->setTaxonomy($adults->id, 'adults', 'culture');

        $similar = collect($this->getJson("/api/web/events/{$base->id}/next")->assertOk()->json('data.similar'));

        $this->assertSame(['Сказка'], $similar->pluck('title')->all());
        $this->assertSame('тоже для детей', $similar->first()['reason']);
    }

    public function test_next_venue_block_shows_kids_shows_only_next_to_kids_event(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $venue = $this->createVenue($msk->id, 'Саквояж', 'sakvoyazh');

        $base = $this->createEvent($msk->id, $community->id, 'Книжная девочка', now()->addDay(), $venue->id);
        $this->setTaxonomy($base->id, 'kids', 'culture');
        $buratino = $this->createEvent($msk->id, $community->id, 'Буратино', now()->addDays(2), $venue->id);
        $this->setTaxonomy($buratino->id, 'kids', 'culture');
        $adult = $this->createEvent($msk->id, $community->id, 'Вечер романса', now()->addDays(3), $venue->id);

        $this->getJson("/api/web/events/{$base->id}/next")
            ->assertOk()
            ->assertJsonPath('data.companions.0.title', 'Буратино')
            ->assertJsonPath('meta.companions.scope', 'venue');

        $this->getJson("/api/web/events/{$adult->id}/next")
            ->assertOk()
            ->assertJsonCount(0, 'data.companions');
    }

    public function test_next_counts_genre_as_its_topic(): void
    {
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $theatre = $this->createInterest('Театр', 'theatre');
        $opera = $this->createInterest('Опера и балет', 'opera-ballet', $theatre->id);
        $hall = $this->createVenue($msk->id, 'Оперный', 'opera');

        $base = $this->createEvent($msk->id, $community->id, 'Трубадур', now()->addDay(), $hall->id);
        $this->tagEventLeafOnly($base->id, $opera->id);
        $play = $this->createEvent($msk->id, $community->id, 'Ревизор', now()->addDays(2));
        $this->tagEventLeafOnly($play->id, $theatre->id);

        $this->getJson("/api/web/events/{$base->id}/next")
            ->assertOk()
            ->assertJsonPath('data.similar.0.title', 'Ревизор')
            ->assertJsonPath('data.similar.0.reason', 'тоже театр');
    }

    public function test_next_similar_stays_near_the_event_day(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 22, 12, 0, 0, 'Europe/Moscow'));

        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $music = $this->createInterest('Музыка', 'music');
        $hall = $this->createVenue($msk->id, 'Филармония', 'phil');

        $base = $this->createEvent($msk->id, $community->id, 'База', Carbon::create(2026, 3, 23, 19, 0, 0, 'Europe/Moscow')->utc(), $hall->id);
        $this->tagEventLeafOnly($base->id, $music->id);
        $dates = [
            'Вчера' => Carbon::create(2026, 3, 21, 19, 0, 0, 'Europe/Moscow')->utc(),
            'Через два дня' => Carbon::create(2026, 3, 25, 19, 0, 0, 'Europe/Moscow')->utc(),
            'Через три недели' => Carbon::create(2026, 4, 13, 19, 0, 0, 'Europe/Moscow')->utc(),
        ];
        foreach ($dates as $title => $start) {
            $e = $this->createEvent($msk->id, $community->id, $title, $start);
            $this->tagEventLeafOnly($e->id, $music->id);
        }

        $similar = $this->getJson("/api/web/events/{$base->id}/next")->assertOk()->json('data.similar');

        $this->assertSame(['Через два дня'], array_column($similar, 'title'));
    }

    public function test_next_shows_one_card_per_thing_across_blocks(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 22, 9, 0, 0, 'Europe/Moscow'));

        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $games = $this->createInterest('Квизы и игры', 'quiz-games');
        $quest = $this->createVenue($msk->id, 'Квест-комнаты', 'quest');
        $bar = $this->createVenue($msk->id, 'Бар', 'bar');

        $base = $this->createEvent($msk->id, $community->id, 'Квиз', Carbon::create(2026, 3, 22, 19, 0, 0, 'Europe/Moscow')->utc(), $bar->id);
        $this->tagEventLeafOnly($base->id, $games->id);
        foreach ([[22, 12], [22, 14], [22, 16], [23, 12]] as [$day, $hour]) {
            $e = $this->createEvent($msk->id, $community->id, 'Убежище 216', Carbon::create(2026, 3, $day, $hour, 0, 0, 'Europe/Moscow')->utc(), $quest->id);
            $this->tagEventLeafOnly($e->id, $games->id);
        }

        $data = $this->getJson("/api/web/events/{$base->id}/next")->assertOk()->json('data');
        $titles = array_column(array_merge($data['same_day'], $data['companions'], $data['similar']), 'title');

        $this->assertSame(['Убежище 216'], $titles);
    }

    public function test_next_same_day_puts_similar_events_first(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 22, 9, 0, 0, 'Europe/Moscow'));

        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $music = $this->createInterest('Музыка', 'music');
        $theatre = $this->createInterest('Театр', 'theatre');
        $garden = $this->createVenue($msk->id, 'Сад', 'garden');

        $base = $this->createEvent($msk->id, $community->id, 'Джаз в саду', Carbon::create(2026, 3, 22, 19, 0, 0, 'Europe/Moscow')->utc(), $garden->id);
        $this->tagEventLeafOnly($base->id, $music->id);
        $play = $this->createEvent($msk->id, $community->id, 'Утренний спектакль', Carbon::create(2026, 3, 22, 12, 0, 0, 'Europe/Moscow')->utc());
        $this->tagEventLeafOnly($play->id, $theatre->id);
        $concert = $this->createEvent($msk->id, $community->id, 'Поздний концерт', Carbon::create(2026, 3, 22, 21, 0, 0, 'Europe/Moscow')->utc());
        $this->tagEventLeafOnly($concert->id, $music->id);

        $this->getJson("/api/web/events/{$base->id}/next")
            ->assertOk()
            ->assertJsonPath('meta.day', '2026-03-22')
            ->assertJsonPath('data.same_day.0.title', 'Поздний концерт')
            ->assertJsonPath('data.same_day.0.reason', 'тоже музыка')
            ->assertJsonPath('data.same_day.1.title', 'Утренний спектакль');
    }

    public function test_next_skips_the_event_itself_written_by_another_source(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 22, 9, 0, 0, 'Europe/Moscow'));

        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $jazz = $this->createInterest('Джаз', 'jazz');
        $hall = $this->createVenue($msk->id, 'Филармония', 'phil');
        $at = Carbon::create(2026, 3, 23, 18, 0, 0, 'Europe/Moscow')->utc();

        $base = $this->createEvent($msk->id, $community->id, 'Танцы с оркестром', $at, $hall->id);
        $this->tagEventLeafOnly($base->id, $jazz->id);
        $copy = $this->createEvent($msk->id, $community->id, 'Концерт «Танцы с Большим джазовым оркестром»', $at->copy(), $hall->id);
        $this->tagEventLeafOnly($copy->id, $jazz->id);

        $data = $this->getJson("/api/web/events/{$base->id}/next")->assertOk()->json('data');

        $this->assertSame([], array_merge($data['same_day'], $data['companions'], $data['similar']));
    }

    public function test_next_same_day_prefers_events_near_the_hour(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 22, 9, 0, 0, 'Europe/Moscow'));

        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $club = $this->createVenue($msk->id, 'Клуб', 'club');

        $base = $this->createEvent($msk->id, $community->id, 'Стендап', Carbon::create(2026, 3, 23, 20, 0, 0, 'Europe/Moscow')->utc(), $club->id);
        $this->createEvent($msk->id, $community->id, 'Утренняя выставка', Carbon::create(2026, 3, 23, 10, 0, 0, 'Europe/Moscow')->utc());
        $this->createEvent($msk->id, $community->id, 'Вечернее шоу', Carbon::create(2026, 3, 23, 19, 0, 0, 'Europe/Moscow')->utc());

        $this->getJson("/api/web/events/{$base->id}/next")
            ->assertOk()
            ->assertJsonPath('data.same_day.0.title', 'Вечернее шоу')
            ->assertJsonPath('data.same_day.1.title', 'Утренняя выставка');
    }

    public function test_next_same_day_card_shows_its_own_day_not_the_series(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 22, 9, 0, 0, 'Europe/Moscow'));

        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $community = $this->createCommunity($msk->id, 'Организатор');
        $games = $this->createInterest('Квизы и игры', 'quiz-games');
        $bar = $this->createVenue($msk->id, 'Бар', 'bar');

        $base = $this->createEvent($msk->id, $community->id, 'Квиз', Carbon::create(2026, 3, 23, 19, 0, 0, 'Europe/Moscow')->utc(), $bar->id);
        $this->tagEventLeafOnly($base->id, $games->id);

        $groupId = DB::table('event_groups')->insertGetId([
            'community_id' => $community->id,
            'city_id' => $msk->id,
            'group_key' => 'eg:test:igroteka',
            'title_norm' => 'игротека',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([22, 23] as $day) {
            $e = $this->createEvent($msk->id, $community->id, 'Игротека', Carbon::create(2026, 3, $day, 19, 0, 0, 'Europe/Moscow')->utc());
            $this->tagEventLeafOnly($e->id, $games->id);
            DB::table('events')->where('id', $e->id)->update(['event_group_id' => $groupId]);
        }

        $this->getJson("/api/web/events/{$base->id}/next")
            ->assertOk()
            ->assertJsonPath('data.same_day.0.title', 'Игротека')
            ->assertJsonPath('data.same_day.0.start_date', '2026-03-23')
            ->assertJsonPath('data.same_day.0.group', null)
            ->assertJsonCount(0, 'data.similar');
    }

    /* ===================== helpers ===================== */

    private function createInterest(string $name, string $slug, ?int $parentId = null): Interest
    {
        return Interest::create([
            'name' => $name,
            'slug' => $slug,
            'parent_id' => $parentId,
        ]);
    }

    /**
     * Прямая вставка в pivot — обходит retagger, который проставил бы и
     * parent-теги. Нужно для теста CTE-разворота parent → children.
     */
    private function tagEventLeafOnly(int $eventId, int $interestId): void
    {
        DB::table('event_interest')->insert([
            'event_id' => $eventId,
            'interest_id' => $interestId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_sort_top_ranks_richer_events_first(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Тест');
        $venue = $this->createVenue($city->id, 'Зал', 'zal');

        $when = Carbon::now()->addDays(5)->setTime(19, 0);

        // Богатое: FIAS + venue + цена + длинное описание + точное время → высокий score
        $rich = $this->createEvent($city->id, $community->id, 'Богатое', $when->copy(), $venue->id);
        $rich->house_fias_id = 'fias-123';
        $rich->price_status = 'priced';
        $rich->price_min = 500;
        $rich->description = str_repeat('а ', 80); // >120 символов
        $rich->time_precision = 'datetime';
        $rich->save();

        // Бедное: ничего, та же дата → нулевой score
        $poor = $this->createEvent($city->id, $community->id, 'Бедное', $when->copy());
        $poor->time_precision = 'date';
        $poor->save();

        $titles = array_column(
            $this->getJson("/api/web/events?city_id={$city->id}&sort=top&grouped=0&grouped_by_post=0")
                ->assertOk()->json('data'),
            'title',
        );

        $this->assertContains('Богатое', $titles);
        $this->assertContains('Бедное', $titles);
        $this->assertLessThan(
            array_search('Бедное', $titles),
            array_search('Богатое', $titles),
            'sort=top должен ставить богатое событие раньше бедного',
        );
    }

    public function test_sort_top_collapses_same_event_group_into_one_card(): void
    {
        // Регресс «задвоенной ленты»: sort=top без grouped=1 (v2-виджеты главной)
        // раньше возвращал каждый сеанс одной event_group отдельной карточкой.
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Тест');
        $when = Carbon::now()->addDays(5);

        // Два сеанса одной экскурсии (один event_group_id) — должна быть ОДНА карточка.
        $a = $this->createEvent($city->id, $community->id, 'Экскурсия', $when->copy()->setTime(9, 0));
        $b = $this->createEvent($city->id, $community->id, 'Экскурсия', $when->copy()->setTime(11, 30));
        $gid = DB::table('event_groups')->insertGetId([
            'community_id' => $community->id,
            'city_id' => $city->id,
            'group_key' => 'test-excursion-grp',
            'title_norm' => 'экскурсия',
            'current_event_id' => $a->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('events')->whereIn('id', [$a->id, $b->id])->update(['event_group_id' => $gid]);

        $data = $this->getJson("/api/web/events?city_id={$city->id}&sort=top")
            ->assertOk()->json('data');

        $sameGroup = array_filter($data, fn ($e) => ($e['title'] ?? '') === 'Экскурсия');
        $this->assertCount(
            1,
            $sameGroup,
            'sort=top должен схлопывать сеансы одной event_group в одну карточку',
        );
    }

    public function test_sort_top_collapses_federated_groups_across_communities(): void
    {
        // Cross-community федерация (MVP-B): один фестиваль из двух пабликов =
        // два события, две group (разные community_id → разный group_key), но
        // связаны federation_id → ОДНА карточка на ленте.
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $c1 = $this->createCommunity($city->id, 'Паблик 1');
        $c2 = $this->createCommunity($city->id, 'Паблик 2');
        $when = Carbon::now()->addDays(5)->setTime(18, 0);

        $e1 = $this->createEvent($city->id, $c1->id, 'Платоновский фестиваль', $when->copy());
        $e2 = $this->createEvent($city->id, $c2->id, 'Платоновский фестиваль', $when->copy());

        // g1 — канон федерации (federation_id NULL → COALESCE даёт сам g1);
        // g2 — член, federation_id = g1.
        $g1 = DB::table('event_groups')->insertGetId([
            'community_id' => $c1->id, 'city_id' => $city->id,
            'group_key' => 'fed-canon', 'title_norm' => 'платоновский фестиваль',
            'current_event_id' => $e1->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $g2 = DB::table('event_groups')->insertGetId([
            'community_id' => $c2->id, 'city_id' => $city->id,
            'group_key' => 'fed-member', 'title_norm' => 'платоновский фестиваль',
            'current_event_id' => $e2->id, 'federation_id' => $g1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('events')->where('id', $e1->id)->update(['event_group_id' => $g1]);
        DB::table('events')->where('id', $e2->id)->update(['event_group_id' => $g2]);

        $data = $this->getJson("/api/web/events?city_id={$city->id}&sort=top")
            ->assertOk()->json('data');

        $fest = array_values(array_filter($data, fn ($e) => ($e['title'] ?? '') === 'Платоновский фестиваль'));
        $this->assertCount(1, $fest, 'федерированные группы разных сообществ → одна карточка');
        $this->assertSame(2, (int) ($fest[0]['group']['count'] ?? 0), 'group.count считается по всей федерации');
    }

    public function test_sort_top_freshness_bonus_lifts_recently_added(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Тест');
        $when = Carbon::now()->addDays(4)->setTime(19, 0);

        // Идентичные события, отличается только created_at → свежесть-бонус решает.
        // created_at выставляем через Postgres now() (тот же now(), что в addTopScore SQL),
        // иначе Carbon-время теста рассинхронено с wall-clock БД.
        $stale = $this->createEvent($city->id, $community->id, 'Старое', $when->copy());
        $fresh = $this->createEvent($city->id, $community->id, 'Свежее', $when->copy());
        DB::table('events')->where('id', $stale->id)->update(['created_at' => DB::raw("now() - interval '20 days'")]);
        DB::table('events')->where('id', $fresh->id)->update(['created_at' => DB::raw('now()')]);

        $titles = array_column(
            $this->getJson("/api/web/events?city_id={$city->id}&sort=top")->assertOk()->json('data'),
            'title',
        );

        $this->assertLessThan(
            array_search('Старое', $titles),
            array_search('Свежее', $titles),
            'свежее событие должно стоять выше идентичного, но давно добавленного',
        );
    }

    public function test_sort_top_diversifies_content_kind(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Тест');
        $when = Carbon::now()->addDays(4)->setTime(19, 0);

        // 3 entertainment + 2 culture одинакового веса: без diversity шли бы
        // 3 entertainment подряд. Должно перемешаться (≤2 подряд одного kind).
        $kinds = ['entertainment', 'entertainment', 'entertainment', 'culture', 'culture'];
        foreach ($kinds as $i => $ck) {
            $e = $this->createEvent($city->id, $community->id, "Событие $i", $when->copy());
            $this->setTaxonomy($e->id, 'general', $ck);
        }

        $data = $this->getJson("/api/web/events?city_id={$city->id}&sort=top&per_page=10")
            ->assertOk()->json('data');

        $kindSeq = array_map(fn ($e) => $e['content_kind'] ?? null, $data);
        $maxRun = 1;
        $run = 1;
        for ($i = 1; $i < count($kindSeq); $i++) {
            $run = ($kindSeq[$i] !== null && $kindSeq[$i] === $kindSeq[$i - 1]) ? $run + 1 : 1;
            $maxRun = max($maxRun, $run);
        }
        $this->assertLessThanOrEqual(2, $maxRun, 'не должно быть >2 подряд одного content_kind');
        $this->assertContains('culture', $kindSeq);
        $this->assertContains('entertainment', $kindSeq);
    }

    public function test_web_event_start_at_returned_in_moscow_time(): void
    {
        // start_time хранится в UTC; API должен отдавать start_at в МСК с offset,
        // иначе карточки (regex HH:MM из строки) показывают UTC = на 3 часа меньше.
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Тест');
        $when = Carbon::now('UTC')->addDays(5)->setTime(16, 0, 0); // 16:00 UTC → 19:00 МСК
        $this->createEvent($city->id, $community->id, 'TZ-проверка', $when);

        $data = $this->getJson("/api/web/events?city_id={$city->id}")->assertOk()->json('data');
        $row = collect($data)->firstWhere('title', 'TZ-проверка');

        $this->assertNotNull($row, 'событие в ленте');
        $this->assertStringEndsWith('+03:00', (string) $row['start_at'], 'start_at в МСК-offset');
        $this->assertStringContainsString('T19:00', (string) $row['start_at'], '16:00 UTC → 19:00 МСК');
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

    private function createCommunity(int $cityId, string $name): Community
    {
        return Community::create([
            'name' => $name,
            'city_id' => $cityId,
        ]);
    }

    /**
     * ДАТЫ СЕРИИ ОТДАЮТСЯ В МСК, как и верхний start_at.
     *
     * Фронт местами вырезает часы прямо из строки — EventFeedSeoList,
     * VenueSchedule, KTicketCard, всего семь мест. Даты серии собирались через
     * toISOString(), то есть в UTC с микросекундами, и одна и та же серия
     * уезжала двумя записями сразу: «Бальзаминов» представителем
     * 2026-09-23T19:00:00+03:00, а датами серии 2026-09-24T16:00:00.000000Z —
     * тот же вечер, другой формат и время на три часа раньше при срезе.
     */
    public function test_group_dates_use_moscow_offset_like_the_top_level(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 06:00:00', 'Europe/Moscow'));

        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.20, 51.67);
        $community = $this->createCommunity($city->id, 'Театр');

        $first = $this->createEvent($city->id, $community->id, 'Бальзаминов', Carbon::parse('2026-09-23 19:00:00', 'Europe/Moscow'));
        $second = $this->createEvent($city->id, $community->id, 'Бальзаминов', Carbon::parse('2026-09-24 19:00:00', 'Europe/Moscow'));

        $groupId = DB::table('event_groups')->insertGetId([
            'community_id' => $community->id,
            'city_id' => $city->id,
            'group_key' => 'eg:test:balzaminov',
            'title_norm' => 'бальзаминов',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('events')->whereIn('id', [$first->id, $second->id])->update(['event_group_id' => $groupId]);

        $data = $this->getJson('/api/web/events?city=voronezh&grouped=1')->assertOk()->json('data');

        $row = collect($data)->firstWhere('title', 'Бальзаминов');
        $this->assertNotNull($row, 'карточка серии не пришла');

        $dates = $row['group']['dates'] ?? [];
        $this->assertNotEmpty($dates, 'дат серии нет — проверять нечего');

        // Верхний start_at — эталон: фронт читает оба поля одним кодом, и
        // разойтись они не имеют права.
        $top = (string) $row['start_at'];
        $this->assertStringEndsWith('+03:00', $top);

        foreach ($dates as $d) {
            $startAt = $d['start_at'] ?? null;
            if ($startAt === null) {
                continue;
            }

            $this->assertStringEndsWith('+03:00', $startAt, 'дата серии отдана не в МСК: '.$startAt);
            $this->assertStringNotContainsString('.000000', $startAt, 'микросекунды в дате серии: '.$startAt);
            $this->assertSame(
                substr($top, 11, 5),
                substr($startAt, 11, 5),
                'срез часов у даты серии расходится с верхним start_at: '.$startAt.' против '.$top,
            );
        }
    }

    private function createEvent(int $cityId, int $communityId, string $title, Carbon $startTime, ?int $venueId = null): Event
    {
        $event = new Event;
        $event->community_id = $communityId;
        $event->title = $title;
        $event->status = 'active';
        $event->city_id = $cityId;
        $event->start_time = $startTime;
        $event->start_date = $startTime->toDateString();
        $event->venue_id = $venueId;
        $event->save();

        return $event;
    }

    private function createVenue(int $cityId, string $name, string $slug): Venue
    {
        return Venue::create([
            'city_id' => $cityId,
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    private function attachPhoto(int $eventId, int $communityId): void
    {
        $networkId = DB::table('social_networks')->insertGetId(['name' => 'vk', 'slug' => 'vk-'.$eventId]);
        $linkId = DB::table('community_social_links')->insertGetId([
            'community_id' => $communityId,
            'social_network_id' => $networkId,
            'url' => 'https://vk.com/src_'.$eventId,
            'status' => 'active',
        ]);
        DB::table('event_sources')->insert([
            'event_id' => $eventId,
            'social_link_id' => $linkId,
            'source' => 'vk',
            'post_external_id' => 'post-'.$eventId,
            'images' => json_encode(['https://example.com/poster.jpg']),
        ]);
    }

    private function setTaxonomy(int $eventId, ?string $audience, ?string $contentKind): void
    {
        DB::table('events')->where('id', $eventId)->update([
            'audience' => $audience,
            'content_kind' => $contentKind,
            'updated_at' => now(),
        ]);
    }
}
