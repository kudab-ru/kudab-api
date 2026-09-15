<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Бронь слота под подборку недели.
 *
 * ЗАЧЕМ ОТДЕЛЬНАЯ БРОНЬ. Слот в ленте держит только существующая запись:
 * планировщик слотов понятия «этот час принадлежит рубрике» не имеет, а
 * наполнитель ленты заполняет всё свободное событиями. Без брони вечер
 * понедельника разбирался бы под обычные посты, и подборке некуда было бы
 * встать.
 *
 * ПОЧЕМУ ЗАПИСЬ ПУСТАЯ. Подборка, собранная заранее, показывает пятую часть
 * недели: в следующей неделе событий вчетверо меньше, чем в текущей (замер в
 * docs/broadcast-admin/CADENCE.md). Поэтому бронь встаёт без состава и без
 * текста, а наполняет их композитор перед самой отправкой.
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
     * @return array{checked: int, booked: int, already: int, off: int}
     */
    public function bookDue(Carbon $now, bool $dryRun = false): array
    {
        $summary = ['checked' => 0, 'booked' => 0, 'already' => 0, 'off' => 0];

        $broadcasts = TelegramChatBroadcast::query()->with('chat')->get();

        foreach ($broadcasts as $broadcast) {
            $summary['checked']++;

            if (! $broadcast->enabled || $broadcast->period === 'off' || $broadcast->digest_weekday === null) {
                $summary['off']++;

                continue;
            }

            // Одна бронь в полёте: пока стоит будущая подборка, вторая не нужна.
            // Следующую поставит этот же прогон после того, как первая уйдёт.
            if ($this->hasOpenDigest($broadcast, $now)) {
                $summary['already']++;

                continue;
            }

            $at = $this->nextSlot($broadcast, $now);
            if ($at === null) {
                continue;
            }

            if ($dryRun) {
                $summary['booked']++;

                continue;
            }

            $item = new TelegramChatBroadcastItem;
            $item->broadcast_id = $broadcast->id;
            $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
            $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
            $item->publish_at = $at->utc();
            $item->save();

            $summary['booked']++;
        }

        return $summary;
    }

    /** Стоит ли уже будущая подборка — в любом открытом статусе. */
    private function hasOpenDigest(TelegramChatBroadcast $broadcast, Carbon $now): bool
    {
        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
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
        $msk = $now->copy()->setTimezone(BroadcastSlotPlanner::TZ);
        $hour = $broadcast->digest_hour;

        for ($week = 0; $week < 5; $week++) {
            $candidate = $msk->copy()
                ->startOfDay()
                ->next($broadcast->digest_weekday)
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
