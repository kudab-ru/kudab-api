<?php

namespace Tests\Feature\Telegram;

use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Services\Telegram\BroadcastAnnounce;
use App\Services\Telegram\BroadcastOccasions;
use App\Services\Telegram\TelegramChatBroadcastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BroadcastNewsPostsTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    private int $interestId;

    private ?TelegramChatBroadcast $channel = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-17 12:10', 'Europe/Moscow'));

        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.66, 'active', 'voronezh', now(), now()]
        );
        $this->cityId = (int) DB::table('cities')->where('slug', 'voronezh')->value('id');
        $this->interestId = (int) DB::table('interests')->insertGetId([
            'name' => 'Театр', 'slug' => 'theatre', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('telegram.message_templates')->insert([
            'code' => 'basic', 'locale' => 'ru', 'name' => 'Базовый', 'body' => "🎟 <b>{title}</b>\n🗓 {start_time}",
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_occasion_is_booked_once_a_week_ahead(): void
    {
        $this->channel();
        app(BroadcastOccasions::class)->run(Carbon::now());
        app(BroadcastOccasions::class)->run(Carbon::now()->addHour());

        $booked = $this->items()->get();
        $this->assertCount(1, $booked);
        $this->assertSame('2026-10-24 12:00', Carbon::parse($booked[0]->publish_at)->setTimezone('Europe/Moscow')->format('Y-m-d H:i'));
        $this->assertTrue($booked[0]->is_off_grid);

        // снятую человеком бронь не возвращаем
        $booked[0]->update(['status' => TelegramChatBroadcastItem::STATUS_SKIPPED]);
        app(BroadcastOccasions::class)->run(Carbon::now()->addHours(2));
        $this->assertSame(1, $this->items()->count());
    }

    #[Test]
    public function a_floating_holiday_is_booked_by_its_date_for_the_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-01 12:10', 'Europe/Moscow'));
        $this->onlyRubric('povod-maslenica');
        $this->channel();

        app(BroadcastOccasions::class)->run(Carbon::now());

        $booked = $this->items()->get();
        $this->assertCount(1, $booked);
        $this->assertSame('2027-03-07 12:00', Carbon::parse($booked[0]->publish_at)->setTimezone('Europe/Moscow')->format('Y-m-d H:i'));
    }

    #[Test]
    public function an_occasion_skipped_in_the_calendar_is_not_booked_until_returned(): void
    {
        $this->onlyRubric('povod-halloween');
        $this->actingAsSuperadmin();
        $url = '/api/admin/broadcast/channels/'.$this->channel()->id.'/occasions/povod-halloween/skip';

        $this->postJson($url)->assertOk()->assertJsonPath('data.state', 'skipped');
        app(BroadcastOccasions::class)->run(Carbon::now());
        $this->assertSame(0, $this->items()->where('status', TelegramChatBroadcastItem::STATUS_PENDING)->count());

        $this->deleteJson($url)->assertOk();
        app(BroadcastOccasions::class)->run(Carbon::now());
        $this->assertSame(1, $this->items()->where('status', TelegramChatBroadcastItem::STATUS_PENDING)->count());
    }

    #[Test]
    public function a_hand_made_occasion_draft_is_scheduled_instead_of_a_second_post(): void
    {
        $draft = new TelegramChatBroadcastItem;
        $draft->broadcast_id = $this->channel()->id;
        $draft->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $draft->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $draft->is_off_grid = true;
        $draft->digest_meta = ['theme' => 'povod-halloween'];
        $draft->save();

        app(BroadcastOccasions::class)->run(Carbon::now());

        $this->assertSame(1, $this->items()->count());
        $this->assertSame('2026-10-24 12:00', Carbon::parse($draft->fresh()->publish_at)->setTimezone('Europe/Moscow')->format('Y-m-d H:i'));
    }

    #[Test]
    public function on_the_day_a_reminder_answers_the_posted_digest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 10:00', 'Europe/Moscow'));
        // другие поводы тех же дней ставят свои брони
        $this->onlyRubric('povod-halloween');
        $early = $this->event('Вечеринка в ПараDice', Carbon::parse('2026-10-31 18:00', 'Europe/Moscow'));
        $late = $this->event('Кошмар на улице Кирова', Carbon::parse('2026-10-31 19:00', 'Europe/Moscow'));
        $eve = $this->event('Готический маркет', Carbon::parse('2026-10-30 18:00', 'Europe/Moscow'));
        $main = $this->postedOccasion([$eve, $early, $late], messageId: 4242);

        app(BroadcastOccasions::class)->run(Carbon::now());

        $reminder = $this->items()->whereRaw("digest_meta->>'reply_to' = ?", [(string) $main->id])->firstOrFail();
        $this->assertMatchesRegularExpression('/^Хэллоуин сегодня\. В 18:00 — <a [^>]+>Вечеринка в ПараDice<\/a>, в 19:00 — <a [^>]+>Кошмар на улице Кирова<\/a>\.$/u', $reminder->caption);
        $this->assertSame([], $reminder->photo_urls);

        Carbon::setTestNow(Carbon::parse('2026-10-31 12:01', 'Europe/Moscow'));
        $tasks = app(TelegramChatBroadcastService::class)->collectDueSingleRuns(Carbon::now(), 10);
        $task = collect($tasks)->first(fn ($t) => (int) ($t['item_id'] ?? 0) === (int) $reminder->id);
        $this->assertSame(4242, $task['reply_to_message_id'] ?? null);
    }

    #[Test]
    public function a_fresh_big_event_is_announced_and_keeps_its_own_post(): void
    {
        $this->channel();
        $show = $this->event('Большой концерт', Carbon::now()->addDays(30), price: 2500, photo: true);

        app(BroadcastAnnounce::class)->run(Carbon::now());

        $item = TelegramChatBroadcastItem::query()->whereRaw("digest_meta->>'theme' = ?", [TelegramChatBroadcastItem::THEME_ANNOUNCE])->firstOrFail();
        $this->assertStringContainsString('<b>Объявили: Большой концерт</b>', $item->caption);
        $this->assertSame([$show], DB::table('telegram.chat_broadcast_item_events')->where('item_id', $item->id)->pluck('event_id')->map(fn ($v) => (int) $v)->all());

        $candidates = app(TelegramChatBroadcastService::class)->channelCandidates($this->channel()->chat, (int) $this->channel()->id)->pluck('id')->all();
        $this->assertContains($show, $candidates);
    }

    #[Test]
    #[DataProvider('notNews')]
    public function old_news_is_not_announced(string $case): void
    {
        $this->channel();
        $show = $this->event('Спектакль «Чайка»', Carbon::now()->addDays(30), price: 2000, photo: true);
        match ($case) {
            // та же постановка уже была в афише другой датой
            'known' => Event::query()->whereKey(
                $this->event('Спектакль «Чайка»', Carbon::now()->addDays(60), price: 2000, photo: true, venueOf: $show)
            )->update(['created_at' => Carbon::now()->subDays(10)]),
            // подключили новый источник: свежих сразу больше, чем бывает новостей за сутки
            'burst' => collect(range(1, 26))->each(fn ($i) => $this->event('Концерт '.$i, Carbon::now()->addDays(25 + $i), price: 1500, photo: true)),
        };

        app(BroadcastAnnounce::class)->run(Carbon::now());

        $this->assertSame(0, TelegramChatBroadcastItem::query()->whereRaw("digest_meta->>'theme' = ?", [TelegramChatBroadcastItem::THEME_ANNOUNCE])->count());
    }

    public static function notNews(): array
    {
        return ['шоу уже знали' => ['known'], 'всплеск нового источника' => ['burst']];
    }

    private function actingAsSuperadmin(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web');
        $user = \App\Models\User::factory()->create();
        $user->assignRole('superadmin');
        \Laravel\Sanctum\Sanctum::actingAs($user);
    }

    private function onlyRubric(string $slug): void
    {
        config(['broadcast_digest.themes' => array_values(array_filter(
            (array) config('broadcast_digest.themes'),
            fn (array $t) => ($t['slug'] ?? '') === $slug,
        ))]);
    }

    private function items()
    {
        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $this->channel()->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST);
    }

    /** @param  list<int>  $events */
    private function postedOccasion(array $events, int $messageId): TelegramChatBroadcastItem
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $this->channel()->id;
        $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $item->status = TelegramChatBroadcastItem::STATUS_POSTED;
        $item->is_off_grid = true;
        $item->publish_at = Carbon::parse('2026-10-24 12:00', 'Europe/Moscow');
        $item->posted_at = $item->publish_at;
        $item->message_id = $messageId;
        $item->caption = 'Хэллоуин';
        $item->digest_meta = ['theme' => 'povod-halloween'];
        $item->save();

        foreach ($events as $i => $eventId) {
            DB::table('telegram.chat_broadcast_item_events')->insert([
                'item_id' => $item->id, 'event_id' => $eventId, 'position' => $i + 1, 'created_at' => now(),
            ]);
        }

        return $item;
    }

    private function event(string $title, Carbon $at, int $price = 1000, bool $photo = false, ?int $venueOf = null): int
    {
        $community = \App\Models\Community::create(['name' => 'Организатор '.uniqid(), 'city_id' => $this->cityId]);
        $venueId = $venueOf !== null
            ? (int) Event::query()->whereKey($venueOf)->value('venue_id')
            : DB::table('venues')->insertGetId([
                'city_id' => $this->cityId, 'name' => 'Площадка '.uniqid(), 'slug' => 'venue-'.uniqid(),
                'created_at' => now(), 'updated_at' => now(),
            ]);

        $event = new Event;
        $event->community_id = $community->id;
        $event->city_id = $this->cityId;
        $event->venue_id = $venueId;
        $event->title = $title;
        $event->status = 'active';
        $event->start_time = $at->copy()->utc();
        $event->start_date = $at->copy()->setTimezone('Europe/Moscow')->toDateString();
        $event->time_precision = 'datetime';
        $event->description = str_repeat('описание события достаточной длины. ', 5);
        $event->price_status = 'range';
        $event->price_min = $price;
        $event->save();

        DB::table('event_interest')->insert([
            'event_id' => $event->id, 'interest_id' => $this->interestId, 'rank' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($photo) {
            $network = DB::table('social_networks')->value('id')
                ?? DB::table('social_networks')->insertGetId(['name' => 'VK', 'slug' => 'vk', 'created_at' => now(), 'updated_at' => now()]);
            $link = DB::table('community_social_links')->insertGetId([
                'community_id' => $community->id, 'social_network_id' => $network, 'url' => 'https://vk.com/'.uniqid(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('event_sources')->insert([
                'event_id' => $event->id, 'social_link_id' => $link, 'source' => 'vk', 'post_external_id' => 'post-'.uniqid(),
                'images' => json_encode(['https://example.test/poster-'.$event->id.'.jpg']), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return (int) $event->id;
    }

    private function channel(): TelegramChatBroadcast
    {
        if ($this->channel !== null) {
            return $this->channel;
        }

        $user = TelegramUser::create(['telegram_id' => random_int(10_000, 99_999), 'first_name' => 'Владелец']);

        $chat = new TelegramChat;
        $chat->telegram_user_id = $user->id;
        $chat->telegram_chat_id = -random_int(1_000_000, 9_999_999);
        $chat->chat_type = 'channel';
        $chat->title = 'Канал новостей';
        $chat->is_active = true;
        $chat->city_id = $this->cityId;
        $chat->save();

        $b = new TelegramChatBroadcast;
        $b->chat_id = $chat->id;
        $b->enabled = true;
        $b->settings = ['ai_text' => false, 'slots' => [19], 'feed_limit' => 20];
        $b->period = 'daily_19';
        $b->save();

        return $this->channel = $b->fresh(['chat.city']);
    }
}
