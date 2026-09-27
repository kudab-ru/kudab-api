<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Бронь слота под подборку недели: без неё наполнитель займёт вечер событиями.
 * Запись встаёт пустой, состав собирает broadcast:prepare-digests за prepare_hours
 * до выхода: раньше события той недели ещё не объявлены. is_pinned не ставим,
 * пересборка недели снимает только kind=event.
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
                // один канал не роняет прогон остальных
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

        // одна бронь за раз: следующую поставит прогон после выхода этой
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
     * Внеочередная подборка (is_off_grid) не в счёт: иначе ручная «подборка сейчас»
     * отменила бы очередную недельную.
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

    /** nextSlot для админки: возврат снятой подборки ставит её в день рубрики. */
    public function slotFor(TelegramChatBroadcast $broadcast, Carbon $now): ?Carbon
    {
        return $this->nextSlot($broadcast, $now);
    }

    /**
     * День рубрики, вечерний час канала. Занятый слот не вытесняем, берём
     * следующую неделю: у брони ещё нет состава.
     */
    private function nextSlot(TelegramChatBroadcast $broadcast, Carbon $now): ?Carbon
    {
        // рубрика выключена — слота нет, админка вернёт запись без дня
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
     * digest_weekday хранится как ISO (1 — пн … 7 — вс), Carbon ждёт 0 — вс … 6 — сб:
     * next(7) падает.
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
