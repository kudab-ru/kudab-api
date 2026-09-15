<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Services\Telegram\BroadcastDigestBooking;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Бронь слота под подборку недели.
 *
 * Слот в ленте держит только существующая запись: планировщик понятия «этот
 * час принадлежит рубрике» не имеет, а наполнитель заполняет всё свободное
 * событиями. Без брони вечер понедельника разобрали бы под обычные посты.
 */
class BroadcastDigestBookingTest extends TestCase
{
    use RefreshDatabase;

    /** По умолчанию рубрика ВЫКЛЮЧЕНА: новая рубрика не появляется от выкатки. */
    public function test_nothing_is_booked_while_the_rubric_is_off(): void
    {
        $this->makeChannel();

        $summary = $this->booking()->bookDue(Carbon::now());

        $this->assertSame(0, $summary['booked']);
        $this->assertSame(1, $summary['off']);
    }

    public function test_books_the_next_chosen_weekday_at_the_evening_slot(): void
    {
        // Вторник 15 сентября, 12:00 МСК — ближайший понедельник 21-го.
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00', 'Europe/Moscow'));

        $broadcast = $this->makeChannel();
        $broadcast->slots = [10, 19];
        $broadcast->digest_weekday = 1;
        $broadcast->save();

        $this->booking()->bookDue(Carbon::now());

        $item = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->firstOrFail();

        $at = Carbon::parse($item->publish_at)->setTimezone('Europe/Moscow');
        $this->assertSame('2026-09-21 19:00', $at->format('Y-m-d H:i'), 'понедельник, вечерний слот');
        $this->assertNull($item->event_id, 'бронь встаёт пустой: состав соберут на отправке');
        $this->assertNull($item->caption);

        Carbon::setTestNow();
    }

    /** Одна бронь в полёте: вторая подборка на ту же неделю не нужна. */
    public function test_does_not_book_twice(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00', 'Europe/Moscow'));

        $broadcast = $this->makeChannel();
        $broadcast->digest_weekday = 1;
        $broadcast->save();

        $this->booking()->bookDue(Carbon::now());
        $second = $this->booking()->bookDue(Carbon::now());

        $this->assertSame(0, $second['booked']);
        $this->assertSame(1, $second['already']);
        $this->assertSame(1, TelegramChatBroadcastItem::query()
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)->count());

        Carbon::setTestNow();
    }

    /** Занятый слот не вытесняем — берём следующую неделю. */
    public function test_busy_slot_moves_the_booking_a_week_later(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00', 'Europe/Moscow'));

        $broadcast = $this->makeChannel();
        $broadcast->slots = [10, 19];
        $broadcast->digest_weekday = 1;
        $broadcast->save();

        $busy = new TelegramChatBroadcastItem;
        $busy->broadcast_id = $broadcast->id;
        $busy->kind = TelegramChatBroadcastItem::KIND_EVENT;
        $busy->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $busy->publish_at = Carbon::parse('2026-09-21 19:00', 'Europe/Moscow')->utc();
        $busy->save();

        $this->booking()->bookDue(Carbon::now());

        $item = TelegramChatBroadcastItem::query()
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)->firstOrFail();

        $this->assertSame(
            '2026-09-28 19:00',
            Carbon::parse($item->publish_at)->setTimezone('Europe/Moscow')->format('Y-m-d H:i'),
            'чужой пост ради пустой брони не вытесняем',
        );

        Carbon::setTestNow();
    }

    private function booking(): BroadcastDigestBooking
    {
        return app(BroadcastDigestBooking::class);
    }

    private function makeChannel(): TelegramChatBroadcast
    {
        $owner = TelegramUser::create(['telegram_id' => 8307201888]);
        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999077;
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
