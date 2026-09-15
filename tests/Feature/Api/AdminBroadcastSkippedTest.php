<?php

namespace Tests\Feature\Api;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Снятый пост обязан оставить след, а «Повторить» — повторять.
 *
 * Автоматическое снятие (просрочка, прошедшее событие) убирает запись из
 * недельной сетки. Пока лента отдавала только открытые статусы, такой пост
 * исчезал бесследно — ровно то поведение, из-за которого канал однажды
 * простоял 33 дня, только теперь с обратным знаком.
 */
class AdminBroadcastSkippedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        Sanctum::actingAs($user);
    }

    public function test_skipped_item_is_visible_in_feed_with_reason(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::STATUS_SKIPPED);
        $item->error_message = 'день публикации прошёл больше 2 ч назад';
        $item->event_id = $this->futureEvent()->id;
        $item->save();

        $res = $this->getJson("/api/admin/broadcast/channels/{$broadcast->id}/feed");

        $res->assertOk();
        $row = collect($res->json('data.items'))->firstWhere('id', $item->id);

        $this->assertNotNull($row, 'снятая запись должна быть видна');
        $this->assertSame('skipped', $row['status']);
        $this->assertStringContainsString('прошёл больше', (string) $row['error_message']);
    }

    public function test_retry_moves_publish_at_to_now(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::STATUS_ERROR);
        $item->error_message = 'бот не админ';
        $item->publish_at = now()->subDays(2); // ошиблась она в свой день, то есть давно
        $item->save();

        $this->postJson("/api/admin/broadcast/items/{$item->id}/retry")->assertOk();

        $fresh = $item->fresh();
        $this->assertSame(TelegramChatBroadcastItem::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->error_message);
        // Без переноса дня отсечка просрочки сняла бы пост первым же тиком —
        // кнопка повтора удаляла бы пост вместо повторной отправки.
        $this->assertTrue(
            $fresh->publish_at->greaterThan(now()->subMinutes(5)),
            'день публикации перенесён на сейчас',
        );
    }

    public function test_patch_cannot_put_two_posts_on_one_day(): void
    {
        $broadcast = $this->makeChannel();
        $day = now()->addDays(2)->setTime(10, 0);

        $occupant = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $occupant->publish_at = $day;
        $occupant->save();

        $moving = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::STATUS_PENDING);

        $this->patchJson("/api/admin/broadcast/items/{$moving->id}", [
            'publish_at' => $day->toIso8601String(),
        ])->assertOk();

        // Занявший день уступает место и возвращается в общую очередь — как
        // при постановке. Два поста на одном дне не появляются.
        $this->assertNull($occupant->fresh()->publish_at);
        $this->assertNotNull($moving->fresh()->publish_at);
    }

    public function test_patch_refuses_day_when_pinned_post_holds_it(): void
    {
        $broadcast = $this->makeChannel();
        $day = now()->addDays(2)->setTime(10, 0);

        $pinned = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $pinned->publish_at = $day;
        $pinned->is_pinned = true;
        $pinned->save();

        $moving = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::STATUS_PENDING);

        $res = $this->patchJson("/api/admin/broadcast/items/{$moving->id}", [
            'publish_at' => $day->toIso8601String(),
        ]);

        $res->assertStatus(409);
        $this->assertNotNull($pinned->fresh()->publish_at, 'закреплённый остался на своём дне');
        $this->assertNull($moving->fresh()->publish_at);
    }

    public function test_rebuild_is_rolled_back_when_nothing_to_fill(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $item->publish_at = now()->addDay()->setTime(10, 0);
        $item->save();

        // У канала нет города — заполнить нечем. Раньше пересборка всё равно
        // снимала ленту, и кнопка работала как «Снять всё».
        $res = $this->postJson("/api/admin/broadcast/channels/{$broadcast->id}/rebuild");

        $res->assertStatus(409);
        $this->assertSame(
            TelegramChatBroadcastItem::STATUS_PENDING,
            $item->fresh()->status,
            'лента осталась на месте',
        );
    }

    public function test_skipped_item_can_be_restored(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::STATUS_SKIPPED);
        $item->error_message = 'снято при пересборке ленты';
        $item->save();

        $this->postJson("/api/admin/broadcast/items/{$item->id}/restore")->assertOk();

        $fresh = $item->fresh();
        $this->assertSame(TelegramChatBroadcastItem::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->error_message);
        $this->assertNotNull($fresh->publish_at, 'вернулся на свободный слот');
    }

    /** Свободных слотов нет — возврат всё равно проходит, запись ждёт дня. */
    public function test_restore_without_free_slot_waits_for_a_day(): void
    {
        $broadcast = $this->makeChannel();
        $broadcast->horizon_days = 1;
        $broadcast->save();

        // Единственный слот горизонта занят.
        $busy = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $busy->publish_at = now()->addDay()->setTime(10, 0);
        $busy->event_id = $this->futureEvent()->id;
        $busy->save();

        $item = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::STATUS_SKIPPED);
        $item->error_message = 'снято из ленты';
        $item->event_id = $this->futureEvent()->id;
        $item->save();

        $this->postJson("/api/admin/broadcast/items/{$item->id}/restore")->assertOk();

        $fresh = $item->fresh();
        $this->assertSame(TelegramChatBroadcastItem::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->publish_at, 'без дня — в «Ждут свободного дня»');
    }

    public function test_stale_skips_are_hidden_and_reason_is_typed(): void
    {
        $broadcast = $this->makeChannel();

        $manual = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::STATUS_SKIPPED);
        $manual->error_message = 'снято из ленты';
        $manual->event_id = $this->futureEvent()->id;
        $manual->save();

        // Снято РУКАМИ, но событие с тех пор прошло: вернуть такое нельзя, и
        // в списке ему не место — иначе кнопка возврата отвечает «не вышло».
        $stale = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::STATUS_SKIPPED);
        $stale->error_message = 'снято из ленты';
        $stale->event_id = $this->pastEvent()->id;
        $stale->save();

        $res = $this->getJson("/api/admin/broadcast/channels/{$broadcast->id}/feed");
        $res->assertOk();
        $rows = collect($res->json('data.items'));

        $this->assertNotNull($rows->firstWhere('id', $manual->id));
        $this->assertSame('manual', $rows->firstWhere('id', $manual->id)['skip_reason']);
        $this->assertNull(
            $rows->firstWhere('id', $stale->id),
            'прошедшее событие в списке снятых не нужно: вернуть его нельзя',
        );
    }

    public function test_restore_many_returns_what_failed(): void
    {
        $broadcast = $this->makeChannel();

        $ok = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::STATUS_SKIPPED);
        $ok->error_message = 'снято из ленты';
        $ok->event_id = $this->futureEvent()->id;
        $ok->save();

        // Событие прошло — вернуть такое нельзя, и массовый возврат обязан
        // отказать ровно так же, как поштучный.
        $gone = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::STATUS_SKIPPED);
        $gone->event_id = $this->pastEvent()->id;
        $gone->save();

        $res = $this->postJson("/api/admin/broadcast/channels/{$broadcast->id}/restore-many", [
            'ids' => [$ok->id, $gone->id],
        ]);

        $res->assertOk();
        $this->assertSame(1, $res->json('data.restored'));
        $this->assertCount(1, $res->json('data.failed'));
        $this->assertSame(TelegramChatBroadcastItem::STATUS_PENDING, $ok->fresh()->status);
        $this->assertSame(TelegramChatBroadcastItem::STATUS_SKIPPED, $gone->fresh()->status);
    }

    /** Живое событие: снятая запись под ним возвращается, поэтому видна. */
    private function futureEvent(): \App\Models\Event
    {
        return $this->makeEvent(now()->addDays(2), now()->addDays(3));
    }

    /** Прошедшее: вернуть такое нельзя, и в списке снятых ему не место. */
    public function test_suggestion_can_be_published_out_of_turn(): void
    {
        $broadcast = $this->makeChannel();
        $event = $this->futureEvent();

        // Неделя занята целиком — вне очереди это не мешает: пост не встаёт
        // в сетку, его момент «сейчас».
        $busy = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $busy->publish_at = now()->addDay()->setTime(10, 0);
        $busy->event_id = $this->futureEvent()->id;
        $busy->save();

        $res = $this->postJson("/api/admin/broadcast/channels/{$broadcast->id}/publish-suggestion", [
            'event_id' => $event->id,
        ]);

        $res->assertOk();
        $item = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('event_id', $event->id)
            ->firstOrFail();

        $this->assertSame(TelegramChatBroadcastItem::STATUS_PENDING, $item->status);
        $this->assertNotNull($item->publish_at);
        $this->assertTrue($item->publish_at->lessThanOrEqualTo(now()), 'момент — сейчас, а не будущий слот');
        // Занявший завтрашний день не тронут: вне очереди никого не вытесняет.
        $this->assertNotNull($busy->fresh()->publish_at);
    }

    public function test_past_event_cannot_be_published_out_of_turn(): void
    {
        $broadcast = $this->makeChannel();

        $this->postJson("/api/admin/broadcast/channels/{$broadcast->id}/publish-suggestion", [
            'event_id' => $this->pastEvent()->id,
        ])->assertStatus(422);
    }

    private function pastEvent(): \App\Models\Event
    {
        return $this->makeEvent(now()->subDays(2), now()->subDay());
    }

    private function makeEvent(\Carbon\Carbon $start, \Carbon\Carbon $end): \App\Models\Event
    {
        // Город один на весь тест: имя города уникально в пределах страны,
        // второй «Воронеж» упирается в cities_country_name_ci_uniq.
        $city = $this->city();
        $community = \App\Models\Community::create([
            'name' => 'Организатор '.uniqid(),
            'city_id' => $city->id,
        ]);

        $event = new \App\Models\Event;
        $event->community_id = $community->id;
        $event->title = 'Событие '.uniqid();
        $event->status = 'active';
        $event->city_id = $city->id;
        $event->start_time = $start;
        $event->start_date = $start->toDateString();
        $event->end_time = $end;
        $event->save();

        return $event;
    }

    private function city(): \App\Models\City
    {
        $existing = \App\Models\City::query()->where('slug', 'voronezh-test')->first();
        if ($existing) {
            return $existing;
        }

        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh-test', now(), now()]
        );

        return \App\Models\City::query()->where('slug', 'voronezh-test')->firstOrFail();
    }

    private function makeChannel(): TelegramChatBroadcast
    {
        $owner = TelegramUser::create(['telegram_id' => 8307201745]);

        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999002;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->save();

        return TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic'],
        ]);
    }

    private function makeItem(int $broadcastId, string $status): TelegramChatBroadcastItem
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcastId;
        $item->status = $status;
        $item->caption = 'текст';
        $item->save();

        return $item;
    }
}
