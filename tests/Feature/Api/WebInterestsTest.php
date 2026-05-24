<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use App\Models\Interest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Этап 2: каталог интересов (плоский массив, дерево строит фронт).
 *
 *   GET /api/web/interests              — все, events_count = глобал
 *   GET /api/web/interests?city=        — events_count по городу
 *   GET /api/web/interests?parent_slug= — только children parent'а
 *   GET /api/web/interests?q=           — ILIKE по name
 */
class WebInterestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_catalog_returns_interests_with_parent_slug(): void
    {
        $music = $this->createInterest('Музыка', 'music');
        $jazz  = $this->createInterest('Джаз', 'jazz', $music->id);

        $response = $this->getJson('/api/web/interests');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'slug', 'name', 'parent_id', 'parent_slug', 'events_count'],
                ],
            ]);

        // Найти music (parent) — parent_slug=null
        $items = $response->json('data');
        $musicRow = collect($items)->firstWhere('slug', 'music');
        $this->assertNotNull($musicRow, 'music should be in catalog');
        $this->assertNull($musicRow['parent_slug']);

        // Найти jazz (child) — parent_slug=music
        $jazzRow = collect($items)->firstWhere('slug', 'jazz');
        $this->assertNotNull($jazzRow, 'jazz should be in catalog');
        $this->assertSame('music', $jazzRow['parent_slug']);
    }

    public function test_events_count_is_scoped_by_city_slug(): void
    {
        $vrn = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $msk = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);

        $jazz = $this->createInterest('Джаз', 'jazz');

        $vrnCom = $this->createCommunity($vrn->id, 'Воронежский джаз');
        $mskCom = $this->createCommunity($msk->id, 'Московский джаз');

        $vrnEvent = $this->createEvent($vrn->id, $vrnCom->id, 'Воронеж концерт', now()->addDay());
        $mskEvent1 = $this->createEvent($msk->id, $mskCom->id, 'Москва концерт 1', now()->addDay());
        $mskEvent2 = $this->createEvent($msk->id, $mskCom->id, 'Москва концерт 2', now()->addDays(2));

        $this->tagEvent($vrnEvent->id, $jazz->id);
        $this->tagEvent($mskEvent1->id, $jazz->id);
        $this->tagEvent($mskEvent2->id, $jazz->id);

        $vrnResponse = $this->getJson('/api/web/interests?city=voronezh');
        $jazzVrn = collect($vrnResponse->json('data'))->firstWhere('slug', 'jazz');
        $this->assertSame(1, $jazzVrn['events_count'], 'voronezh scope should count 1 event');

        $mskResponse = $this->getJson('/api/web/interests?city=moskva');
        $jazzMsk = collect($mskResponse->json('data'))->firstWhere('slug', 'jazz');
        $this->assertSame(2, $jazzMsk['events_count'], 'moskva scope should count 2 events');

        $allResponse = $this->getJson('/api/web/interests');
        $jazzAll = collect($allResponse->json('data'))->firstWhere('slug', 'jazz');
        $this->assertSame(3, $jazzAll['events_count'], 'no scope should count 3 events');
    }

    public function test_q_filter_uses_ilike_on_name(): void
    {
        $this->createInterest('Театр', 'teatr');
        $this->createInterest('Театральная классика', 'teatr-classic');
        $this->createInterest('Музыка', 'music');

        $response = $this->getJson('/api/web/interests?q=театр');

        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('teatr', $slugs);
        $this->assertContains('teatr-classic', $slugs);
        $this->assertNotContains('music', $slugs);
    }

    public function test_parent_slug_returns_only_children(): void
    {
        $music = $this->createInterest('Музыка', 'music');
        $jazz  = $this->createInterest('Джаз', 'jazz', $music->id);
        $rock  = $this->createInterest('Рок', 'rock', $music->id);
        $this->createInterest('Театр', 'teatr');

        $response = $this->getJson('/api/web/interests?parent_slug=music');

        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('jazz', $slugs);
        $this->assertContains('rock', $slugs);
        $this->assertNotContains('music', $slugs, 'parent itself should not be returned');
        $this->assertNotContains('teatr', $slugs, 'unrelated interest should not be returned');
    }

    /* ===================== helpers ===================== */

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
            'name'    => $name,
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

    private function createInterest(string $name, string $slug, ?int $parentId = null): Interest
    {
        return Interest::create([
            'name'      => $name,
            'slug'      => $slug,
            'parent_id' => $parentId,
        ]);
    }

    private function tagEvent(int $eventId, int $interestId): void
    {
        DB::table('event_interest')->insert([
            'event_id'    => $eventId,
            'interest_id' => $interestId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }
}
