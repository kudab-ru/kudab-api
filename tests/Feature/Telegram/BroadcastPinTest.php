<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramChatBroadcastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BroadcastPinTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = 'test-bot-token';

    private ?TelegramChatBroadcast $channel = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bot.shared_token' => self::BOT_TOKEN, 'broadcast_subscribers.enabled' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function a_plan_digest_is_pinned_in_place_of_the_previous_one(): void
    {
        $week = $this->publish('na-nedele', '2026-11-09 12:00', messageId: 100);
        $this->assertTrue($week['pin'] ?? false);
        $this->assertArrayNotHasKey('unpin_message_id', $week);

        $free = $this->publish('besplatno', '2026-11-10 19:00', messageId: 101);
        $this->assertArrayNotHasKey('pin', $free);

        $weekend = $this->publish('na-vyhodnyh', '2026-11-12 19:00', messageId: 102);
        $this->assertTrue($weekend['pin'] ?? false);
        $this->assertSame(100, $weekend['unpin_message_id'] ?? null);
    }

    #[Test]
    public function a_holiday_digest_keeps_the_pin_until_the_holiday_is_over(): void
    {
        $holiday = $this->publish('povod-halloween', '2026-10-26 12:00', messageId: 200);
        $this->assertTrue($holiday['pin'] ?? false);

        $weekend = $this->publish('na-vyhodnyh', '2026-10-29 19:00', messageId: 201);
        $this->assertArrayNotHasKey('pin', $weekend);

        $week = $this->publish('na-nedele', '2026-11-02 12:00', messageId: 202);
        $this->assertTrue($week['pin'] ?? false);
        $this->assertSame(200, $week['unpin_message_id'] ?? null);
    }

    /**
     * Подборка уходит боту, бот её отправляет и, если задача просила, закрепляет.
     *
     * @return array<string, mixed> задача боту
     */
    private function publish(string $theme, string $at, int $messageId): array
    {
        Carbon::setTestNow(Carbon::parse($at, 'Europe/Moscow'));

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $this->channel()->id;
        $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $item->status = TelegramChatBroadcastItem::STATUS_APPROVED;
        $item->is_off_grid = true;
        $item->publish_at = Carbon::now();
        $item->caption = 'Подборка '.$theme;
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_MANUAL;
        $item->photo_urls = [];
        $item->digest_meta = ['theme' => $theme];
        $item->save();

        $task = collect(app(TelegramChatBroadcastService::class)->collectDueSingleRuns(Carbon::now(), 10))
            ->first(fn ($t) => ($t['type'] ?? null) === 'publish' && (int) ($t['item_id'] ?? 0) === (int) $item->id);
        $this->assertNotNull($task, "подборка {$theme} не ушла боту");

        $this->withHeader('Authorization', 'Bearer '.self::BOT_TOKEN)
            ->postJson('/api/bot/broadcast/single/mark-item-sent', [
                'item_id' => $item->id,
                'claim_token' => $task['claim_token'],
                'message_id' => $messageId,
                'pinned' => (bool) ($task['pin'] ?? false),
            ])
            ->assertOk()->assertJson(['ok' => true]);

        return $task;
    }

    private function channel(): TelegramChatBroadcast
    {
        if ($this->channel !== null) {
            return $this->channel;
        }

        $user = TelegramUser::create(['telegram_id' => random_int(10_000, 99_999), 'first_name' => 'Владелец']);

        $chat = new TelegramChat;
        $chat->telegram_user_id = $user->id;
        $chat->telegram_chat_id = -random_int(1_000_000, 9_999_999);
        $chat->chat_type = 'channel';
        $chat->title = 'Канал';
        $chat->is_active = true;
        $chat->save();

        $b = new TelegramChatBroadcast;
        $b->chat_id = $chat->id;
        $b->enabled = true;
        $b->settings = ['ai_text' => false, 'slots' => [12, 19], 'feed_limit' => 20];
        $b->period = 'daily_19';
        $b->save();

        return $this->channel = $b->fresh(['chat']);
    }
}
