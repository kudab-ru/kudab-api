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
use Tests\TestCase;

class WebEventsTest extends TestCase
{
    use RefreshDatabase;

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

    private function setTaxonomy(int $eventId, ?string $audience, ?string $contentKind): void
    {
        DB::table('events')->where('id', $eventId)->update([
            'audience' => $audience,
            'content_kind' => $contentKind,
            'updated_at' => now(),
        ]);
    }
}
