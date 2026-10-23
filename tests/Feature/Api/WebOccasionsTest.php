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
use PHPUnit\Framework\Attributes\DataProvider;
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
    public function a_main_event_is_not_repeated_in_the_programme(): void
    {
        $party = $this->event('Хэллоуинская вечеринка', '2026-10-31 18:00');
        $quiz = $this->event('Квиз: Хэллоуин', '2026-10-29 19:00');
        $this->postedOccasion([$party], []);

        $page = $this->getJson('/api/web/occasions/halloween?city=voronezh')->assertOk()->json('data');

        $this->assertSame([$party], array_column($page['picks'], 'id'));
        $this->assertSame([$quiz], array_merge(...array_map(fn (array $d) => array_column($d['events'], 'id'), $page['days'])));
        $this->assertSame(1, $page['occasion']['program_total']);
    }

    #[Test]
    public function the_owner_overrules_the_holiday_words_in_the_program(): void
    {
        $party = $this->event('Хэллоуинская вечеринка', '2026-10-31 18:00');
        $jazz = $this->event('Джазовый вечер', '2026-10-31 20:00');
        $base = '/api/admin/broadcast/channels/'.$this->channel()->id.'/occasions/povod-halloween/program';
        \Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web');
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('superadmin');
        \Laravel\Sanctum\Sanctum::actingAs($admin);
        // событие из «Главного» в программе не повторяется: на странице оно там или там
        $shown = function () {
            $page = $this->getJson('/api/web/occasions/halloween?city=voronezh')->assertOk()->json('data');

            return array_merge(array_column($page['picks'], 'id'), ...array_map(fn (array $day) => array_column($day['events'], 'id'), $page['days']));
        };

        $this->postJson($base.'/include', ['event_id' => $jazz])->assertOk();
        $this->postJson($base.'/exclude', ['event_id' => $party])->assertOk();

        $this->assertSame([$jazz], $shown());
        $this->assertSame('words', DB::table('roster_edits')->where('event_in', $jazz)->value('missed_rule'));

        $this->postJson($base.'/exclude', ['event_id' => $jazz])->assertOk();

        $this->assertSame([], $shown());
        $this->assertSame(0, DB::table('occasion_picks')->where('event_id', $jazz)->count());
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
        // главное — вечеринка, спектакль остаётся в программе
        $this->postedOccasion([$this->event('Хэллоуинская вечеринка', '2026-10-31 20:00')], []);

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
        // главное — вечеринка, квест остаётся в программе
        $this->postedOccasion([$this->event('Хэллоуинская вечеринка', '2026-10-31 20:00')], []);

        $days = $this->getJson('/api/web/occasions/halloween?city=voronezh')->assertOk()->json('data.days');

        $this->assertCount(1, $days);
        $this->assertSame('2026-10-31', $days[0]['events'][0]['until']);
    }

    #[Test]
    public function the_home_promo_shows_upcoming_events_of_the_post_in_time_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 19:30', 'Europe/Moscow'));
        $past = $this->event('Квиз: Хэллоуин', '2026-10-31 15:00');
        $late = $this->event('Хэллоуинская вечеринка', '2026-10-31 20:00');
        $next = $this->event('Halloween VRN', '2026-11-01 18:00');
        $soon = $this->event('Кошмар на Хэллоуин', '2026-10-31 18:00');
        $this->postedOccasion([$next, $past, $late, $soon], []);

        $program = $this->getJson('/api/web/occasions?city=voronezh')->assertOk()->json('soon.program');

        $this->assertSame([$soon, $late, $next], array_column($program, 'id'));
    }

    #[Test]
    public function the_short_answer_names_the_main_events_by_day_and_links_them(): void
    {
        $party = $this->event('Хэллоуинская вечеринка', '2026-10-31 18:00');
        $market = $this->event('Готический Halloween', '2026-10-30 12:00');
        $quiz = $this->event('Квиз про Хэллоуин', '2026-10-31 17:00');
        $this->postedOccasion([$party, $market, $quiz], []);

        $faq = $this->getJson('/api/web/occasions/halloween?city=voronezh')->assertOk()->json('data.faq');
        $where = collect($faq)->firstWhere('q', 'Где отметить Хэллоуин в Воронеже?');

        $this->assertSame('В пятницу: Готический Halloween в 12:00. В субботу: Квиз про Хэллоуин в 17:00, Хэллоуинская вечеринка в 18:00.', $where['a']);
        foreach ($where['links'] as $link) {
            $this->assertStringContainsString($link['text'], $where['a']);
        }
    }

    #[Test]
    public function the_next_holiday_tells_how_many_events_it_already_has(): void
    {
        $this->event('Хэллоуинская вечеринка', '2026-10-31 18:00');
        $this->event('Ночь искусств в музее', '2026-11-03 19:00');

        $next = $this->getJson('/api/web/occasions/halloween?city=voronezh')->assertOk()->json('data.next.0');

        $this->assertSame('noch-iskusstv', $next['page']);
        $this->assertSame(1, $next['total']);
    }

    #[Test]
    public function an_event_page_names_the_holiday_whose_program_it_is_in(): void
    {
        $party = $this->event('Хэллоуинская вечеринка', '2026-10-31 18:00');
        $concert = $this->event('Концерт органной музыки', '2026-10-31 19:00');

        $this->assertSame('halloween', $this->getJson("/api/web/events/{$party}")->assertOk()->json('data.occasion.page'));
        $this->assertNull($this->getJson("/api/web/events/{$concert}")->assertOk()->json('data.occasion'));
    }

    #[Test]
    #[DataProvider('sourcePosts')]
    public function a_night_takes_events_whose_post_names_it(string $page, string $at, string $post, bool $taken): void
    {
        $this->fromPost($this->event('Квартирник «Серебряный век русского рока»', $at), $post);

        $total = $this->getJson("/api/web/occasions/{$page}?city=voronezh")->assertOk()->json('data.occasion.total');

        $this->assertSame($taken ? 1 : 0, $total);
    }

    public static function sourcePosts(): array
    {
        return [
            'программа ночи одним постом' => ['noch-iskusstv', '2026-11-03 19:00', 'Приглашаем на мероприятия «Ночи искусств»: квартирник и экскурсия.', true],
            'у хэллоуина только своё название' => ['halloween', '2026-10-31 19:00', 'В субботу Хэллоуин, а у нас квартирник.', false],
        ];
    }

    #[Test]
    #[DataProvider('searches')]
    public function a_search_naming_a_holiday_finds_it(string $query, ?string $page): void
    {
        $found = $this->getJson('/api/web/occasions?city=voronezh&q='.urlencode($query))->assertOk()->json('match.page');

        $this->assertSame($page, $found);
    }

    public static function searches(): array
    {
        return [
            'слово программы' => ['halloween вечеринки', 'halloween'],
            'название сильнее слова программы' => ['новый год', 'novyj-god'],
            'не праздник' => ['концерт', null],
        ];
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

    private function fromPost(int $eventId, string $text): void
    {
        $community = (int) Event::query()->whereKey($eventId)->value('community_id');
        $postId = (int) DB::table('context_posts')->insertGetId([
            'external_id' => 'post-'.$eventId, 'source' => 'vk', 'community_id' => $community, 'status' => 'parsed',
            'text' => $text, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $networkId = DB::table('social_networks')->insertGetId(['name' => 'vk', 'slug' => 'vk-'.$eventId]);
        $linkId = DB::table('community_social_links')->insertGetId([
            'community_id' => $community, 'social_network_id' => $networkId,
            'url' => 'https://vk.com/src_'.$eventId, 'status' => 'active',
        ]);
        DB::table('event_sources')->insert([
            'event_id' => $eventId, 'social_link_id' => $linkId, 'context_post_id' => $postId,
            'source' => 'vk', 'post_external_id' => 'post-'.$eventId,
        ]);
    }

    /**
     * @param  list<int>  $roster
     * @param  array<string, string>  $hooks
     */
    private function postedOccasion(array $roster, array $hooks): void
    {
        $broadcast = $this->channel();

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

    private function channel(): TelegramChatBroadcast
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
}
