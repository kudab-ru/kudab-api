<?php

namespace Tests\Feature\Api;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Слоты дня в админском API.
 *
 * Контракт, на который опирается интерфейс: слоты приходят отсортированными,
 * пустой список означает «как раньше», а кап записей по умолчанию считается
 * из горизонта и числа слотов — иначе прежние семь молча придержали бы
 * половину недели из четырнадцати постов.
 */
class AdminBroadcastSlotsTest extends TestCase
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

    public function test_defaults_look_like_before(): void
    {
        $broadcast = $this->makeChannel();

        $row = collect($this->getJson('/api/admin/broadcast/channels')->json('data'))
            ->firstWhere('id', $broadcast->id);

        $this->assertSame([], $row['slots'], 'по умолчанию слотов нет');
        $this->assertSame(7, $row['horizon_days']);
        $this->assertSame(7, $row['feed_limit'], 'без слотов кап прежний');
    }

    public function test_slots_are_sorted_and_cap_follows_them(): void
    {
        $broadcast = $this->makeChannel();

        $res = $this->patchJson("/api/admin/broadcast/channels/{$broadcast->id}", [
            'slots' => [19, 10, 10],
            'horizon_days' => 7,
        ]);

        $res->assertOk();
        $this->assertSame([10, 19], $res->json('data.slots'), 'по возрастанию и без повторов');
        $this->assertSame(14, $res->json('data.feed_limit'), 'семь дней по два слота');
    }

    public function test_too_many_slots_are_refused(): void
    {
        $broadcast = $this->makeChannel();

        $this->patchJson("/api/admin/broadcast/channels/{$broadcast->id}", [
            'slots' => [8, 11, 14, 17, 20],
        ])->assertStatus(422);
    }

    public function test_hour_out_of_range_is_refused(): void
    {
        $broadcast = $this->makeChannel();

        $this->patchJson("/api/admin/broadcast/channels/{$broadcast->id}", [
            'slots' => [10, 24],
        ])->assertStatus(422);
    }

    private function makeChannel(): TelegramChatBroadcast
    {
        $owner = TelegramUser::create(['telegram_id' => 8307201745]);

        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999004;
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
}
