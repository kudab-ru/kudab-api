<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IndexNowCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 12:00', 'Europe/Moscow'));
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.66, 'active', 'voronezh', now(), now()]
        );
        $this->cityId = (int) DB::table('cities')->where('slug', 'voronezh')->value('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function only_new_upcoming_events_go_to_yandex_and_each_once(): void
    {
        $past = $this->event('2026-11-10 19:00');
        $first = $this->event('2026-11-20 19:00');
        Http::fake(['yandex.com/*' => Http::response('', 202), 'www.bing.com/*' => Http::response('', 202)]);

        $this->artisan('seo:indexnow')->assertSuccessful();
        $new = $this->event('2026-11-21 19:00');
        $this->artisan('seo:indexnow')->assertSuccessful();

        $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), 'yandex.com'))
            ->map(fn ($pair) => $pair[0]['urlList'])->values()->all();

        $this->assertSame([
            ["https://kudab.ru/events/{$first}"],
            ["https://kudab.ru/events/{$new}"],
        ], $sent);
        $this->assertNotContains("https://kudab.ru/events/{$past}", array_merge(...$sent));
    }

    #[Test]
    public function a_refusal_from_yandex_keeps_the_events_for_the_next_run(): void
    {
        $id = $this->event('2026-11-20 19:00');
        Http::fake(['yandex.com/*' => Http::sequence()->push('', 500)->push('', 200), 'www.bing.com/*' => Http::response('', 202)]);

        $this->artisan('seo:indexnow')->assertFailed();
        $this->artisan('seo:indexnow')->assertSuccessful();

        $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), 'yandex.com'))
            ->map(fn ($pair) => $pair[0]['urlList'])->values()->all();
        $this->assertSame([["https://kudab.ru/events/{$id}"], ["https://kudab.ru/events/{$id}"]], $sent);
    }

    #[Test]
    public function the_stand_sends_nothing(): void
    {
        $this->event('2026-11-20 19:00');
        Http::fake();
        // так выглядит стенд: не production и база не тестовая
        config(['app.env' => 'local']);
        DB::shouldReceive('connection->getDatabaseName')->andReturn('kudab');

        $this->artisan('seo:indexnow --pages')->assertSuccessful();

        Http::assertNothingSent();
    }

    private function event(string $at): int
    {
        $start = Carbon::parse($at, 'Europe/Moscow');
        $community = Community::create(['name' => 'Организатор '.uniqid(), 'city_id' => $this->cityId]);

        $event = new Event;
        $event->community_id = $community->id;
        $event->city_id = $this->cityId;
        $event->title = 'Концерт '.uniqid();
        $event->status = 'active';
        $event->start_time = $start->copy()->utc();
        $event->start_date = $start->toDateString();
        $event->save();

        return (int) $event->id;
    }
}
