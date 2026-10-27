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
use Illuminate\Support\Facades\Http;
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
        // страница спрашивает погоду у MET Norway: в тестах без сети, пустой прогноз
        Http::fake(['api.met.no/*' => Http::response(['properties' => ['timeseries' => []]])]);

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
    public function a_programme_row_hands_its_pictures_to_the_card_only_when_there_are_several(): void
    {
        $many = $this->withPhoto($this->event('Спектакль «Три сестры»', '2026-10-24 19:00'), [
            'https://example.com/a.jpg', 'https://example.com/b.jpg', 'https://example.com/c.jpg',
        ]);
        $one = $this->withPhoto($this->event('Концерт органной музыки', '2026-10-24 20:00'));

        $days = $this->getJson('/api/web/weekends?city=voronezh')->assertOk()->json('data.current.days');
        $rows = collect(array_merge(...array_column($days, 'events')))->keyBy('id');

        $this->assertCount(3, $rows[$many]['images']);
        $this->assertNull($rows[$one]['images']);
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
        $shows = [];
        foreach (['2026-10-24 18:00', '2026-10-25 18:00'] as $at) {
            $shows[] = $id = $this->withPhoto($this->event('Спектакль «Чайка»', $at));
            $this->withTheme($id, 'theatre');
        }
        // оба показа на одной сцене: то же название в другом месте — уже другое событие
        Event::query()->whereKey($shows[1])->update(['venue_id' => Event::query()->whereKey($shows[0])->value('venue_id')]);
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

    #[Test]
    public function the_holiday_events_stay_in_the_programme_while_its_card_leads_the_main(): void
    {
        $party = $this->withPhoto($this->event('Хэллоуинская вечеринка', '2026-10-31 18:00'));
        $this->withTheme($party, 'parties');
        $play = $this->withPhoto($this->event('Спектакль «Чайка»', '2026-10-31 19:00'));
        $this->withTheme($play, 'theatre');

        $page = $this->getJson('/api/web/weekends?city=voronezh&w=2026-10-31')->assertOk()->json('data.current');

        $this->assertSame('halloween', $page['holiday']['page']);
        $this->assertSame([$play], array_column($page['picks'], 'id'));
        $this->assertSame([$party], array_column(array_merge(...array_column($page['days'], 'events')), 'id'));
    }

    #[Test]
    public function the_free_week_shows_each_free_event_once(): void
    {
        $free = ['price_status' => 'free', 'price_min' => null];
        foreach (['2026-10-24 11:00', '2026-10-25 11:00'] as $at) {
            Event::query()->whereKey($this->event('Гаражка издательства', $at))->update($free);
        }
        $this->event('Концерт органной музыки', '2026-10-24 19:00');
        foreach (['2026-10-22', '2026-10-23'] as $day) {
            Event::query()->whereKey($this->event('Выставка «Миг свободы»', $day.' 00:00'))
                ->update($free + ['start_time' => null, 'time_precision' => 'date']);
        }

        $week = $this->getJson('/api/web/free?city=voronezh')->assertOk()->json('data.current');
        $dated = array_merge(...array_column($week['days'], 'events'));

        $this->assertSame(['Гаражка издательства'], array_column($dated, 'title'));
        $this->assertSame([['date' => '2026-10-25', 'time' => '11:00']], $dated[0]['also']);
        $this->assertSame(['Выставка «Миг свободы»'], array_column($week['both'], 'title'));
        $this->assertSame('2026-10-23', $week['both'][0]['until']);
    }

    #[Test]
    public function a_coin_adds_paid_events_up_to_its_price_and_counts_each_step(): void
    {
        Event::query()->whereKey($this->event('Лекция о модерне', '2026-10-24 11:00'))
            ->update(['price_status' => 'free', 'price_min' => null]);
        // утренний квиз сегодня уже начался: карточку сайт спрячет, копилка его не считает
        $prices = ['Квиз в баре' => ['2026-10-24 19:00', 250], 'Камерный концерт' => ['2026-10-25 18:00', 450],
            'Большой концерт' => ['2026-10-25 20:00', 1500], 'Утренний квиз' => ['2026-10-22 10:00', 200]];
        foreach ($prices as $title => [$at, $price]) {
            Event::query()->whereKey($this->event($title, $at))->update(['price_min' => $price]);
        }

        $week = $this->getJson('/api/web/free?city=voronezh&budget=500')->assertOk()->json('data.current');

        $this->assertSame(
            [['2026-10-22', ['Утренний квиз']], ['2026-10-24', ['Лекция о модерне', 'Квиз в баре']], ['2026-10-25', ['Камерный концерт']]],
            array_map(fn (array $d) => [$d['date'], array_column($d['events'], 'title')], $week['days']),
        );
        $this->assertSame(4, $week['total']);
        $this->assertSame(1, $week['piggy']['free']);
        $this->assertSame([[300, 1, 250, 250], [500, 1, 450, 450], [1000, 0, 0, null]],
            array_map(fn (array $s) => [$s['max'], $s['n'], $s['sum'], $s['min']], $week['piggy']['steps']));
        // у каждого вида меньше трёх цен: есть только общая обычная цена, медиана четырёх платных
        $this->assertSame(['' => 350], $week['piggy']['usual']);
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

    /** @param  list<string>  $images */
    private function withPhoto(int $eventId, array $images = ['https://example.com/poster.jpg']): int
    {
        $community = (int) Event::query()->whereKey($eventId)->value('community_id');
        $networkId = DB::table('social_networks')->insertGetId(['name' => 'vk', 'slug' => 'vk-'.$eventId]);
        $linkId = DB::table('community_social_links')->insertGetId([
            'community_id' => $community, 'social_network_id' => $networkId,
            'url' => 'https://vk.com/src_'.$eventId, 'status' => 'active',
        ]);
        DB::table('event_sources')->insert([
            'event_id' => $eventId, 'social_link_id' => $linkId, 'source' => 'vk',
            'post_external_id' => 'post-'.$eventId, 'images' => json_encode($images),
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
