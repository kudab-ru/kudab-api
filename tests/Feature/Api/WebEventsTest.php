<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
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

        $response = $this->getJson('/api/web/events?city=moskva&community_id=' . $wantedCommunity->id);

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Нужное событие');

        $response->assertJsonMissing([
            'title' => 'Чужое событие',
        ]);
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

    private function createEvent(int $cityId, int $communityId, string $title, Carbon $startTime): Event
    {
        $event = new Event();
        $event->community_id = $communityId;
        $event->title = $title;
        $event->status = 'active';
        $event->city_id = $cityId;
        $event->start_time = $startTime;
        $event->start_date = $startTime->toDateString();
        $event->save();

        return $event;
    }
}
