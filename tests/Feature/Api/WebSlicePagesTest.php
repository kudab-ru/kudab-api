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

class WebSlicePagesTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00', 'Europe/Moscow'));

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
    public function the_main_events_are_this_weekends_channel_post_not_last_weeks(): void
    {
        $lastWeek = $this->event('Концерт прошлых выходных', '2026-10-17 19:00');
        $play = $this->event('Спектакль этих выходных', '2026-10-24 18:00');
        $broadcast = $this->broadcast();
        $this->digest($broadcast, [$lastWeek], '2026-10-16 12:00', posted: true);
        $this->digest($broadcast, [$play], '2026-10-23 12:00', posted: false);

        $picks = $this->getJson('/api/web/weekends?city=voronezh')->assertOk()->json('data.current.picks');

        $this->assertSame([$play], array_column($picks, 'id'));
    }

    #[Test]
    public function an_exhibition_open_both_days_is_one_row_with_its_last_date(): void
    {
        $group = (int) DB::table('event_groups')->insertGetId([
            'community_id' => Community::create(['name' => 'Музей', 'city_id' => $this->cityId])->id,
            'city_id' => $this->cityId, 'group_key' => 'grp-show', 'title_norm' => 'выставка миг свободы',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['2026-10-24', '2026-10-25', '2026-12-06'] as $day) {
            Event::query()->whereKey($this->event('Выставка «Миг свободы»', $day.' 00:00'))
                ->update(['event_group_id' => $group, 'start_time' => null, 'time_precision' => 'date']);
        }
        $this->event('Концерт органной музыки', '2026-10-24 19:00');

        $page = $this->getJson('/api/web/weekends?city=voronezh')->assertOk()->json('data.current');

        $this->assertSame(['Выставка «Миг свободы»'], array_column($page['both'], 'title'));
        $this->assertSame('2026-12-06', $page['both'][0]['until']);
        $this->assertSame(['Концерт органной музыки'], array_column(array_merge(...array_column($page['days'], 'events')), 'title'));
    }

    #[Test]
    public function without_a_channel_post_the_main_events_take_two_of_a_kind_and_skip_classes_and_weak_events(): void
    {
        $themes = ['Концерт группы Сова' => 'music', 'Концерт группы Kamazz' => 'music', 'Концерт группы Lumen' => 'music',
            'Концерт группы Обе-Рек' => 'music', 'Спектакль «Чайка»' => 'theatre', 'Мастер-класс по керамике' => 'theatre',
            'Отъезд участников' => null];
        foreach ($themes as $title => $theme) {
            $id = $this->withPhoto($this->event($title, '2026-10-24 19:00'));
            if ($theme !== null) {
                $this->withTheme($id, $theme);
            }
        }

        $picks = array_column($this->getJson('/api/web/weekends?city=voronezh')->assertOk()->json('data.current.picks'), 'title');

        $this->assertCount(2, array_filter($picks, fn (string $t) => str_starts_with($t, 'Концерт')));
        $this->assertContains('Спектакль «Чайка»', $picks);
        $this->assertNotContains('Мастер-класс по керамике', $picks);
        $this->assertNotContains('Отъезд участников', $picks);
    }

    #[Test]
    public function a_main_event_leaves_the_programme_and_keeps_its_other_day_on_the_card(): void
    {
        foreach (['2026-10-24 18:00', '2026-10-25 18:00'] as $at) {
            $this->withTheme($this->withPhoto($this->event('Спектакль «Чайка»', $at)), 'theatre');
        }
        $this->event('Концерт органной музыки', '2026-10-24 19:00');

        $page = $this->getJson('/api/web/weekends?city=voronezh')->assertOk()->json('data.current');

        $this->assertSame(['Спектакль «Чайка»'], array_column($page['picks'], 'title'));
        $this->assertSame([['date' => '2026-10-25', 'time' => '18:00']], $page['picks'][0]['also']);
        $this->assertSame(['Концерт органной музыки'], array_column(array_merge(...array_column($page['days'], 'events')), 'title'));
    }

    #[Test]
    public function today_drops_what_has_passed_and_keeps_all_day_exhibitions_apart(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-23 19:00', 'Europe/Moscow'));
        $this->withTheme($this->withPhoto($this->event('Концерт группы Сова', '2026-10-23 12:00')), 'music');
        $this->event('Спектакль «Чайка»', '2026-10-23 20:00');
        foreach (['Выставка «Миг свободы»', 'Кавер-группа в баре'] as $title) {
            Event::query()->whereKey($this->event($title, '2026-10-23 00:00'))
                ->update(['start_time' => null, 'time_precision' => 'date']);
        }

        $day = $this->getJson('/api/web/days?city=voronezh')->assertOk()->json('data.current');

        $this->assertSame([], $day['picks']);
        $this->assertSame(['Спектакль «Чайка»', 'Кавер-группа в баре'], array_column(array_merge(...array_column($day['days'], 'events')), 'title'));
        $this->assertSame(['Выставка «Миг свободы»'], array_column($day['both'], 'title'));
    }

    /** тема даёт основной вес оценки интереса: без неё событие до главного не дотягивает */
    private function withTheme(int $eventId, string $slug): void
    {
        $interest = DB::table('interests')->where('slug', $slug)->value('id')
            ?? DB::table('interests')->insertGetId(['name' => $slug, 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('event_interest')->insert([
            'event_id' => $eventId, 'interest_id' => $interest, 'rank' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function withPhoto(int $eventId): int
    {
        $community = (int) Event::query()->whereKey($eventId)->value('community_id');
        $networkId = DB::table('social_networks')->insertGetId(['name' => 'vk', 'slug' => 'vk-'.$eventId]);
        $linkId = DB::table('community_social_links')->insertGetId([
            'community_id' => $community, 'social_network_id' => $networkId,
            'url' => 'https://vk.com/src_'.$eventId, 'status' => 'active',
        ]);
        DB::table('event_sources')->insert([
            'event_id' => $eventId, 'social_link_id' => $linkId, 'source' => 'vk',
            'post_external_id' => 'post-'.$eventId, 'images' => json_encode(['https://example.com/poster.jpg']),
        ]);

        return $eventId;
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

    private function broadcast(): TelegramChatBroadcast
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

        return $broadcast;
    }

    /** @param  list<int>  $roster */
    private function digest(TelegramChatBroadcast $broadcast, array $roster, string $at, bool $posted): void
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $item->status = $posted ? TelegramChatBroadcastItem::STATUS_POSTED : TelegramChatBroadcastItem::STATUS_PENDING;
        $item->is_off_grid = true;
        $item->publish_at = Carbon::parse($at, 'Europe/Moscow');
        $item->posted_at = $posted ? Carbon::parse($at, 'Europe/Moscow') : null;
        $item->digest_meta = ['theme' => 'na-vyhodnyh'];
        $item->save();

        foreach ($roster as $i => $eventId) {
            DB::table('telegram.chat_broadcast_item_events')->insert([
                'item_id' => $item->id, 'event_id' => $eventId, 'position' => $i + 1, 'created_at' => now(),
            ]);
        }
    }
}
