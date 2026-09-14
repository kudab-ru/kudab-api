<?php

namespace Tests\Feature\Api;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
