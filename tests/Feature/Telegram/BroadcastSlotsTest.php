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
 * Несколько постов в один день: слоты.
 *
 * Слоты — это список часов в настройках канала. Пустой список означает
 * прежнее поведение: один пост в день, час из расписания. Поэтому все тесты
 * здесь идут парами — «без слотов как раньше» и «со слотами по-новому».
 */
class BroadcastSlotsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TelegramChatBroadcastService
    {
        return app(TelegramChatBroadcastService::class);
    }

    public function test_filler_puts_one_post_per_day_without_slots(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC')); // 06:00 МСК

        [$broadcast] = $this->channelWithEvents(6);

        $this->service()->fillFeedDays($broadcast, now());

        $hours = $this->publishHours($broadcast->id);
        $this->assertSame([10], array_values(array_unique($hours)), 'час один — из расписания');
        $this->assertSame(count($hours), count(array_unique($this->publishDays($broadcast->id))), 'по одному посту на день');

        Carbon::setTestNow();
    }

    public function test_filler_fills_both_slots_of_a_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC')); // 06:00 МСК

        [$broadcast] = $this->channelWithEvents(6);
        $broadcast->slots = [10, 19];
        $broadcast->horizon_days = 2;
        $broadcast->save();

        $this->service()->fillFeedDays($broadcast->fresh(), now());

        $hours = $this->publishHours($broadcast->id);
        sort($hours);

        $this->assertSame([10, 10, 19, 19], $hours, 'два дня по два слота');

        Carbon::setTestNow();
    }

    /**
     * Второй слот дня уходит, хотя суточное окно закрыто первым постом.
     *
     * Это и есть смысл правки гейта: запись с назначенным моментом идёт по
     * нему, а не по окну расписания, иначе второй слот не открылся бы никогда.
     */
    public function test_second_slot_of_the_day_is_delivered(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 16:30:00', 'UTC')); // 19:30 МСК

        [$broadcast, $events] = $this->channelWithEvents(2);
        $broadcast->slots = [10, 19];
        $broadcast->save();

        // Утренний пост уже ушёл: окно расписания на сегодня закрыто.
        $morning = $this->makeItem($broadcast->id, $events[0]->id, TelegramChatBroadcastItem::STATUS_POSTED);
        $morning->publish_at = Carbon::parse('2026-09-15 07:00:00', 'UTC');
        $morning->posted_at = Carbon::parse('2026-09-15 07:00:30', 'UTC');
        $morning->save();
        $broadcast->last_run_at = Carbon::parse('2026-09-15 07:00:30', 'UTC');
        $broadcast->save();

        $evening = $this->makeItem($broadcast->id, $events[1]->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $evening->publish_at = Carbon::parse('2026-09-15 16:00:00', 'UTC'); // 19:00 МСК
        $evening->save();

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks, 'вечерний слот открылся');
        $this->assertSame($evening->id, $tasks[0]['item_id']);

        Carbon::setTestNow();
    }

    /** Без слотов запись с днём по-прежнему ждёт своего окна. */
    public function test_without_slots_second_post_of_the_day_waits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 16:30:00', 'UTC')); // 19:30 МСК

        [$broadcast, $events] = $this->channelWithEvents(2);

        $morning = $this->makeItem($broadcast->id, $events[0]->id, TelegramChatBroadcastItem::STATUS_POSTED);
        $morning->publish_at = Carbon::parse('2026-09-15 07:00:00', 'UTC');
        $morning->posted_at = Carbon::parse('2026-09-15 07:00:30', 'UTC');
        $morning->save();
        $broadcast->last_run_at = Carbon::parse('2026-09-15 07:00:30', 'UTC');
        $broadcast->save();

        // Второй пост дня без слотов взяться неоткуда, но если он есть —
        // назначенный момент его всё равно выпускает: это осознанно, запись с
        // днём идёт по своему дню. Проверяем обратное: записи БЕЗ дня ждут окна.
        $waiting = $this->makeItem($broadcast->id, $events[1]->id, TelegramChatBroadcastItem::STATUS_PENDING);

        $this->assertCount(0, $this->service()->collectDueSingleRuns(now()), 'окно на сегодня закрыто');
        $this->assertNull($waiting->fresh()->publish_at);

        Carbon::setTestNow();
    }

    /** @return list<int> */
    private function publishHours(int $broadcastId): array
    {
        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->whereNotNull('publish_at')
            ->pluck('publish_at')
            ->map(fn ($d) => (int) Carbon::parse($d)->setTimezone('Europe/Moscow')->format('H'))
            ->all();
    }

    /** @return list<string> */
    private function publishDays(int $broadcastId): array
    {
        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->whereNotNull('publish_at')
            ->pluck('publish_at')
            ->map(fn ($d) => Carbon::parse($d)->setTimezone('Europe/Moscow')->toDateString())
            ->all();
    }

    /** @return array{0: TelegramChatBroadcast, 1: list<Event>} */
    private function channelWithEvents(int $count): array
    {
        $city = $this->insertCity();
        $owner = TelegramUser::create(['telegram_id' => 9100200]);

        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009100200;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->city_id = $city->id;
        $chat->save();

        $broadcast = TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic'],
        ]);

        $events = [];
        for ($i = 0; $i < $count; $i++) {
            $community = Community::create(['name' => 'Организатор '.$i, 'city_id' => $city->id]);
            $event = new Event;
            $event->community_id = $community->id;
            $event->title = 'Событие '.$i;
            $event->status = 'active';
            $event->city_id = $city->id;
            $event->start_time = now()->addDays(3 + $i)->setTime(19, 0);
            $event->start_date = $event->start_time->toDateString();
            $event->save();
            $events[] = $event;
        }

        return [$broadcast, $events];
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

    private function insertCity(): City
    {
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh', now(), now()]
        );

        return City::query()->where('slug', 'voronezh')->firstOrFail();
    }
}
