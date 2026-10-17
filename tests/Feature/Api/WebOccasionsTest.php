<?php

namespace Tests\Feature\Api;

use App\Models\Community;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebOccasionsTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-17 12:00', 'Europe/Moscow'));

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
    public function the_page_shows_the_channel_post_roster_with_its_lines(): void
    {
        $party = $this->event('Хэллоуинская вечеринка', '2026-10-31 18:00');
        $market = $this->event('Готический Halloween', '2026-10-30 12:00');
        $this->event('Квиз: Хэллоуин', '2026-10-29 19:00');
        $this->postedOccasion([$market, $party], [(string) $market => 'Мастера привезут тематические работы.']);

        $picks = $this->getJson('/api/web/occasions/halloween?city=voronezh')->assertOk()->json('data.picks');

        $this->assertSame([$market, $party], array_column($picks, 'id'));
        $this->assertSame('Мастера привезут тематические работы.', $picks[0]['hook']);
    }

    #[Test]
    public function two_sessions_of_one_event_make_one_row(): void
    {
        $group = (int) DB::table('event_groups')->insertGetId([
            'community_id' => Community::create(['name' => 'Театр', 'city_id' => $this->cityId])->id,
            'city_id' => $this->cityId, 'group_key' => 'grp-1', 'title_norm' => 'хэллоуин в театре',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['2026-10-31 14:00', '2026-10-31 18:00'] as $at) {
            Event::query()->whereKey($this->event('Хэллоуин в театре', $at))->update(['event_group_id' => $group]);
        }

        $days = $this->getJson('/api/web/occasions/halloween?city=voronezh')->assertOk()->json('data.days');

        $this->assertCount(1, $days[0]['events']);
        $this->assertSame(['14:00', '18:00'], $days[0]['events'][0]['times']);
    }

    #[Test]
    public function a_show_running_for_days_is_one_row_with_its_last_date(): void
    {
        $group = (int) DB::table('event_groups')->insertGetId([
            'community_id' => Community::create(['name' => 'Театр', 'city_id' => $this->cityId])->id,
            'city_id' => $this->cityId, 'group_key' => 'grp-2', 'title_norm' => 'хэллоуин-квест',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31'] as $day) {
            Event::query()->whereKey($this->event('Хэллоуин-квест', $day.' 11:00'))->update(['event_group_id' => $group]);
        }

        $days = $this->getJson('/api/web/occasions/halloween?city=voronezh')->assertOk()->json('data.days');

        $this->assertCount(1, $days);
        $this->assertSame('2026-10-31', $days[0]['events'][0]['until']);
    }

    private function event(string $title, string $at): int
    {
        $start = Carbon::parse($at, 'Europe/Moscow');
        $community = Community::create(['name' => 'Организатор '.uniqid(), 'city_id' => $this->cityId]);

        $event = new Event;
        $event->community_id = $community->id;
        $event->city_id = $this->cityId;
        $event->venue_id = DB::table('venues')->insertGetId([
            'city_id' => $this->cityId, 'name' => 'Площадка '.uniqid(), 'slug' => 'venue-'.uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $event->title = $title;
        $event->status = 'active';
        $event->start_time = $start->copy()->utc();
        $event->start_date = $start->toDateString();
        $event->description = str_repeat('описание события достаточной длины. ', 5);
        $event->price_status = 'range';
        $event->price_min = 500;
        $event->save();

        return (int) $event->id;
    }

    /**
     * @param  list<int>  $roster
     * @param  array<string, string>  $hooks
     */
    private function postedOccasion(array $roster, array $hooks): void
    {
        $user = TelegramUser::create(['telegram_id' => random_int(10_000, 99_999), 'first_name' => 'Владелец']);
        $chat = new TelegramChat;
        $chat->telegram_user_id = $user->id;
        $chat->telegram_chat_id = -random_int(1_000_000, 9_999_999);
        $chat->chat_type = 'channel';
        $chat->title = 'Канал';
        $chat->is_active = true;
        $chat->city_id = $this->cityId;
        $chat->save();

        $broadcast = new TelegramChatBroadcast;
        $broadcast->chat_id = $chat->id;
        $broadcast->enabled = true;
        $broadcast->settings = ['ai_text' => false, 'slots' => [19]];
        $broadcast->period = 'daily_19';
        $broadcast->save();

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->is_off_grid = true;
        $item->publish_at = Carbon::parse('2026-10-24 12:00', 'Europe/Moscow');
        $item->digest_meta = ['theme' => 'povod-halloween', 'hooks' => $hooks];
        $item->save();

        foreach ($roster as $i => $eventId) {
            DB::table('telegram.chat_broadcast_item_events')->insert([
                'item_id' => $item->id, 'event_id' => $eventId, 'position' => $i + 1, 'created_at' => now(),
            ]);
        }
    }
}
