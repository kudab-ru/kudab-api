<?php

namespace Tests\Feature\Api;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Привязка телеграм-канала из админки.
 *
 * До этой ручки канал попадал в базу единственным путём — событием
 * my_chat_member, то есть в момент, когда бота добавляют в чат. Событие
 * приходит один раз, поэтому канал, где бот админ давно, подключить было
 * нельзя ничем, кроме правки базы руками.
 *
 * Живьём успешный путь не проверить: на стенде бот не администратор ни в
 * одном канале. Поэтому ответ бота подставляем, а проверяем то, что делает
 * сам API — отказы и запись в базу.
 */
class AdminBroadcastLinkTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/broadcast/channels/link';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bot.url' => 'http://kudab-bot:8000', 'services.bot.shared_token' => 'secret']);

        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        Sanctum::actingAs($user);
    }

    /** Ответ бота на «нашёл, бот админ, публиковать может». */
    private function fakeBot(array $override = []): void
    {
        $body = array_replace_recursive([
            'found' => true,
            'postable_type' => true,
            'chat' => [
                'id' => -1009999,
                'type' => 'channel',
                'title' => 'Тестовый канал',
                'username' => 'kudab_test',
            ],
            'bot' => [
                'id' => 42,
                'username' => 'dev_bot',
                'status' => 'administrator',
                'is_admin' => true,
                'can_post' => true,
            ],
        ], $override);

        Http::fake(['*/internal/check-chat' => Http::response($body, 200)]);
    }

    public function test_links_channel_and_creates_broadcast_row(): void
    {
        $this->fakeBot();

        $res = $this->postJson(self::URL, ['chat' => '@kudab_test']);

        $res->assertOk();
        $res->assertJsonPath('meta.existed', false);

        $chat = TelegramChat::query()->where('telegram_chat_id', -1009999)->first();
        $this->assertNotNull($chat, 'чат не записан в telegram.chats');
        $this->assertSame('kudab_test', $chat->username);
        $this->assertSame('Тестовый канал', $chat->title);
        $this->assertTrue((bool) $chat->is_active);

        $broadcast = TelegramChatBroadcast::query()->where('chat_id', $chat->id)->first();
        $this->assertNotNull($broadcast, 'строка рассылки не заведена');
        // Автопостинг выключен: канал только что привязали, расписание ещё не
        // выбрано — сам он публиковать не должен.
        $this->assertFalse((bool) $broadcast->enabled);
    }

    public function test_relink_keeps_existing_owner_and_does_not_duplicate(): void
    {
        $existing = new TelegramChat;
        $existing->telegram_chat_id = -1009999;
        $existing->chat_type = 'channel';
        $existing->title = 'Старое название';
        $existing->is_active = false;
        $existing->save();

        $this->fakeBot();

        $res = $this->postJson(self::URL, ['chat' => '-1009999']);

        $res->assertOk();
        $res->assertJsonPath('meta.existed', true);

        $this->assertSame(1, TelegramChat::query()->where('telegram_chat_id', -1009999)->count());

        $existing->refresh();
        $this->assertSame('Тестовый канал', $existing->title, 'название должно обновиться из Telegram');
        $this->assertTrue((bool) $existing->is_active, 'повторная привязка должна оживить запись');
    }

    public function test_rejects_chat_where_bot_is_not_admin(): void
    {
        // Ровно этот случай делает проверку осмысленной: get_chat на стороне
        // бота отвечает успехом и для чужого публичного канала.
        $this->fakeBot(['bot' => ['is_admin' => false, 'status' => null, 'can_post' => null]]);

        $res = $this->postJson(self::URL, ['chat' => '@durov']);

        $res->assertStatus(422);
        $this->assertStringContainsString('не администратор', (string) $res->json('error'));
        $this->assertSame(0, TelegramChat::query()->count());
    }

    public function test_rejects_admin_without_posting_right(): void
    {
        $this->fakeBot(['bot' => ['can_post' => false]]);

        $res = $this->postJson(self::URL, ['chat' => '@kudab_test']);

        $res->assertStatus(422);
        $this->assertStringContainsString('публиковать', (string) $res->json('error'));
        $this->assertSame(0, TelegramChat::query()->count());
    }

    public function test_rejects_unknown_chat(): void
    {
        Http::fake(['*/internal/check-chat' => Http::response([
            'found' => false,
            'reason' => 'not_found',
            'message' => 'Telegram не знает такого чата.',
        ], 200)]);

        $res = $this->postJson(self::URL, ['chat' => '@nope']);

        $res->assertStatus(422);
        $this->assertSame(0, TelegramChat::query()->count());
    }

    public function test_rejects_private_chat(): void
    {
        $this->fakeBot(['postable_type' => false, 'chat' => ['type' => 'private']]);

        $res = $this->postJson(self::URL, ['chat' => '12345']);

        $res->assertStatus(422);
        $this->assertSame(0, TelegramChat::query()->count());
    }

    public function test_reports_unreachable_bot_instead_of_failing_silently(): void
    {
        Http::fake(['*/internal/check-chat' => Http::response('', 500)]);

        $res = $this->postJson(self::URL, ['chat' => '@kudab_test']);

        $res->assertStatus(502);
        $this->assertSame(0, TelegramChat::query()->count());
    }

    public function test_passes_bot_identifier_verbatim(): void
    {
        $this->fakeBot();

        $this->postJson(self::URL, ['chat' => '  https://t.me/kudab_test '])->assertOk();

        // Нормализацию делает бот — он единственный, кто разговаривает с
        // Telegram. API своей не заводит, иначе два разбора разъедутся.
        // Единственное изменение по дороге — обрезка пробелов глобальной
        // middleware TrimStrings; бот делает strip() и сам, так что они
        // согласны.
        Http::assertSent(function ($request) {
            return $request['chat'] === 'https://t.me/kudab_test';
        });
    }
}
