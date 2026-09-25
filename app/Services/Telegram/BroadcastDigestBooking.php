<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Бронь слота под подборку недели.
 *
 * ЗАЧЕМ ОТДЕЛЬНАЯ БРОНЬ. Слот в ленте держит только существующая запись:
 * планировщик слотов понятия «этот час принадлежит рубрике» не имеет, а
 * наполнитель ленты заполняет всё свободное событиями. Без брони вечер
 * понедельника разбирался бы под обычные посты, и подборке некуда было бы
 * встать.
 *
 * ПОЧЕМУ ЗАПИСЬ ПУСТАЯ. Подборка, собранная в день брони, показывала бы пятую
 * часть недели: в следующей неделе событий вчетверо меньше, чем в текущей
 * (замер в docs/broadcast-admin/CADENCE.md). Поэтому бронь встаёт без состава
 * и без текста.
 *
 * НО НЕ ДО ПОСЛЕДНЕЙ СЕКУНДЫ. Состав собирает `broadcast:prepare-digests`
 * заранее: замер по записям 214 и 215 и выбор числа часов — у `prepare_hours`
 * в config/broadcast_digest.php. Подпись по-прежнему пересобирается перед
 * отправкой: диапазон дат, цены и время должны быть свежими.
 *
 * ЧЕГО БРОНЬ НЕ ДЕЛАЕТ. Не закрепляет запись: пересборка недели её и так не
 * трогает (она снимает только `kind = event`), а закрепление мешало бы человеку
 * перетащить подборку на другой день.
 */
final class BroadcastDigestBooking
{
    public function __construct(
        private readonly BroadcastSlotPlanner $slotPlanner,
    ) {}

    /**
     * Поставить брони всем каналам, где рубрика включена.
     *
     * @return array{checked: int, booked: int, already: int, off: int, failed: int}
     */
    public function bookDue(Carbon $now, bool $dryRun = false): array
    {
        $summary = ['checked' => 0, 'booked' => 0, 'already' => 0, 'off' => 0, 'failed' => 0];

        $broadcasts = TelegramChatBroadcast::query()->with('chat')->get();

        foreach ($broadcasts as $broadcast) {
            $summary['checked']++;

            try {
                $this->bookOne($broadcast, $now, $dryRun, $summary);
            } catch (\Throwable $e) {
                // Один канал не должен ронять прогон. До этого гарда любая
                // ошибка на одном канале уносила всю команду: настройка
                // «воскресенье» роняла broadcast:enqueue-digests целиком, и
                // рубрика молча переставала бронировать слоты ВЕЗДЕ.
                $summary['failed']++;
                Log::error('broadcast.digest.book_failed', [
                    'broadcast_id' => $broadcast->id,
                    'digest_weekday' => $broadcast->digest_weekday,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * Бронь одного канала.
     *
     * @param  array{checked: int, booked: int, already: int, off: int, failed: int}  $summary
     */
    private function bookOne(
        TelegramChatBroadcast $broadcast,
        Carbon $now,
        bool $dryRun,
        array &$summary,
    ): void {
        if (! $broadcast->enabled || $broadcast->period === 'off' || $broadcast->digest_weekday === null) {
            $summary['off']++;

            return;
        }

        // Одна бронь в полёте: пока стоит будущая подборка, вторая не нужна.
        // Следующую поставит этот же прогон после того, как первая уйдёт.
        if ($this->hasOpenDigest($broadcast, $now)) {
            $summary['already']++;

            return;
        }

        $at = $this->nextSlot($broadcast, $now);
        if ($at === null) {
            return;
        }

        if ($dryRun) {
            $summary['booked']++;

            return;
        }

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->publish_at = $at->utc();
        $item->save();

        $summary['booked']++;
    }

    /**
     * Стоит ли уже будущая подборка — в любом открытом статусе.
     *
     * Подборка ВНЕ СЕТКИ не в счёт: её поставили руками, дополнительно к
     * рубрике, и она не должна отменять очередную недельную. Иначе кнопка
     * «подборка сейчас» тихо съедала бы следующую по расписанию.
     */
    private function hasOpenDigest(TelegramChatBroadcast $broadcast, Carbon $now): bool
    {
        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->where('is_off_grid', false)
            ->whereNull('posted_at')
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ])
            ->exists();
    }

    /**
     * Ближайший слот рубрики — снаружи: возврат снятой подборки обязан ставить
     * её в СВОЙ день недели, а не в первый свободный слот горизонта. Событийный
     * планировщик отдаёт утро ближайшего дня, и подборка теряла свой каденс.
     */
    public function slotFor(TelegramChatBroadcast $broadcast, Carbon $now): ?Carbon
    {
        return $this->nextSlot($broadcast, $now);
    }

    /**
     * Ближайший слот рубрики: нужный день недели, вечерний час канала.
     *
     * Занятый слот НЕ вытесняем — берём следующую неделю: вытеснить чужой пост
     * ради брони, которая ещё даже не знает своего состава, было бы обменом
     * живого на пустое. Если и через месяц свободного дня нет, значит что-то не
     * так с лентой, а не с рубрикой.
     */
    private function nextSlot(TelegramChatBroadcast $broadcast, Carbon $now): ?Carbon
    {
        // Рубрика выключена — слота у неё нет. Раньше здесь получалось
        // `Carbon->next(null)`, то есть «тот же день недели через неделю», и
        // снятая подборка возвращалась в случайный день выключенной рубрики.
        // Возврат из админки это переживает штатно: запись без дня встаёт в
        // «ждут свободного дня».
        $weekday = $broadcast->digest_weekday;
        if ($weekday === null) {
            return null;
        }

        $msk = $now->copy()->setTimezone(BroadcastSlotPlanner::TZ);
        $hour = $broadcast->digest_hour;

        for ($week = 0; $week < 5; $week++) {
            $candidate = $msk->copy()
                ->startOfDay()
                ->next($this->carbonWeekday($weekday))
                ->addWeeks($week)
                ->setTime($hour, 0);

            if ($candidate->lte($msk)) {
                continue;
            }

            if (! $this->slotTaken($broadcast, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * День недели рубрики в нумерации Carbon.
     *
     * Настройка хранится по-человечески: 1 — понедельник … 7 — воскресенье.
     * Carbon считает иначе: 0 — воскресенье … 6 — суббота, и `next(7)` не
     * «воскресенье», а исключение InvalidFormatException. То есть выбор
     * «воскресенье» в админке валидацию проходил (min:1, max:7), сохранялся —
     * и ронял `broadcast:enqueue-digests` целиком, для всех каналов сразу.
     * Понедельник–суббота совпадают в обеих нумерациях, поэтому дыра была
     * ровно в одном значении из семи и на глаза не попадалась.
     */
    private function carbonWeekday(int $isoWeekday): int
    {
        return $isoWeekday % 7;
    }

    private function slotTaken(TelegramChatBroadcast $broadcast, Carbon $at): bool
    {
        $key = $this->slotPlanner->key($at);

        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereNotNull('publish_at')
            ->whereNull('posted_at')
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
                TelegramChatBroadcastItem::STATUS_ERROR,
            ])
            ->get(['publish_at'])
            ->contains(fn ($row) => $this->slotPlanner->key(Carbon::parse($row->publish_at)) === $key);
    }
}
