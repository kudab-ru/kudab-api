<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramChatBroadcastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Сколько у канала подписчиков.
 *
 * Все приборы канала до сих пор мерили ОТТОК: метка в ссылках считает переходы
 * из канала на сайт. Притока не мерил никто, и числа подписчиков не было нигде —
 * то есть на вопрос «канал растёт?» ответа не существовало.
 *
 * Замер делает бот: телеграм отдаёт число только тому, у кого токен. Задачу
 * ставит API вместе с остальными задачами опроса, раз в сутки.
 */
class BroadcastSubscribersTest extends TestCase
{
    use RefreshDatabase;

    /** Общий секрет бота: ручки /api/bot/* закрыты bot.auth. */
    private const BOT_TOKEN = 'test-bot-token';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-17 09:00', 'Europe/Moscow'));
        config(['services.bot.shared_token' => self::BOT_TOKEN]);
        config(['broadcast_subscribers.enabled' => true]);
    }

    /** @param  array<string, mixed>  $body */
    private function botPost(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.self::BOT_TOKEN)
            ->postJson('/api/bot/broadcast/subscribers', $body);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_poll_asks_for_a_count_once_a_day(): void
    {
        $broadcast = $this->makeChannel();

        $first = $this->tasksOf($this->service()->collectDueSingleRuns(Carbon::now(), 50));
        $this->assertContains('subscribers', $first, 'первый опрос за сутки просит замер');

        // Тот же день, следующая минута: поллер тикает раз в минуту, и без
        // отметки владелец получал бы 1440 замеров в сутки.
        Carbon::setTestNow(Carbon::now()->addMinute());
        $second = $this->tasksOf($this->service()->collectDueSingleRuns(Carbon::now(), 50));
        $this->assertNotContains('subscribers', $second, 'второй раз в тот же день не просим');

        // Назавтра — снова.
        Carbon::setTestNow(Carbon::now()->addDay());
        $third = $this->tasksOf($this->service()->collectDueSingleRuns(Carbon::now(), 50));
        $this->assertContains('subscribers', $third, 'назавтра меряем заново');

        $this->assertSame($broadcast->id, $broadcast->fresh()->id);
    }

    /** Замер приходит от бота отдельной ручкой: число знает только он. */
    public function test_bot_records_the_count(): void
    {
        $broadcast = $this->makeChannel();
        $chatId = $broadcast->chat->telegram_chat_id;

        $this->botPost(['telegram_chat_id' => $chatId, 'count' => 9])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(9, (int) DB::table('telegram.chat_subscriber_counts')
            ->where('chat_id', $broadcast->chat->id)->value('count'));
    }

    /**
     * Повтор в тот же день переписывает число, а не заводит вторую строку.
     *
     * Иначе один день давал бы несколько точек, и «прирост за неделю» считался
     * бы по числу замеров, а не по числу подписчиков.
     */
    public function test_second_measure_the_same_day_overwrites(): void
    {
        $broadcast = $this->makeChannel();
        $chatId = $broadcast->chat->telegram_chat_id;

        $this->botPost(['telegram_chat_id' => $chatId, 'count' => 9])->assertOk();
        $this->botPost(['telegram_chat_id' => $chatId, 'count' => 11])->assertOk();

        $rows = DB::table('telegram.chat_subscriber_counts')->where('chat_id', $broadcast->chat->id)->get();
        $this->assertCount(1, $rows, 'одна строка в сутки на канал');
        $this->assertSame(11, (int) $rows->first()->count);
    }

    /** Разные дни — разные точки: из них и складывается кривая. */
    public function test_days_accumulate(): void
    {
        $broadcast = $this->makeChannel();
        $chatId = $broadcast->chat->telegram_chat_id;

        $this->botPost(['telegram_chat_id' => $chatId, 'count' => 9])->assertOk();
        Carbon::setTestNow(Carbon::now()->addDay());
        $this->botPost(['telegram_chat_id' => $chatId, 'count' => 12])->assertOk();

        $this->assertSame(2, DB::table('telegram.chat_subscriber_counts')
            ->where('chat_id', $broadcast->chat->id)->count());
    }

    /** Чужой чат не заводим молча: неизвестный id — это ошибка, а не новый канал. */
    public function test_unknown_chat_is_refused(): void
    {
        $this->botPost(['telegram_chat_id' => -1009999999, 'count' => 5])
            ->assertOk()->assertJson(['ok' => false]);

        $this->assertSame(0, DB::table('telegram.chat_subscriber_counts')->count());
    }

    private function service(): TelegramChatBroadcastService
    {
        return app(TelegramChatBroadcastService::class);
    }

    /** @return list<string> */
    private function tasksOf(array $items): array
    {
        return array_map(static fn ($t) => (string) ($t['type'] ?? 'publish'), $items);
    }

    private function makeChannel(): TelegramChatBroadcast
    {
        $owner = TelegramUser::create(['telegram_id' => 8307203011]);
        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009911022;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->save();

        return TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic'],
        ])->load('chat');
    }
}
