<?php

namespace Tests\Feature\Telegram;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Models\Venue;
use App\Services\Telegram\TelegramChatBroadcastService;
use App\Services\Telegram\TelegramVenuePortraitService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Этап 2 venue-portrait — постановка портрета площадки в очередь + анти-повтор
 * (TelegramVenuePortraitService). Фабрик у этих моделей нет — сеем inline по
 * образцу BroadcastEnqueueDueTest.
 */
class VenuePortraitEnqueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 3, 22, 12, 0, 0, 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): TelegramVenuePortraitService
    {
        return app(TelegramVenuePortraitService::class);
    }

    public function test_enqueues_venue_portrait_for_due_channel(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $venue = $this->createVenue($city->id, 'Зелёный театр', 'zeleny-teatr', 'Открытая летняя сцена: концерты под небом.');

        $chat = $this->createChannelChat($city->id, -3001);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        $summary = $this->service()->enqueueDueVenuePortraits(now());

        $this->assertSame(1, $summary['due']);
        $this->assertSame(1, $summary['enqueued']);

        $item = TelegramChatBroadcastItem::query()->where('broadcast_id', $broadcast->id)->first();
        $this->assertNotNull($item);
        $this->assertSame(TelegramChatBroadcastItem::KIND_VENUE, $item->kind);
        $this->assertSame($venue->id, (int) $item->venue_id);
        $this->assertNull($item->event_id, 'у портрета нет event_id');
        $this->assertStringContainsString('Зелёный театр', (string) $item->caption);
        $this->assertSame(TelegramChatBroadcastItem::STATUS_PENDING, $item->status);
    }

    public function test_rotation_prefers_never_posted_over_earlier_posted(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $chat = $this->createChannelChat($city->id, -3002);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        // A уже выходила портретом 100 дней назад (вне кулдауна), B — ни разу.
        $a = $this->createVenue($city->id, 'Площадка A', 'venue-a', 'проза A');
        $b = $this->createVenue($city->id, 'Площадка B', 'venue-b', 'проза B');
        $this->makeVenueItem($broadcast->id, $a->id, TelegramChatBroadcastItem::STATUS_POSTED, now()->subDays(100));

        $picked = $this->service()->pickNextVenueForChat($city->id, $broadcast->id, now());

        $this->assertNotNull($picked);
        $this->assertSame($b->id, $picked->id, 'ротация: ни разу не выходившая — вперёд');
    }

    public function test_cooldown_excludes_recently_posted_venue(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $chat = $this->createChannelChat($city->id, -3003);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        // Единственная площадка постилась 30 дней назад — в кулдауне (90д) ⇒ кандидата нет.
        $v = $this->createVenue($city->id, 'Площадка', 'venue-c', 'проза');
        $this->makeVenueItem($broadcast->id, $v->id, TelegramChatBroadcastItem::STATUS_POSTED, now()->subDays(30));

        $picked = $this->service()->pickNextVenueForChat($city->id, $broadcast->id, now());

        $this->assertNull($picked, 'площадка в кулдауне не выбирается');
    }

    public function test_cross_format_excludes_venue_with_recent_event_spotlight(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $chat = $this->createChannelChat($city->id, -3004);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        // У площадки есть проза, но её событие ушло спотлайтом 2 дня назад ⇒ кросс-формат исключает.
        $v = $this->createVenue($city->id, 'Площадка', 'venue-d', 'проза');
        $event = $this->createEvent($city->id, $community->id, 'Концерт', now()->addDay());
        $event->venue_id = $v->id;
        $event->save();
        $item = $this->makeItem($broadcast->id, $event->id, TelegramChatBroadcastItem::STATUS_POSTED);
        $item->posted_at = now()->subDays(2);
        $item->save();

        $picked = $this->service()->pickNextVenueForChat($city->id, $broadcast->id, now());

        $this->assertNull($picked, 'площадка со свежим спотлайтом события не берётся портретом');
    }

    public function test_weekly_cadence_not_due_when_portrait_posted_recently(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $chat = $this->createChannelChat($city->id, -3005);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        $v = $this->createVenue($city->id, 'Площадка', 'venue-e', 'проза');
        // Портрет уже постнут 2 дня назад ⇒ канал не «созрел» под недельный каденс.
        $this->makeVenueItem($broadcast->id, $v->id, TelegramChatBroadcastItem::STATUS_POSTED, now()->subDays(2));

        $summary = $this->service()->enqueueDueVenuePortraits(now());

        $this->assertSame(0, $summary['due'], 'портрет постили <7 дней назад — не пора');
        $this->assertSame(0, $summary['enqueued']);
    }

    public function test_collect_due_returns_venue_publish_task_with_caption(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $chat = $this->createChannelChat($city->id, -3006, 557000);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $v = $this->createVenue($city->id, 'Площадка', 'venue-f', 'проза');

        // Готовый venue-айтем в очереди (pending) с caption.
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_VENUE;
        $item->venue_id = $v->id;
        $item->caption = '🏛 <b>Площадка</b>\n\nпроза';
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->save();

        $tasks = app(TelegramChatBroadcastService::class)->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks);
        $this->assertSame('venue', $tasks[0]['kind']);
        $this->assertSame('publish', $tasks[0]['type']);
        $this->assertSame($item->id, $tasks[0]['item_id']);
        $this->assertArrayNotHasKey('event_id', $tasks[0]);
        $this->assertStringContainsString('Площадка', $tasks[0]['caption']);
        $this->assertNotEmpty($tasks[0]['claim_token']);
    }

    // ---------------------------------------------------------------- helpers

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
        return Community::create(['name' => $name, 'city_id' => $cityId]);
    }

    private function createVenue(int $cityId, string $name, string $slug, ?string $tgPortrait): Venue
    {
        $venue = new Venue;
        $venue->city_id = $cityId;
        $venue->name = $name;
        $venue->slug = $slug;
        $venue->status = 'active';
        if ($tgPortrait !== null) {
            $venue->tg_portrait = $tgPortrait;
        }
        $venue->save();

        return $venue;
    }

    private function createEvent(int $cityId, int $communityId, string $title, Carbon $startTime): Event
    {
        $event = new Event;
        $event->community_id = $communityId;
        $event->title = $title;
        $event->status = 'active';
        $event->city_id = $cityId;
        $event->start_time = $startTime;
        $event->start_date = $startTime->toDateString();
        $event->save();

        return $event;
    }

    private function createChannelChat(?int $cityId, int $telegramChatId, ?int $ownerTelegramId = null): TelegramChat
    {
        $chat = new TelegramChat;
        $chat->telegram_chat_id = $telegramChatId;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        if ($cityId !== null) {
            $chat->city_id = $cityId;
        }
        if ($ownerTelegramId !== null) {
            $owner = TelegramUser::create(['telegram_id' => $ownerTelegramId]);
            $chat->telegram_user_id = $owner->id;
        }
        $chat->save();

        return $chat;
    }

    private function createBroadcast(int $chatId, string $period): TelegramChatBroadcast
    {
        return TelegramChatBroadcast::create([
            'chat_id' => $chatId,
            'enabled' => true,
            'settings' => ['period' => $period, 'template_code' => 'basic'],
        ]);
    }

    private function makeItem(int $broadcastId, int $eventId, string $status): TelegramChatBroadcastItem
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcastId;
        $item->event_id = $eventId;
        $item->status = $status;
        $item->save();

        return $item;
    }

    private function makeVenueItem(int $broadcastId, int $venueId, string $status, ?Carbon $postedAt = null): TelegramChatBroadcastItem
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcastId;
        $item->kind = TelegramChatBroadcastItem::KIND_VENUE;
        $item->venue_id = $venueId;
        $item->caption = 'x';
        $item->status = $status;
        if ($postedAt !== null) {
            $item->posted_at = $postedAt;
        }
        $item->save();

        return $item;
    }
}
