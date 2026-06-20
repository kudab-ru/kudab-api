<?php

namespace Tests\Feature\Telegram;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
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

    public function test_review_gate_enqueues_pending_review_with_reviewer_and_deadline(): void
    {
        config([
            'services.bot.broadcast_review_gate' => true,
            'services.bot.broadcast_review_timeout_minutes' => 120,
        ]);

        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $this->createEvent($city->id, $community->id, 'Событие', now()->addDay());

        $chat = $this->createChannelChat($city->id, -1012, 555000);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        $summary = $this->service()->enqueueDueForAllChannels(now());

        $this->assertSame(1, $summary['enqueued']);
        $item = TelegramChatBroadcastItem::query()->where('broadcast_id', $broadcast->id)->first();
        $this->assertNotNull($item);
        $this->assertSame(TelegramChatBroadcastItem::STATUS_PENDING_REVIEW, $item->status);
        $this->assertSame(555000, (int) $item->review_reviewer_telegram_id);
        $this->assertTrue($item->review_deadline_at->equalTo(now()->addMinutes(120)));
    }

    public function test_review_gate_skips_channel_without_owner(): void
    {
        config(['services.bot.broadcast_review_gate' => true]);

        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $this->createEvent($city->id, $community->id, 'Событие', now()->addDay());

        $chat = $this->createChannelChat($city->id, -1013); // без owner
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        $summary = $this->service()->enqueueDueForAllChannels(now());

        $this->assertSame(1, $summary['skipped_no_reviewer']);
        $this->assertSame(0, $summary['enqueued']);
        $this->assertSame(0, TelegramChatBroadcastItem::query()->where('broadcast_id', $broadcast->id)->count());
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

    // ===== P0.5b: poll type-branching + decideReview + sweeper =====

    public function test_poll_returns_review_task_for_pending_review(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $event = $this->createEvent($city->id, $community->id, 'Событие', now()->addDay());

        $chat = $this->createChannelChat($city->id, -1014, 555111);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $item = $this->makeReviewItem($broadcast->id, $event->id, 555111, now()->addHours(2));

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks);
        $this->assertSame('review', $tasks[0]['type']);
        $this->assertSame($item->id, $tasks[0]['item_id']);
        $this->assertSame(555111, $tasks[0]['reviewer_telegram_id']);
        $this->assertSame($event->id, $tasks[0]['event_id']);
    }

    public function test_poll_skips_pending_review_with_preview_already_sent(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $event = $this->createEvent($city->id, $community->id, 'Событие', now()->addDay());

        $chat = $this->createChannelChat($city->id, -1015, 555111);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        // превью уже отправлено (review_message_id) → poll не должен возвращать задачу
        $this->makeReviewItem($broadcast->id, $event->id, 555111, now()->addHours(2), 99999);

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(0, $tasks);
    }

    public function test_poll_returns_publish_task_for_approved_item(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $event = $this->createEvent($city->id, $community->id, 'Событие', now()->addDay());

        $chat = $this->createChannelChat($city->id, -1016, 555222);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $this->makeItem($broadcast->id, $event->id, TelegramChatBroadcastItem::STATUS_APPROVED);

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks);
        $this->assertSame('publish', $tasks[0]['type']);
        $this->assertSame(555222, $tasks[0]['telegram_id']);
        $this->assertSame($event->id, $tasks[0]['event_id']);
    }

    public function test_decide_review_approve_sets_approved(): void
    {
        $item = $this->makeStandaloneReviewItem(555333);

        $this->service()->decideReview(555333, $item->id, true);

        $item->refresh();
        $this->assertSame(TelegramChatBroadcastItem::STATUS_APPROVED, $item->status);
        $this->assertSame('approve', $item->review_action);
        $this->assertNotNull($item->reviewed_at);
    }

    public function test_decide_review_reject_sets_rejected(): void
    {
        $item = $this->makeStandaloneReviewItem(555333);

        $this->service()->decideReview(555333, $item->id, false);

        $item->refresh();
        $this->assertSame(TelegramChatBroadcastItem::STATUS_REJECTED, $item->status);
        $this->assertSame('reject', $item->review_action);
    }

    public function test_decide_review_rejects_wrong_reviewer(): void
    {
        $item = $this->makeStandaloneReviewItem(555333);

        $this->expectException(\RuntimeException::class);
        $this->service()->decideReview(999999, $item->id, true);
    }

    public function test_decide_review_is_idempotent_when_already_decided(): void
    {
        $item = $this->makeStandaloneReviewItem(555333);
        $item->status = TelegramChatBroadcastItem::STATUS_APPROVED;
        $item->save();

        // повторное решение по уже-решённому — no-op, не падает
        $this->service()->decideReview(555333, $item->id, false);

        $item->refresh();
        $this->assertSame(TelegramChatBroadcastItem::STATUS_APPROVED, $item->status);
    }

    public function test_auto_approve_expired_reviews(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $e1 = $this->createEvent($city->id, $community->id, 'Просрочено', now()->addDay());
        $e2 = $this->createEvent($city->id, $community->id, 'Ещё ждёт', now()->addDays(2));

        $chat = $this->createChannelChat($city->id, -1017, 555444);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        $expired = $this->makeReviewItem($broadcast->id, $e1->id, 555444, now()->subMinute());
        $fresh   = $this->makeReviewItem($broadcast->id, $e2->id, 555444, now()->addHours(2));

        $count = $this->service()->autoApproveExpiredReviews(now());

        $this->assertSame(1, $count);
        $this->assertSame(TelegramChatBroadcastItem::STATUS_AUTO_APPROVED, $expired->refresh()->status);
        $this->assertSame('timeout', $expired->review_action);
        $this->assertSame(TelegramChatBroadcastItem::STATUS_PENDING_REVIEW, $fresh->refresh()->status);
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

    private function createChannelChat(?int $cityId, int $telegramChatId, ?int $ownerTelegramId = null): TelegramChat
    {
        $chat = new TelegramChat();
        $chat->telegram_chat_id = $telegramChatId;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        if ($cityId !== null) {
            $chat->city_id = $cityId; // не в fillable — ставим напрямую
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

    private function makeReviewItem(int $broadcastId, int $eventId, int $reviewerTelegramId, Carbon $deadline, ?int $messageId = null): TelegramChatBroadcastItem
    {
        $item = new TelegramChatBroadcastItem();
        $item->broadcast_id = $broadcastId;
        $item->event_id = $eventId;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING_REVIEW;
        $item->review_reviewer_telegram_id = $reviewerTelegramId;
        $item->review_deadline_at = $deadline;
        if ($messageId !== null) {
            $item->review_message_id = $messageId;
        }
        $item->save();

        return $item;
    }

    /** Самодостаточный pending_review item (со своим city/community/event/chat/broadcast). */
    private function makeStandaloneReviewItem(int $reviewerTelegramId): TelegramChatBroadcastItem
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $event = $this->createEvent($city->id, $community->id, 'Событие', now()->addDay());
        $chat = $this->createChannelChat($city->id, -1099, $reviewerTelegramId);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        return $this->makeReviewItem($broadcast->id, $event->id, $reviewerTelegramId, now()->addHours(2));
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
