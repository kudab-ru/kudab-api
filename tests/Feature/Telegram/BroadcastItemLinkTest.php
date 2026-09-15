<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Observers\TelegramChatBroadcastItemObserver as Links;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Связь «пост → события» заполняется сама и не рассыпается.
 *
 * На неё переезжают все слои анти-дублей, и её главная опасность — тихая: если
 * строка связи не появилась, ошибки не будет, просто событие останется
 * кандидатом и выйдет вторым постом через несколько дней. Поэтому писатель
 * один (обсервер на сохранении записи), а этот тест сторожит, что он жив.
 */
class BroadcastItemLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_an_item_creates_its_link(): void
    {
        $item = $this->makeItem($this->event()->id);

        $this->assertSame(
            [0 => (int) $item->event_id],
            $this->links($item->id),
            'у записи с событием сразу есть ведущая строка связи',
        );
    }

    /** У портрета площадки события нет — и связи быть не должно. */
    public function test_item_without_event_has_no_links(): void
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $this->broadcast()->id;
        $item->kind = TelegramChatBroadcastItem::KIND_VENUE;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->save();

        $this->assertSame([], $this->links($item->id));
    }

    /** Смена ведущего события заменяет строку, а не добавляет вторую. */
    public function test_changing_the_event_replaces_the_leading_link(): void
    {
        $item = $this->makeItem($this->event()->id);
        $another = $this->event();

        $item->event_id = $another->id;
        $item->save();

        $this->assertSame([0 => $another->id], $this->links($item->id));
    }

    /** Снятие и возврат — это про статус, связь они не трогают. */
    public function test_status_changes_keep_the_link(): void
    {
        $item = $this->makeItem($this->event()->id);
        $eventId = (int) $item->event_id;

        $item->status = TelegramChatBroadcastItem::STATUS_SKIPPED;
        $item->save();
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->save();

        $this->assertSame([0 => $eventId], $this->links($item->id));
    }

    /**
     * Запись, вставленная мимо модели, остаётся без связи — и это видно
     * прибором. Молчаливая недостача и есть главный риск работы.
     */
    public function test_a_row_written_around_the_model_is_reported_as_missing(): void
    {
        $event = $this->event();
        DB::table('telegram.chat_broadcast_items')->insert([
            'broadcast_id' => $this->broadcast()->id,
            'kind' => TelegramChatBroadcastItem::KIND_EVENT,
            'event_id' => $event->id,
            'status' => TelegramChatBroadcastItem::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->artisan('broadcast:links:backfill', ['--check' => true])->run());

        $this->artisan('broadcast:links:backfill')->assertSuccessful();

        $this->assertSame(0, $this->artisan('broadcast:links:backfill', ['--check' => true])->run());
    }

    /** @return array<int, int> позиция => событие */
    private function links(int $itemId): array
    {
        return DB::table(Links::TABLE)
            ->where('item_id', $itemId)
            ->orderBy('position')
            ->pluck('event_id', 'position')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private function makeItem(int $eventId): TelegramChatBroadcastItem
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $this->broadcast()->id;
        $item->kind = TelegramChatBroadcastItem::KIND_EVENT;
        $item->event_id = $eventId;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->save();

        return $item;
    }

    /** Не static: RefreshDatabase чистит базу между тестами, а static пережил бы. */
    private ?TelegramChatBroadcast $broadcast = null;

    private function broadcast(): TelegramChatBroadcast
    {
        if ($this->broadcast) {
            return $this->broadcast;
        }

        $owner = TelegramUser::create(['telegram_id' => 8307201801]);
        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999055;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->save();

        return $this->broadcast = TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic'],
        ]);
    }

    private function event(): \App\Models\Event
    {
        $city = \App\Models\City::query()->where('slug', 'voronezh-link')->first();
        if (! $city) {
            DB::insert(
                'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
                 VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
                ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh-link', now(), now()]
            );
            $city = \App\Models\City::query()->where('slug', 'voronezh-link')->firstOrFail();
        }

        $community = \App\Models\Community::create([
            'name' => 'Организатор '.uniqid(),
            'city_id' => $city->id,
        ]);

        $event = new \App\Models\Event;
        $event->community_id = $community->id;
        $event->title = 'Событие '.uniqid();
        $event->status = 'active';
        $event->city_id = $city->id;
        $event->start_time = now()->addDays(2);
        $event->start_date = now()->addDays(2)->toDateString();
        $event->end_time = now()->addDays(2)->addHours(2);
        $event->save();

        return $event;
    }
}
