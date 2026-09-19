<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Services\Telegram\BroadcastDigestBooking;
use App\Services\Telegram\BroadcastSlotPlanner;
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

    /**
     * Включение рубрики в настройках ставит бронь СРАЗУ.
     *
     * Бронь ставит почасовая команда, и без этого после сохранения настроек в
     * ленте до часа не появлялось ничего: человек включил подборку, не увидел
     * её и решил, что настройка не сохранилась. Ровно так и вышло на первой же
     * живой проверке.
     */
    public function test_enabling_the_rubric_books_a_slot_immediately(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00', 'Europe/Moscow'));

        \Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web');
        $user = \App\Models\User::factory()->create();
        $user->assignRole('superadmin');
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $broadcast = $this->makeChannel();

        $this->patchJson("/api/admin/broadcast/channels/{$broadcast->id}", ['digest_weekday' => 1])
            ->assertOk();

        $this->assertSame(1, TelegramChatBroadcastItem::query()
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->whereNull('posted_at')
            ->count(), 'бронь появилась сразу, а не через час');

        // И выключение убирает неотправленную бронь: оставить её значило бы
        // выпустить рубрику, от которой только что отказались.
        $this->patchJson("/api/admin/broadcast/channels/{$broadcast->id}", ['digest_weekday' => null])
            ->assertOk();

        $this->assertSame(0, TelegramChatBroadcastItem::query()
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
            ])
            ->count());

        Carbon::setTestNow();
    }

    /**
     * Воскресенье — такой же день недели, как остальные шесть.
     *
     * Настройка хранится по-человечески (1 пн … 7 вс), Carbon считает иначе
     * (0 вс … 6 сб), и `next(7)` кидал исключение. Валидация значение
     * пропускала, админка его предлагала — а `broadcast:enqueue-digests`
     * падала целиком, для ВСЕХ каналов сразу.
     */
    public function test_sunday_books_like_any_other_weekday(): void
    {
        // Вторник 15 сентября — ближайшее воскресенье 20-е.
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00', 'Europe/Moscow'));

        $broadcast = $this->makeChannel();
        $broadcast->slots = [10, 19];
        $broadcast->digest_weekday = 7;
        $broadcast->save();

        $summary = $this->booking()->bookDue(Carbon::now());

        $this->assertSame(0, $summary['failed'], 'воскресенье больше не роняет прогон');
        $this->assertSame(1, $summary['booked']);

        $item = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->firstOrFail();

        $this->assertSame(
            '2026-09-20 19:00',
            Carbon::parse($item->publish_at)->setTimezone('Europe/Moscow')->format('Y-m-d H:i'),
            'воскресенье, вечерний слот',
        );

        Carbon::setTestNow();
    }

    /** Все семь дней доезжают до слота — ни один не роняет команду. */
    public function test_every_weekday_books_a_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00', 'Europe/Moscow'));

        for ($weekday = 1; $weekday <= 7; $weekday++) {
            TelegramChatBroadcastItem::query()->delete();

            $broadcast = TelegramChatBroadcast::query()->firstOr(fn () => $this->makeChannel());
            $broadcast->slots = [10, 19];
            $broadcast->digest_weekday = $weekday;
            $broadcast->save();

            $summary = $this->booking()->bookDue(Carbon::now());

            $this->assertSame(0, $summary['failed'], "день {$weekday} уронил прогон");
            $this->assertSame(1, $summary['booked'], "день {$weekday} не забронировал слот");

            $at = Carbon::parse(TelegramChatBroadcastItem::query()
                ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
                ->value('publish_at'))->setTimezone('Europe/Moscow');

            $this->assertSame($weekday, $at->isoWeekday(), "день {$weekday} уехал не в свой день недели");
        }

        Carbon::setTestNow();
    }

    /**
     * Упавший канал не уносит остальные, а команда об этом говорит.
     *
     * До гарда любая ошибка на одном канале уносила весь прогон: рубрика
     * молча переставала бронировать слоты во ВСЕХ каналах разом, и заметить
     * это можно было только по отсутствию подборок. Поломку подсовываем
     * планировщику — это единственная общая зависимость брони, и ошибка в
     * ней воспроизводит ровно тот случай, что был с воскресеньем.
     */
    public function test_a_broken_channel_does_not_take_the_others_down(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00', 'Europe/Moscow'));

        $broken = $this->makeChannel();
        $broken->slots = [10, 19];   // вечерний час 19 — на нём и споткнётся
        $broken->digest_weekday = 1;
        $broken->save();

        $healthy = $this->makeSecondChannel();
        $healthy->slots = [10, 12];  // вечерний час 12 — этот канал доедет
        $healthy->digest_weekday = 1;
        $healthy->save();

        $this->app->bind(BroadcastSlotPlanner::class, fn () => new class extends BroadcastSlotPlanner
        {
            public function key(\Carbon\CarbonInterface|Carbon|string $at): string
            {
                if (Carbon::parse($at)->setTimezone(self::TZ)->format('H') === '19') {
                    throw new \RuntimeException('сломанный канал');
                }

                return parent::key($at);
            }
        });

        $summary = $this->booking()->bookDue(Carbon::now());

        $this->assertSame(1, $summary['failed'], 'сломанный канал посчитан отдельно');
        $this->assertSame(1, $summary['booked'], 'исправный канал бронь получил');

        $this->assertSame(1, TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $healthy->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->count());
        $this->assertSame(0, TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broken->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->count());

        Carbon::setTestNow();
    }

    /**
     * Выключенная рубрика не роняет возврат снятой подборки.
     *
     * `slotFor()` зовут из админки, когда снятый пост возвращают в ленту, и у
     * подборки выключенного канала дня рубрики нет. Строгий тип у перевода
     * нумерации превращал это в TypeError, то есть в 500 на кнопке «вернуть».
     */
    public function test_slot_for_a_disabled_rubric_is_null_not_a_crash(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00', 'Europe/Moscow'));

        $broadcast = $this->makeChannel(); // digest_weekday не задан

        $this->assertNull($this->booking()->slotFor($broadcast, Carbon::now()));

        Carbon::setTestNow();
    }

    private function makeSecondChannel(): TelegramChatBroadcast
    {
        $owner = TelegramUser::create(['telegram_id' => 8307201889]);
        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999078;
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
