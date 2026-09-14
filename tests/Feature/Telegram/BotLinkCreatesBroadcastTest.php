<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Канал, куда добавили бота, появляется в разделе рассылки.
 *
 * Обнаружение каналов работало и раньше: событие my_chat_member приходит боту
 * в момент, когда его делают администратором, и бот записывает чат вместе с
 * владельцем. Но строки рассылки на этом пути не создавалось, а список в
 * админке строится по chat_broadcasts — канала там не было. Человек делал всё
 * правильно, и ничего не происходило.
 */
class BotLinkCreatesBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/bot/telegram-chats/link';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bot.shared_token' => 'secret']);
    }

    public function test_channel_link_creates_disabled_broadcast_row(): void
    {
        $this->boundTelegramUser(900100);

        $this->linkChat(900100, -1009000100, 'channel')->assertOk();

        $chat = TelegramChat::query()->where('telegram_chat_id', -1009000100)->firstOrFail();
        $broadcast = TelegramChatBroadcast::query()->where('chat_id', $chat->id)->first();

        $this->assertNotNull($broadcast, 'канал должен появиться в списке рассылки');
        // Появление канала само по себе ничего не публикует.
        $this->assertFalse((bool) $broadcast->enabled);
        $this->assertSame('off', $broadcast->period);
    }

    public function test_group_link_does_not_create_broadcast_row(): void
    {
        $this->boundTelegramUser(900200);

        $this->linkChat(900200, -1009000200, 'group')->assertOk();

        $chat = TelegramChat::query()->where('telegram_chat_id', -1009000200)->firstOrFail();

        $this->assertNull(
            TelegramChatBroadcast::query()->where('chat_id', $chat->id)->first(),
            'группа в разделе «Рассылка» — мусор',
        );
    }

    public function test_repeated_link_does_not_duplicate_row(): void
    {
        $this->boundTelegramUser(900300);

        $this->linkChat(900300, -1009000300, 'channel')->assertOk();
        $this->linkChat(900300, -1009000300, 'channel')->assertOk();

        $chat = TelegramChat::query()->where('telegram_chat_id', -1009000300)->firstOrFail();

        $this->assertSame(1, TelegramChatBroadcast::query()->where('chat_id', $chat->id)->count());
    }

    private function linkChat(int $telegramId, int $chatId, string $type)
    {
        return $this->withHeaders(['Authorization' => 'Bearer secret'])
            ->postJson(self::URL, [
                'telegram_id' => $telegramId,
                'chat_id' => $chatId,
                'chat_type' => $type,
                'title' => 'Канал',
                'username' => 'chan'.$chatId,
            ]);
    }

    private function boundTelegramUser(int $telegramId): TelegramUser
    {
        $webUser = User::create([
            'name' => 'U'.$telegramId,
            'email' => 'u'.$telegramId.'@example.test',
            'password' => bcrypt('secret-'.$telegramId),
        ]);

        $tu = new TelegramUser;
        $tu->telegram_id = $telegramId;
        $tu->user()->associate($webUser);
        $tu->save();

        return $tu;
    }
}
