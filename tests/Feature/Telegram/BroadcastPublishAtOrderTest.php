<?php

namespace Tests\Feature\Telegram;

use App\Contracts\Telegram\TelegramChatBroadcastItemRepositoryInterface;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Порядок отправки уважает назначенный день.
 *
 * До этого publish_at не читал НИКТО: поллер брал самый старый открытый пост
 * по created_at. Из-за этого недельная сетка, перетаскивание и «отправить
 * сейчас» на эфир не влияли вообще, а вытесненный пост уходил в канал первым
 * — он ведь старше того, кто занял его день. Метод, умеющий читать publish_at,
 * в репозитории лежал, но не вызывался ниоткуда.
 */
class BroadcastPublishAtOrderTest extends TestCase
{
    use RefreshDatabase;

    private TelegramChatBroadcast $broadcast;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 3, 22, 10, 0, 0, 'Europe/Moscow'));

        $chat = new TelegramChat;
        $chat->telegram_chat_id = -100555;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->save();

        $this->broadcast = new TelegramChatBroadcast;
        $this->broadcast->chat_id = $chat->id;
        $this->broadcast->enabled = true;
        $this->broadcast->settings = ['period' => 'daily_10', 'template_code' => 'basic'];
        $this->broadcast->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function item(?Carbon $publishAt, Carbon $createdAt, string $status = TelegramChatBroadcastItem::STATUS_PENDING): TelegramChatBroadcastItem
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $this->broadcast->id;
        $item->status = $status;
        $item->publish_at = $publishAt;
        $item->save();

        // created_at пишем отдельно: Eloquent проставляет своё.
        TelegramChatBroadcastItem::query()->whereKey($item->id)->update(['created_at' => $createdAt]);

        return $item->fresh();
    }

    private function next(): ?TelegramChatBroadcastItem
    {
        return app(TelegramChatBroadcastItemRepositoryInterface::class)
            ->findActiveForBroadcast($this->broadcast->id, Carbon::now());
    }

    public function test_takes_the_post_assigned_to_today_not_the_oldest(): void
    {
        // Старый пост без дня и сегодняшний назначенный. Раньше побеждал
        // старый: сортировка шла по created_at.
        $old = $this->item(null, Carbon::now()->subDays(5));
        $today = $this->item(Carbon::now(), Carbon::now());

        $this->assertSame($today->id, $this->next()?->id);
        $this->assertNotSame($old->id, $this->next()?->id);
    }

    public function test_post_without_a_day_waits_behind_assigned_ones(): void
    {
        // Ровно случай вытеснения: снятый с дня пост старше того, кто его
        // вытеснил, и обгонял бы его — вопреки обещанию «ждёт свободного дня».
        $displaced = $this->item(null, Carbon::now()->subDays(3));
        $assigned = $this->item(Carbon::now(), Carbon::now());

        $this->assertSame($assigned->id, $this->next()?->id);

        // Когда назначенный ушёл, очередь доходит и до ожидающего.
        $assigned->status = TelegramChatBroadcastItem::STATUS_POSTED;
        $assigned->posted_at = Carbon::now();
        $assigned->save();

        $this->assertSame($displaced->id, $this->next()?->id);
    }

    public function test_future_day_is_not_taken_early(): void
    {
        $this->item(Carbon::now()->addDays(2), Carbon::now()->subDays(9));

        $this->assertNull($this->next(), 'пост, назначенный на послезавтра, не должен уходить сегодня');
    }

    public function test_earlier_day_goes_first(): void
    {
        // Порядок задаёт день, а не время создания.
        $later = $this->item(Carbon::now()->subMinutes(5), Carbon::now()->subDays(9));
        $earlier = $this->item(Carbon::now()->subHours(3), Carbon::now());

        $this->assertSame($earlier->id, $this->next()?->id);
        $this->assertNotSame($later->id, $this->next()?->id);
    }

    public function test_review_is_offered_before_its_day_arrives(): void
    {
        // Иначе превью пришло бы рецензенту ровно в момент публикации и
        // решать было бы уже нечего.
        $review = $this->item(
            Carbon::now()->addDays(2),
            Carbon::now(),
            TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
        );

        $this->assertSame($review->id, $this->next()?->id);
    }

    public function test_posts_without_days_keep_the_old_order_between_themselves(): void
    {
        // На каналах, где publish_at не проставлен нигде, поведение прежнее.
        $first = $this->item(null, Carbon::now()->subDays(4));
        $this->item(null, Carbon::now()->subDay());

        $this->assertSame($first->id, $this->next()?->id);
    }
}
