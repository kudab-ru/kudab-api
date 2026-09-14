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
 * Канал без владельца не публикует ничего — и раньше молчал об этом.
 *
 * Поллер пропускает такой канал целиком (skipped_no_owner), ЛС-сигнал о
 * простое писать некому, а в админке признака не было вовсе: включённый
 * канал выглядел рабочим и не отдавал ни одной ошибки. Привязка из админки
 * владельца не пишет намеренно — за веб-админом нет телеграм-пользователя,
 * — поэтому именно ручная форма и создаёт такие каналы.
 */
class AdminBroadcastOwnerTest extends TestCase
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

    public function test_channel_without_owner_cannot_be_enabled(): void
    {
        $broadcast = $this->makeChannel(ownerTelegramId: null, enabled: false);

        $res = $this->patchJson("/api/admin/broadcast/channels/{$broadcast->id}", ['enabled' => true]);

        $res->assertStatus(422);
        $this->assertStringContainsString('нет владельца', (string) $res->json('error'));
        $this->assertFalse((bool) $broadcast->fresh()->enabled, 'канал не должен включиться');
    }

    public function test_channel_without_owner_is_flagged_in_payload(): void
    {
        $broadcast = $this->makeChannel(ownerTelegramId: null, enabled: true);

        $res = $this->getJson('/api/admin/broadcast/channels');

        $res->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $broadcast->id);

        $this->assertNotNull($row);
        $this->assertFalse($row['has_owner']);

        $texts = collect($row['problems'])->where('level', 'danger')->pluck('text')->implode(' ');
        $this->assertStringContainsString('нет владельца', $texts);
    }

    public function test_channel_with_owner_can_be_enabled(): void
    {
        $broadcast = $this->makeChannel(ownerTelegramId: 8307201745, enabled: false);

        $res = $this->patchJson("/api/admin/broadcast/channels/{$broadcast->id}", ['enabled' => true]);

        $res->assertOk();
        $this->assertTrue($res->json('data.has_owner'));
        $this->assertTrue((bool) $broadcast->fresh()->enabled);
    }

    private function makeChannel(?int $ownerTelegramId, bool $enabled): TelegramChatBroadcast
    {
        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999001;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        if ($ownerTelegramId !== null) {
            $owner = TelegramUser::create(['telegram_id' => $ownerTelegramId]);
            $chat->telegram_user_id = $owner->id; // не в fillable — ставим напрямую
        }
        $chat->save();

        return TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => $enabled,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic'],
        ]);
    }
}
