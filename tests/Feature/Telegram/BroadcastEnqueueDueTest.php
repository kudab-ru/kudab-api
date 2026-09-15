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
        // айтем встаёт под придержкой: за это окно парсер пишет ТГ-текст, и только
        // потом бот его забирает (см. broadcast_text_grace_minutes)
        $this->assertSame(TelegramChatBroadcastItem::STATUS_PLANNED, $items->first()->status);
        $this->assertTrue($items->first()->planned_at->isFuture(), 'придержка должна быть в будущем');
    }

    public function test_fills_feed_up_to_limit(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $e1 = $this->createEvent($city->id, $community->id, 'Событие 1', now()->addDay());
        $this->createEvent($city->id, $community->id, 'Событие 2', now()->addDays(2));

        $chat = $this->createChannelChat($city->id, -1003);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        // Одна запись в ленте на семь постов — это не «занято».
        $this->makeItem($broadcast->id, $e1->id, TelegramChatBroadcastItem::STATUS_PENDING);

        $summary = $this->service()->enqueueDueForAllChannels(now());

        $this->assertSame(0, $summary['skipped_queue_busy'], 'лента ещё не полна');
        $this->assertSame(1, $summary['enqueued']);
        $this->assertSame(2, TelegramChatBroadcastItem::query()->where('broadcast_id', $broadcast->id)->count());
    }

    public function test_skips_channel_when_feed_is_full(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $e1 = $this->createEvent($city->id, $community->id, 'Событие 1', now()->addDay());
        $this->createEvent($city->id, $community->id, 'Событие 2', now()->addDays(2));

        $chat = $this->createChannelChat($city->id, -1002);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');

        // Лента на один пост — тогда одна незакрытая запись её заполняет.
        // Раньше «занято» означало «есть хоть что-то», теперь — «лента полна»:
        // канал держит до feed_limit постов вперёд.
        $broadcast->feed_limit = 1;
        $broadcast->save();

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
            ->where('status', TelegramChatBroadcastItem::STATUS_PLANNED)
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
            ->where('status', TelegramChatBroadcastItem::STATUS_PLANNED)
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

    /**
     * Окно расписания открывается по Москве, а не по UTC.
     *
     * Час в period («daily_10») подписан в админке как московский, и день
     * ленте назначается по Москве. Окно же считалось в поясе приложения —
     * UTC, — поэтому daily_10 открывался в 13:00 МСК. Проверяем границу:
     * в 09:30 МСК пост ещё не уходит, в 10:30 — уже.
     *
     * last_run_at стоит на вчерашних 20:00 МСК: это момент между вчерашним
     * московским окном и сегодняшним, и только на нём старое поведение
     * отличается от нового.
     */
    public function test_daily_window_opens_by_moscow_hour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 06:30:00', 'UTC')); // 09:30 МСК

        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $event = $this->createEvent($city->id, $community->id, 'Событие', Carbon::parse('2026-09-16 15:00:00', 'UTC'));

        $chat = $this->createChannelChat($city->id, -1017, 555444);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $broadcast->last_run_at = Carbon::parse('2026-09-14 17:00:00', 'UTC'); // вчера 20:00 МСК
        $broadcast->save();

        $this->makeItem($broadcast->id, $event->id, TelegramChatBroadcastItem::STATUS_PENDING);

        $this->assertCount(
            0,
            $this->service()->collectDueSingleRuns(now()),
            'в 09:30 МСК окно daily_10 ещё закрыто',
        );

        Carbon::setTestNow(Carbon::parse('2026-09-15 07:30:00', 'UTC')); // 10:30 МСК

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks, 'в 10:30 МСК окно уже открыто');
        $this->assertSame('publish', $tasks[0]['type']);

        Carbon::setTestNow();
    }

    /**
     * Событие, закончившееся к моменту отправки, снимается — и канал едет дальше.
     *
     * Между постановкой и отправкой проверки времени не было вообще: после
     * паузы канала первым уходил анонс уже прошедшего. Снятая запись обязана
     * перестать держать канал — иначе лечение хуже болезни.
     */
    public function test_finished_event_is_skipped_and_channel_moves_on(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $past = $this->createEvent($city->id, $community->id, 'Вчерашнее', now()->subDay());
        $future = $this->createEvent($city->id, $community->id, 'Завтрашнее', now()->addDay());

        $chat = $this->createChannelChat($city->id, -1018, 555555);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $stale = $this->makeItem($broadcast->id, $past->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $fresh = $this->makeItem($broadcast->id, $future->id, TelegramChatBroadcastItem::STATUS_PENDING);

        $this->assertCount(0, $this->service()->collectDueSingleRuns(now()), 'прошедшее не уходит');
        $this->assertSame(TelegramChatBroadcastItem::STATUS_SKIPPED, $stale->fresh()->status);

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks, 'следующая запись канала пошла в работу');
        $this->assertSame($fresh->id, $tasks[0]['item_id']);
    }

    /** Многодневка, которая ещё идёт, не считается прошедшей: смотрим на конец, а не на начало. */
    public function test_running_multiday_event_is_not_skipped(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $event = $this->createEvent($city->id, $community->id, 'Выставка', now()->subDays(3));
        $event->end_time = now()->addDays(10);
        $event->save();

        $chat = $this->createChannelChat($city->id, -1019, 555666);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $item = $this->makeItem($broadcast->id, $event->id, TelegramChatBroadcastItem::STATUS_PENDING);

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks);
        $this->assertSame($item->id, $tasks[0]['item_id']);
    }

    /**
     * Текст, собранный под другой день, пересобирается перед отправкой.
     *
     * Раньше ensureEventCaption выходил при любом непустом тексте, и пост,
     * пролежавший лишние сутки, уходил дословно — со словом «сегодня» про
     * позавчера.
     */
    public function test_stale_template_caption_is_rebuilt_on_send(): void
    {
        $this->seedBasicTemplate();

        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        // Событие завтра: текст, собранный вчера, назвал бы этот день датой,
        // а собранный сегодня — «завтра». По этому слову и отличаем.
        $event = $this->createEvent(
            $city->id,
            $community->id,
            'Концерт',
            Carbon::now('Europe/Moscow')->addDay()->setTime(19, 0, 0),
        );

        $chat = $this->createChannelChat($city->id, -1020, 555777);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $item = $this->makeItem($broadcast->id, $event->id, TelegramChatBroadcastItem::STATUS_PENDING);
        // Запись «ждёт дня»: publish_at нет, а текст собран два дня назад, от
        // planned_at. Именно так и лежат записи, переживающие паузу канала —
        // просрочкой они не считаются, потому что дня им никто не назначал.
        $item->planned_at = now()->subDays(2);
        $item->caption = 'СТАРЫЙ ТЕКСТ';
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_TEMPLATE;
        $item->save();

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks);
        $this->assertNotSame('СТАРЫЙ ТЕКСТ', $tasks[0]['caption'], 'текст пересобран под день отправки');
        $this->assertStringContainsString('завтра', $tasks[0]['caption'], 'пересобран именно под день отправки');
        $this->assertNotSame('СТАРЫЙ ТЕКСТ', (string) $item->fresh()->caption);
    }

    /**
     * Анонс, написанный ПОСЛЕ сборки подписи, доезжает до поста.
     *
     * Лента стоит на неделю вперёд, а ТГ-анонс парсер пишет перед самой
     * публикацией — ради экономии: платим только за то, что действительно
     * уйдёт в канал. Если подпись не пересобрать перед отправкой, свежий текст
     * останется в базе, а подписчик получит сырое описание из парсера.
     */
    public function test_tg_description_written_after_enqueue_reaches_the_post(): void
    {
        $this->seedBasicTemplate();
        // Базовый шаблон в тестах — только заголовок и дата; здесь проверяется
        // как раз описание, поэтому кладём его в тело шаблона.
        DB::table('telegram.message_templates')
            ->where('code', 'basic')
            ->update(['body' => "🎟 <b>{title}</b>\n{description}"]);

        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $event = $this->createEvent(
            $city->id,
            $community->id,
            'Концерт',
            Carbon::now('Europe/Moscow')->addDay()->setTime(19, 0, 0),
        );
        $event->description = 'сырое описание из парсера';
        $event->save();

        $chat = $this->createChannelChat($city->id, -1029, 555991);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $item = $this->makeItem($broadcast->id, $event->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $item->publish_at = now();
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_TEMPLATE;
        $item->save();

        // Подпись собрана в момент постановки — с тем описанием, что было тогда.
        $this->service()->collectDueSingleRuns(now());
        $this->assertStringContainsString('сырое описание из парсера', (string) $item->fresh()->caption);

        // Парсер написал анонс уже после — ровно так и работает describe-due.
        $event->tg_description = 'Свежий анонс от парсера';
        $event->save();
        $item->refresh();
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->posted_at = null;
        $item->claimed_at = null;
        $item->save();

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks);
        $this->assertStringContainsString('Свежий анонс от парсера', $tasks[0]['caption']);
        $this->assertStringNotContainsString('сырое описание из парсера', $tasks[0]['caption']);
    }

    /** Свой текст писал человек — его не пересобирают, даже если день разъехался. */
    public function test_manual_caption_survives_stale_day(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $event = $this->createEvent($city->id, $community->id, 'Концерт', now()->addDays(2));

        $chat = $this->createChannelChat($city->id, -1021, 555888);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $item = $this->makeItem($broadcast->id, $event->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $item->planned_at = now()->subDays(2);
        $item->caption = 'МОЙ ТЕКСТ';
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_MANUAL;
        $item->save();

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks);
        $this->assertSame('МОЙ ТЕКСТ', $tasks[0]['caption']);
    }

    /** Зазор между постами: канал молчит, пока не пройдёт положенное время. */
    public function test_gap_between_posts_holds_the_channel(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $posted = $this->createEvent($city->id, $community->id, 'Утреннее', now()->addDay());
        $next = $this->createEvent($city->id, $community->id, 'Следующее', now()->addDays(2));

        $chat = $this->createChannelChat($city->id, -1022, 555999);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $this->makePostedItem($broadcast->id, $posted->id, now()->subMinutes(10));
        $this->makeItem($broadcast->id, $next->id, TelegramChatBroadcastItem::STATUS_PENDING);

        $this->assertCount(0, $this->service()->collectDueSingleRuns(now()), 'зазор ещё не вышел');

        Carbon::setTestNow(now()->addHours(2));

        $this->assertCount(1, $this->service()->collectDueSingleRuns(now()), 'зазор вышел — пост пошёл');

        Carbon::setTestNow();
    }

    /**
     * Зазор считается по факту отправки любого вида, а не по last_run_at.
     *
     * Портрет площадки last_run_at намеренно не двигает, поэтому по нему
     * канал выглядел бы молчавшим — и событие ушло бы следом за портретом.
     */
    public function test_gap_counts_venue_post_too(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $event = $this->createEvent($city->id, $community->id, 'Событие', now()->addDay());

        $chat = $this->createChannelChat($city->id, -1023, 556000);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10'); // last_run_at пуст
        $this->makePostedVenueItem($broadcast->id, now()->subMinutes(5));
        $this->makeItem($broadcast->id, $event->id, TelegramChatBroadcastItem::STATUS_PENDING);

        $this->assertCount(0, $this->service()->collectDueSingleRuns(now()));
    }

    /**
     * Пост, чей день прошёл давно, теряет день — но не выбрасывается.
     *
     * Так пачка просроченных не уезжает подряд после паузы канала: без дня
     * записи уходят по одной за окно расписания. Выбрасывать их нельзя — на
     * живой очереди три из пяти просроченных оказались многодневками, которые
     * ещё идут, а подобрать их заново нечем: подбор смотрит на дату начала.
     */
    public function test_overdue_item_loses_its_day_but_survives(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $event = $this->createEvent($city->id, $community->id, 'Событие', now()->addDays(3));

        $chat = $this->createChannelChat($city->id, -1024, 556111);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $item = $this->makeItem($broadcast->id, $event->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $item->publish_at = now()->subHours(3);
        $item->save();

        $this->assertCount(0, $this->service()->collectDueSingleRuns(now()), 'в этот тик не уходит');

        $fresh = $item->fresh();
        $this->assertNull($fresh->publish_at, 'день снят');
        $this->assertSame(TelegramChatBroadcastItem::STATUS_PENDING, $fresh->status, 'пост остался в ленте');
    }

    /** Многодневка, которая ещё идёт, просрочку переживает и уходит следующим тиком. */
    public function test_overdue_running_event_still_goes_later(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $event = $this->createEvent($city->id, $community->id, 'Выставка', now()->subDays(2));
        $event->end_time = now()->addMonths(3);
        $event->save();

        $chat = $this->createChannelChat($city->id, -1027, 556444);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $item = $this->makeItem($broadcast->id, $event->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $item->publish_at = now()->subDays(3);
        $item->save();

        $this->assertCount(0, $this->service()->collectDueSingleRuns(now()));
        $this->assertNull($item->fresh()->publish_at);

        // Следующий тик: дня нет, но запись жива и уходит по окну расписания.
        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks, 'многодневка не потеряна');
        $this->assertSame($item->id, $tasks[0]['item_id']);
    }

    /**
     * Опоздание, устроенное зазором, не считается просрочкой.
     *
     * Между зазором (90 мин) и отсечкой (2 ч) всего полчаса: если предыдущий
     * пост канала ушёл с задержкой, следующий освобождался уже за отсечкой и
     * снимался — задержали мы, а наказана запись.
     */
    public function test_gap_delay_does_not_make_item_overdue(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $posted = $this->createEvent($city->id, $community->id, 'Предыдущее', now()->addDay());
        $event = $this->createEvent($city->id, $community->id, 'Событие', now()->addDays(3));

        $chat = $this->createChannelChat($city->id, -1026, 556333);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        // Канал был занят: предыдущий пост ушёл 95 минут назад, зазор вышел
        // пять минут назад.
        $this->makePostedItem($broadcast->id, $posted->id, now()->subMinutes(95));

        $item = $this->makeItem($broadcast->id, $event->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $item->publish_at = now()->subMinutes(150); // по календарю опоздал на 2,5 часа
        $item->save();

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks, 'пост уходит: канал освободился пять минут назад');
        $this->assertSame($item->id, $tasks[0]['item_id']);
        $this->assertNotSame(TelegramChatBroadcastItem::STATUS_SKIPPED, $item->fresh()->status);
    }

    /** Небольшая задержка просрочкой не считается: пост уходит. */
    public function test_slightly_late_item_still_goes(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $event = $this->createEvent($city->id, $community->id, 'Событие', now()->addDays(3));

        $chat = $this->createChannelChat($city->id, -1025, 556222);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $item = $this->makeItem($broadcast->id, $event->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $item->publish_at = now()->subMinutes(30);
        $item->save();

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks);
        $this->assertSame($item->id, $tasks[0]['item_id']);
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
        $fresh = $this->makeReviewItem($broadcast->id, $e2->id, 555444, now()->addHours(2));

        $count = $this->service()->autoApproveExpiredReviews(now());

        $this->assertSame(1, $count);
        $this->assertSame(TelegramChatBroadcastItem::STATUS_AUTO_APPROVED, $expired->refresh()->status);
        $this->assertSame('timeout', $expired->review_action);
        $this->assertSame(TelegramChatBroadcastItem::STATUS_PENDING_REVIEW, $fresh->refresh()->status);
    }

    // ===== Надёжность поллера, Часть 1: claim-before-post =====

    public function test_poll_claims_publish_item_and_returns_token(): void
    {
        [$broadcast] = $this->seedPublishItem(-2001, 556001, TelegramChatBroadcastItem::STATUS_APPROVED);

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks);
        $this->assertSame('publish', $tasks[0]['type']);
        $this->assertArrayHasKey('claim_token', $tasks[0]);
        $this->assertNotEmpty($tasks[0]['claim_token']);

        $item = TelegramChatBroadcastItem::query()->where('broadcast_id', $broadcast->id)->first();
        $this->assertNotNull($item->claimed_at, 'claimed_at проставлен');
        $this->assertSame($tasks[0]['claim_token'], $item->claim_token);
    }

    public function test_second_poll_does_not_reclaim_within_lease(): void
    {
        $this->seedPublishItem(-2002, 556002, TelegramChatBroadcastItem::STATUS_APPROVED);

        $first = $this->service()->collectDueSingleRuns(now());
        $this->assertCount(1, $first);

        // Второй poll в пределах lease — айтем заклеймлен, publish-задачи нет (анти-дубль).
        $second = $this->service()->collectDueSingleRuns(now());
        $this->assertCount(0, $second);
    }

    public function test_stale_claim_is_reclaimed_after_lease(): void
    {
        [$broadcast] = $this->seedPublishItem(-2003, 556003, TelegramChatBroadcastItem::STATUS_APPROVED);

        $item = TelegramChatBroadcastItem::query()->where('broadcast_id', $broadcast->id)->first();
        $item->claimed_at = now()->subSeconds(TelegramChatBroadcastService::CLAIM_LEASE_SECONDS + 60);
        $item->claim_token = 'stale-token';
        $item->save();

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks, 'протухший claim реклеймится');
        $this->assertNotSame('stale-token', $tasks[0]['claim_token'], 'выдан новый токен');
    }

    public function test_mark_sent_with_matching_token_posts_and_moves_last_run(): void
    {
        [$broadcast, $event] = $this->seedPublishItem(-2004, 556004, TelegramChatBroadcastItem::STATUS_APPROVED);
        config(['services.bot.superadmin_telegram_id' => 556004]); // владелец-superadmin проходит role-guard mark-пути
        $item = TelegramChatBroadcastItem::query()->where('broadcast_id', $broadcast->id)->first();

        $token = $this->itemRepo()->claimForPublish($item->id, now(), 300);
        $this->assertNotNull($token);

        $this->service()->markSingleEventSentForChat(556004, -2004, $event->id, null, $token);

        $item->refresh();
        $this->assertSame(TelegramChatBroadcastItem::STATUS_POSTED, $item->status);
        $this->assertNull($item->claim_token, 'claim очищен после поста');
        $this->assertNotNull($broadcast->fresh()->last_run_at, 'last_run сдвинут');
    }

    public function test_mark_sent_with_wrong_token_is_noop(): void
    {
        [$broadcast, $event] = $this->seedPublishItem(-2005, 556005, TelegramChatBroadcastItem::STATUS_APPROVED);
        config(['services.bot.superadmin_telegram_id' => 556005]); // владелец-superadmin проходит role-guard mark-пути
        $item = TelegramChatBroadcastItem::query()->where('broadcast_id', $broadcast->id)->first();

        $this->itemRepo()->claimForPublish($item->id, now(), 300);

        // Чужой токен (stale-claim другого поллера) — не постит, last_run не двигаем.
        $this->service()->markSingleEventSentForChat(556005, -2005, $event->id, null, 'wrong-token');

        $item->refresh();
        $this->assertSame(TelegramChatBroadcastItem::STATUS_APPROVED, $item->status, 'чужой токен не постит');
        $this->assertNull($broadcast->fresh()->last_run_at, 'last_run не двигается за чужой пост');
    }

    public function test_mark_sent_without_token_is_backward_compatible(): void
    {
        [$broadcast, $event] = $this->seedPublishItem(-2006, 556006, TelegramChatBroadcastItem::STATUS_APPROVED);
        config(['services.bot.superadmin_telegram_id' => 556006]); // владелец-superadmin проходит role-guard mark-пути

        // Старый бот без токена — прежнее поведение: постит.
        $this->service()->markSingleEventSentForChat(556006, -2006, $event->id, null, null);

        $item = TelegramChatBroadcastItem::query()->where('broadcast_id', $broadcast->id)->first();
        $this->assertSame(TelegramChatBroadcastItem::STATUS_POSTED, $item->status);
    }

    // ----------------------------------------------------------------
    // helpers (по образцу WebEventsTest + telegram-сущности)
    // ----------------------------------------------------------------

    private function itemRepo(): \App\Contracts\Telegram\TelegramChatBroadcastItemRepositoryInterface
    {
        return app(\App\Contracts\Telegram\TelegramChatBroadcastItemRepositoryInterface::class);
    }

    /**
     * Сидит publish-айтем (city/community/event/chat+owner/broadcast/item).
     *
     * @return array{0: TelegramChatBroadcast, 1: Event, 2: TelegramChat, 3: TelegramChatBroadcastItem}
     */
    private function seedPublishItem(int $tgChatId, int $ownerTgId, string $status): array
    {
        $city = $this->insertCity('Воронеж', 'voronezh', 'active', 39.2003, 51.6608);
        $community = $this->createCommunity($city->id, 'Организатор');
        $event = $this->createEvent($city->id, $community->id, 'Событие', now()->addDay());
        $chat = $this->createChannelChat($city->id, $tgChatId, $ownerTgId);
        $broadcast = $this->createBroadcast($chat->id, 'daily_10');
        $item = $this->makeItem($broadcast->id, $event->id, $status);

        return [$broadcast, $event, $chat, $item];
    }

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
            'name' => $name,
            'city_id' => $cityId,
        ]);
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

    private function makeReviewItem(int $broadcastId, int $eventId, int $reviewerTelegramId, Carbon $deadline, ?int $messageId = null): TelegramChatBroadcastItem
    {
        $item = new TelegramChatBroadcastItem;
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

    private function makePostedItem(int $broadcastId, int $eventId, Carbon $postedAt): TelegramChatBroadcastItem
    {
        $item = $this->makeItem($broadcastId, $eventId, TelegramChatBroadcastItem::STATUS_POSTED);
        $item->posted_at = $postedAt;
        $item->save();

        return $item;
    }

    private function makePostedVenueItem(int $broadcastId, Carbon $postedAt): TelegramChatBroadcastItem
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcastId;
        $item->kind = TelegramChatBroadcastItem::KIND_VENUE;
        $item->status = TelegramChatBroadcastItem::STATUS_POSTED;
        $item->caption = 'портрет';
        $item->posted_at = $postedAt;
        $item->save();

        return $item;
    }

    /**
     * Шаблон поста в базе.
     *
     * Без него EventCaptionBuilder бросает «Шаблон не найден», сборка молча
     * проглатывает исключение и старый текст остаётся на месте — тест прошёл
     * бы и на сломанном коде.
     */
    private function seedBasicTemplate(): void
    {
        DB::table('telegram.message_templates')->insert([
            'code' => 'basic',
            'locale' => 'ru',
            'name' => 'Базовый',
            'body' => "🎟 <b>{title}</b>\n🗓 {start_time}",
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createEventGroup(int $communityId, ?int $cityId, string $groupKey, string $titleNorm): int
    {
        return (int) DB::table('event_groups')->insertGetId([
            'community_id' => $communityId,
            'city_id' => $cityId,
            'group_key' => $groupKey,
            'title_norm' => $titleNorm,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
