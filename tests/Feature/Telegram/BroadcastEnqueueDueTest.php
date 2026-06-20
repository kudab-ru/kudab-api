<?php

namespace Tests\Feature\Telegram;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Services\Telegram\TelegramChatBroadcastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P0 автопостинг, фаза 1 — автонаполнение очереди (TelegramChatBroadcastService::enqueueDueForAllChannels).
 * Фабрик у этих моделей нет — данные сеем inline по образцу WebEventsTest.
 */
class BroadcastEnqueueDueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 12:00 — окно daily_10 уже прошло сегодня, last_run_at=null ⇒ канал due.
        Carbon::setTestNow(Carbon::create(2026, 3, 22, 12, 0, 0, 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): TelegramChatBroadcastService
    {
        return app(TelegramChatBroadcastService::class);
    }

    public function test_enqueues_earliest_city_event_for_due_channel(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');

        // Два кандидата — выбраться должен ближайший по start_time.
        $this->createEvent($city->id, $community->id, 'Позже', now()->addDays(3));
        $soon = $this->createEvent($city->id, $community->id, 'Раньше', now()->addDay());

        $chat = $this->createChannelChat($city->id, -1001);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        $summary = $this->service()->enqueueDueForAllChannels(now());

        $this->assertSame(1, $summary['due']);
        $this->assertSame(1, $summary['enqueued']);

        $items = TelegramChatBroadcastItem::query()->where('broadcast_id', $broadcast->id)->get();
        $this->assertCount(1, $items);
        $this->assertSame($soon->id, (int) $items->first()->event_id, 'должно выбраться ближайшее событие');
        $this->assertSame(TelegramChatBroadcastItem::STATUS_PENDING, $items->first()->status);
    }

    public function test_skips_channel_with_pending_item_in_queue(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $e1 = $this->createEvent($city->id, $community->id, 'Событие 1', now()->addDay());
        $this->createEvent($city->id, $community->id, 'Событие 2', now()->addDays(2));

        $chat = $this->createChannelChat($city->id, -1002);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        // Уже есть незакрытый item — не должны плодить второй.
        $this->makeItem($broadcast->id, $e1->id, TelegramChatBroadcastItem::STATUS_PENDING);

        $summary = $this->service()->enqueueDueForAllChannels(now());

        $this->assertSame(1, $summary['due']);
        $this->assertSame(1, $summary['skipped_queue_busy']);
        $this->assertSame(0, $summary['enqueued']);
        $this->assertSame(1, TelegramChatBroadcastItem::query()->where('broadcast_id', $broadcast->id)->count());
    }

    public function test_no_candidate_when_no_event_in_channel_city(): void
    {
        $voronezh = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $moskva = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);

        // Событие в Москве, канал — Воронежа: city_id-фильтр не должен его взять.
        $mskCommunity = $this->createCommunity($moskva->id, 'Организатор Мск');
        $this->createEvent($moskva->id, $mskCommunity->id, 'Москва', now()->addDay());

        $chat = $this->createChannelChat($voronezh->id, -1003);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        $summary = $this->service()->enqueueDueForAllChannels(now());

        $this->assertSame(1, $summary['due']);
        $this->assertSame(1, $summary['no_candidate']);
        $this->assertSame(0, $summary['enqueued']);
        $this->assertSame(0, TelegramChatBroadcastItem::query()->where('broadcast_id', $broadcast->id)->count());
    }

    public function test_does_not_pick_sold_out_event(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $e = $this->createEvent($city->id, $community->id, 'Распродано', now()->addDay());
        $e->tickets_status = 'sold_out';
        $e->save();

        $chat = $this->createChannelChat($city->id, -1007);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        $summary = $this->service()->enqueueDueForAllChannels(now());

        $this->assertSame(1, $summary['no_candidate']);
        $this->assertSame(0, $summary['enqueued']);
        $this->assertSame(0, TelegramChatBroadcastItem::query()->where('broadcast_id', $broadcast->id)->count());
    }

    public function test_does_not_pick_official_content_kind(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $e = $this->createEvent($city->id, $community->id, 'Официальное', now()->addDay());
        $e->content_kind = 'official';
        $e->save();

        $chat = $this->createChannelChat($city->id, -1008);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        $summary = $this->service()->enqueueDueForAllChannels(now());

        $this->assertSame(1, $summary['no_candidate']);
        $this->assertSame(0, $summary['enqueued']);
    }

    public function test_does_not_pick_event_from_already_used_group(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $groupId = $this->createEventGroup($community->id, $city->id, 'grp-key-1', 'концерт');

        // A — то же событие из источника 1 (уже постнуто), B — из источника 2 (та же группа).
        $a = $this->createEvent($city->id, $community->id, 'Концерт (источник 1)', now()->addDay());
        $a->event_group_id = $groupId;
        $a->save();
        $b = $this->createEvent($city->id, $community->id, 'Концерт (источник 2)', now()->addDays(2));
        $b->event_group_id = $groupId;
        $b->save();

        $chat = $this->createChannelChat($city->id, -1009);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $this->makeItem($broadcast->id, $a->id, TelegramChatBroadcastItem::STATUS_POSTED);

        $summary = $this->service()->enqueueDueForAllChannels(now());

        // A исключён Layer 1 (уже постнут), B — Layer 2 (группа занята) ⇒ нет кандидата.
        $this->assertSame(1, $summary['no_candidate']);
        $this->assertSame(0, $summary['enqueued']);
    }

    public function test_cross_time_prefers_title_not_recently_posted(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');

        // Недавно постнутый «Концерт» в канале (в окне cross-time).
        $postedConcert = $this->createEvent($city->id, $community->id, 'Концерт', now()->addDays(2));
        $chat = $this->createChannelChat($city->id, -1010);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $item = $this->makeItem($broadcast->id, $postedConcert->id, TelegramChatBroadcastItem::STATUS_POSTED);
        $item->posted_at = now()->subDays(2);
        $item->save();

        // Кандидаты с равным score: «Концерт» ближе (по tie-break выиграл бы), но title
        // недавно постился → cross-time должен предпочесть «Лекцию».
        $this->createEvent($city->id, $community->id, 'Концерт', now()->addDay());
        $lecture = $this->createEvent($city->id, $community->id, 'Лекция', now()->addDays(2));

        $summary = $this->service()->enqueueDueForAllChannels(now());

        $this->assertSame(1, $summary['enqueued']);
        $picked = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('status', TelegramChatBroadcastItem::STATUS_PENDING)
            ->first();
        $this->assertSame($lecture->id, (int) $picked->event_id, 'cross-time: свежий заголовок предпочтительнее');
    }

    public function test_cross_time_still_posts_when_all_titles_recent(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');

        $postedConcert = $this->createEvent($city->id, $community->id, 'Концерт', now()->addDays(2));
        $chat = $this->createChannelChat($city->id, -1011);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $item = $this->makeItem($broadcast->id, $postedConcert->id, TelegramChatBroadcastItem::STATUS_POSTED);
        $item->posted_at = now()->subDays(1);
        $item->save();

        // Единственный кандидат — тоже «Концерт» (title недавно постился) ⇒ fallback: всё равно постим.
        $newConcert = $this->createEvent($city->id, $community->id, 'Концерт', now()->addDay());

        $summary = $this->service()->enqueueDueForAllChannels(now());

        $this->assertSame(1, $summary['enqueued']);
        $picked = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('status', TelegramChatBroadcastItem::STATUS_PENDING)
            ->first();
        $this->assertSame($newConcert->id, (int) $picked->event_id);
    }

    public function test_not_due_when_period_off(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $this->createEvent($city->id, $community->id, 'Событие', now()->addDay());

        $chat = $this->createChannelChat($city->id, -1004);
        $this->createBroadcast($chat->id, 'off');

        $summary = $this->service()->enqueueDueForAllChannels(now());

        $this->assertSame(1, $summary['checked']);
        $this->assertSame(0, $summary['due']);
        $this->assertSame(0, $summary['enqueued']);
    }

    public function test_skips_channel_without_city(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $this->createEvent($city->id, $community->id, 'Событие', now()->addDay());

        $chat = $this->createChannelChat(null, -1005); // без city_id
        $this->createBroadcast($chat->id, 'daily_10');

        $summary = $this->service()->enqueueDueForAllChannels(now());

        $this->assertSame(1, $summary['due']);
        $this->assertSame(1, $summary['skipped_no_city']);
        $this->assertSame(0, $summary['enqueued']);
    }

    public function test_dry_run_counts_but_writes_nothing(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $this->createEvent($city->id, $community->id, 'Событие', now()->addDay());

        $chat = $this->createChannelChat($city->id, -1006);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        $summary = $this->service()->enqueueDueForAllChannels(now(), true);

        $this->assertSame(1, $summary['enqueued']); // считаем «было бы»
        $this->assertSame(0, TelegramChatBroadcastItem::query()->where('broadcast_id', $broadcast->id)->count(), 'dry-run не пишет');
    }

    // ----------------------------------------------------------------
    // helpers (по образцу WebEventsTest + telegram-сущности)
    // ----------------------------------------------------------------

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

    private function createChannelChat(?int $cityId, int $telegramChatId): TelegramChat
    {
        $chat = new TelegramChat();
        $chat->telegram_chat_id = $telegramChatId;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        if ($cityId !== null) {
            $chat->city_id = $cityId; // не в fillable — ставим напрямую
        }
        $chat->save();

        return $chat;
    }

    private function createBroadcast(int $chatId, string $period): TelegramChatBroadcast
    {
        return TelegramChatBroadcast::create([
            'chat_id'  => $chatId,
            'enabled'  => true,
            'settings' => ['period' => $period, 'template_code' => 'basic'],
        ]);
    }

    private function makeItem(int $broadcastId, int $eventId, string $status): TelegramChatBroadcastItem
    {
        $item = new TelegramChatBroadcastItem();
        $item->broadcast_id = $broadcastId;
        $item->event_id = $eventId;
        $item->status = $status;
        $item->save();

        return $item;
    }

    private function createEventGroup(int $communityId, ?int $cityId, string $groupKey, string $titleNorm): int
    {
        return (int) DB::table('event_groups')->insertGetId([
            'community_id' => $communityId,
            'city_id'      => $cityId,
            'group_key'    => $groupKey,
            'title_norm'   => $titleNorm,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
}
