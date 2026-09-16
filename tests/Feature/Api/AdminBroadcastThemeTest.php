<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Тема события в ленте и в пуле.
 *
 * Тема — это ответ на вопрос «чем эта неделя будет отличаться от прошлой», и
 * до сих пор его нельзя было задать: интерфейс про темы не знал ничего. При
 * этом внутри ими уже меряется всё — состав подборки недели и соседство слотов
 * в ленте.
 *
 * Первичная тема (`event_interest.rank = 0`), а не все теги: вторичные стоят
 * пачками, и по ним «та же тема» совпадает почти у всего.
 */
class AdminBroadcastThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-15 06:00:00', 'UTC'));

        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        Sanctum::actingAs($user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_feed_row_carries_the_theme(): void
    {
        $broadcast = $this->makeChannel();
        $music = $this->interest('Концерты');
        $event = $this->event('Концерт', 3, $music);

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_EVENT;
        $item->event_id = $event->id;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->publish_at = Carbon::now()->addDay()->setTime(7, 0);
        $item->save();

        $res = $this->getJson("/api/admin/broadcast/channels/{$broadcast->id}/feed");

        $res->assertOk();
        $row = collect($res->json('data.items'))->firstWhere('id', $item->id);

        $this->assertSame('Концерты', $row['theme']['name'] ?? null,
            'по теме в ленте видно, чем занята неделя');
    }

    /**
     * Причина «такой темы ещё не было» — первая: она отвечает на вопрос «чем
     * эта неделя будет отличаться», а площадка — только «не повторяемся ли».
     */
    public function test_suggestion_tells_when_the_theme_is_new_for_the_week(): void
    {
        $broadcast = $this->makeChannel();
        $music = $this->interest('Концерты');
        $theatre = $this->interest('Спектакли');

        // Неделя уже занята концертом.
        $inFeed = $this->event('Концерт в ленте', 3, $music);
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_EVENT;
        $item->event_id = $inFeed->id;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->publish_at = Carbon::now()->addDay()->setTime(7, 0);
        $item->save();

        $sameTheme = $this->event('Ещё концерт', 4, $music);
        $newTheme = $this->event('Спектакль', 5, $theatre);

        $res = $this->getJson("/api/admin/broadcast/channels/{$broadcast->id}/suggestions");
        $res->assertOk();

        $rows = collect($res->json('data'));
        $new = $rows->firstWhere('event_id', $newTheme->id);
        $same = $rows->firstWhere('event_id', $sameTheme->id);

        $this->assertNotNull($new, 'спектакль обязан быть в пуле');
        $this->assertContains('такой темы ещё не было на неделе', $new['reasons'],
            'темы «Спектакли» на неделе нет — это и есть повод поставить');
        $this->assertSame('Спектакли', $new['theme']['name'] ?? null);

        $this->assertNotNull($same);
        $this->assertNotContains('такой темы ещё не было на неделе', $same['reasons'],
            'концерт на неделе уже стоит — причина была бы враньём');
    }

    /** Начавшееся однодневное событие в пул не идёт: пост про него звать уже некуда. */
    public function test_started_event_is_not_suggested_for_a_later_slot(): void
    {
        $broadcast = $this->makeChannel();
        $music = $this->interest('Концерты');

        $started = $this->event('Уже идёт', 0, $music);
        $started->start_time = Carbon::now()->subHour();
        $started->end_time = Carbon::now()->addHours(2);
        $started->save();

        $res = $this->getJson(
            "/api/admin/broadcast/channels/{$broadcast->id}/suggestions?date=".Carbon::now()->format('Y-m-d'),
        );

        $res->assertOk();
        $this->assertNotContains(
            $started->id,
            collect($res->json('data'))->pluck('event_id')->all(),
        );
    }

    private function interest(string $name): int
    {
        return (int) DB::table('interests')->insertGetId([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function event(string $title, int $inDays, int $interestId): Event
    {
        $city = $this->city();
        $community = Community::create(['name' => $title.' орг', 'city_id' => $city->id]);

        $venueId = DB::table('venues')->insertGetId([
            'city_id' => $city->id,
            'name' => 'Площадка '.uniqid(),
            'slug' => 'venue-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = new Event;
        $event->community_id = $community->id;
        $event->title = $title;
        $event->status = 'active';
        $event->city_id = $city->id;
        $event->venue_id = $venueId;
        $event->start_time = Carbon::now()->addDays($inDays)->setTime(16, 0);
        $event->start_date = $event->start_time->toDateString();
        $event->save();

        DB::table('event_interest')->insert([
            'event_id' => $event->id,
            'interest_id' => $interestId,
            'rank' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $event;
    }

    private function city(): City
    {
        $city = City::query()->where('slug', 'voronezh-theme')->first();
        if ($city) {
            return $city;
        }

        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh-theme', now(), now()]
        );

        return City::query()->where('slug', 'voronezh-theme')->firstOrFail();
    }

    private function makeChannel(): TelegramChatBroadcast
    {
        $owner = TelegramUser::create(['telegram_id' => 8307201888]);

        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999055;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->city_id = $this->city()->id;
        $chat->save();

        return TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic'],
        ]);
    }
}
