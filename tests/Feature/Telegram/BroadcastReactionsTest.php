<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Реакции на пост — второй прибор отклика канала.
 *
 * Первый (переходы по метке utm_content) меряет исход: человек ушёл на сайт.
 * За 90 дней таких набралось 2 на 7 постов, и по ним нельзя отличить «пост не
 * понравился» от «понравился, но идти никуда не захотелось». Просмотры Bot API
 * не отдаёт вовсе.
 *
 * Телеграм присылает ПОЛНЫЙ текущий состав реакций сообщения, а не разницу, —
 * отсюда все правила ниже.
 */
class BroadcastReactionsTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = 'test-bot-token';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-19 10:00', 'Europe/Moscow'));
        config(['services.bot.shared_token' => self::BOT_TOKEN]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_bot_records_reactions_of_a_post(): void
    {
        [$broadcast, $item] = $this->channelWithPost(messageId: 42);

        $this->botPost([
            'telegram_chat_id' => $broadcast->chat->telegram_chat_id,
            'message_id' => 42,
            'reactions' => [
                ['emoji' => '👍', 'count' => 1],
                ['emoji' => '🔥', 'count' => 4],
            ],
        ])->assertOk()->assertJson(['ok' => true]);

        $fresh = $item->fresh();
        $this->assertSame(5, (int) $fresh->reactions);
        // assertEquals, а не assertSame: jsonb в постгресе не хранит порядок
        // ключей объекта и отдаёт «count» раньше «emoji». Порядок САМОГО
        // списка при этом сохраняется — его и проверяем.
        $this->assertEquals(
            [['emoji' => '🔥', 'count' => 4], ['emoji' => '👍', 'count' => 1]],
            $fresh->reactions_meta,
            'разбивка по убыванию: первым то, чего больше',
        );
        $this->assertNotNull($fresh->reactions_at);
    }

    /**
     * Обновление ЗАМЕЩАЕТ прошлое, а не складывается с ним.
     *
     * Телеграм шлёт текущее состояние сообщения целиком. При сложении снятая
     * реакция осталась бы в сумме навсегда, и число только росло бы.
     */
    public function test_a_later_update_replaces_the_previous_one(): void
    {
        [$broadcast, $item] = $this->channelWithPost(messageId: 42);
        $chatId = $broadcast->chat->telegram_chat_id;

        $this->botPost([
            'telegram_chat_id' => $chatId,
            'message_id' => 42,
            'reactions' => [['emoji' => '🔥', 'count' => 4]],
        ])->assertOk();

        $this->botPost([
            'telegram_chat_id' => $chatId,
            'message_id' => 42,
            'reactions' => [['emoji' => '🔥', 'count' => 2]],
        ])->assertOk();

        $this->assertSame(2, (int) $item->fresh()->reactions, 'снятая реакция уходит из суммы');
    }

    /** Все реакции сняли — это ноль, а не «не мерили». */
    public function test_empty_list_means_zero_not_unmeasured(): void
    {
        [$broadcast, $item] = $this->channelWithPost(messageId: 42);

        $this->botPost([
            'telegram_chat_id' => $broadcast->chat->telegram_chat_id,
            'message_id' => 42,
            'reactions' => [],
        ])->assertOk()->assertJson(['ok' => true]);

        $fresh = $item->fresh();
        $this->assertSame(0, (int) $fresh->reactions);
        $this->assertNull($fresh->reactions_meta);
        $this->assertNotNull($fresh->reactions_at, 'мерили — значит момент замера есть');
    }

    /**
     * Реакция на чужой пост канала записи не находит — и это не ошибка.
     *
     * В канале есть сообщения, которые писал не бот; для них никакой записи
     * очереди не существует.
     */
    public function test_unknown_message_is_answered_honestly(): void
    {
        [$broadcast] = $this->channelWithPost(messageId: 42);

        $this->botPost([
            'telegram_chat_id' => $broadcast->chat->telegram_chat_id,
            'message_id' => 777,
            'reactions' => [['emoji' => '🔥', 'count' => 1]],
        ])->assertOk()->assertJson(['ok' => false]);
    }

    /**
     * Номер сообщения уникален только внутри чата.
     *
     * Без ограничения по чату реакция в одном канале легла бы посту другого:
     * номера сообщений у разных каналов совпадают постоянно.
     */
    public function test_message_number_does_not_leak_between_channels(): void
    {
        [, $mine] = $this->channelWithPost(messageId: 42);
        [$other, $theirs] = $this->channelWithPost(messageId: 42, suffix: 2);

        $this->botPost([
            'telegram_chat_id' => $other->chat->telegram_chat_id,
            'message_id' => 42,
            'reactions' => [['emoji' => '🔥', 'count' => 3]],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(3, (int) $theirs->fresh()->reactions);
        $this->assertNull($mine->fresh()->reactions, 'чужой канал не тронут');
    }

    /** Ручка закрыта общим секретом бота, как и остальные /api/bot/*. */
    public function test_route_is_closed_without_the_bot_token(): void
    {
        [$broadcast] = $this->channelWithPost(messageId: 42);

        $this->postJson('/api/bot/broadcast/reactions', [
            'telegram_chat_id' => $broadcast->chat->telegram_chat_id,
            'message_id' => 42,
            'reactions' => [],
        ])->assertStatus(401);
    }

    /**
     * Выключенные в канале реакции становятся видимой причиной пустоты.
     *
     * Признак приносит бот заодно с суточным замером подписчиков: отдельной
     * задачи ради одного булева значения заводить незачем, а без причины
     * пустой столбец в ленте читается как «реакций не было».
     */
    public function test_channel_without_reactions_says_so(): void
    {
        [$broadcast] = $this->channelWithPost(messageId: 42);

        $this->withHeader('Authorization', 'Bearer '.self::BOT_TOKEN)
            ->postJson('/api/bot/broadcast/subscribers', [
                'telegram_chat_id' => $broadcast->chat->telegram_chat_id,
                'count' => 9,
                'reactions_enabled' => false,
            ])->assertOk()->assertJson(['ok' => true]);

        $this->assertFalse($broadcast->fresh()->settings['reactions_enabled']);
    }

    /** Поля нет — прежнее значение не трогаем: «спросить не удалось» ≠ «выключены». */
    public function test_a_missing_flag_keeps_the_previous_answer(): void
    {
        [$broadcast] = $this->channelWithPost(messageId: 42);

        $send = fn (array $extra) => $this->withHeader('Authorization', 'Bearer '.self::BOT_TOKEN)
            ->postJson('/api/bot/broadcast/subscribers', array_merge([
                'telegram_chat_id' => $broadcast->chat->telegram_chat_id,
                'count' => 9,
            ], $extra));

        $send(['reactions_enabled' => true])->assertOk();
        $send([])->assertOk();

        $this->assertTrue($broadcast->fresh()->settings['reactions_enabled']);
    }

    // ------------------------------------------------------------------

    /** @param  array<string, mixed>  $body */
    private function botPost(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.self::BOT_TOKEN)
            ->postJson('/api/bot/broadcast/reactions', $body);
    }

    /** @return array{0: TelegramChatBroadcast, 1: TelegramChatBroadcastItem} */
    private function channelWithPost(int $messageId, int $suffix = 1): array
    {
        $owner = TelegramUser::create(['telegram_id' => 8300000000 + $suffix]);

        $chat = new TelegramChat;
        $chat->telegram_chat_id = -100999900 - $suffix;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->save();

        $broadcast = TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic'],
        ]);

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->status = TelegramChatBroadcastItem::STATUS_POSTED;
        $item->posted_at = Carbon::now()->subHour();
        $item->message_id = $messageId;
        $item->save();

        return [$broadcast->fresh('chat'), $item];
    }
}
