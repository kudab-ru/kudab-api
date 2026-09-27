<?php

namespace App\Services\Telegram;

use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Где в ленте канала свободно. Слот — день плюс час по Москве: занятость сравнивать
 * по key(), не по дате, в дне бывает несколько слотов. Отдельным классом, потому что
 * TelegramChatBroadcastService зависит от сервиса портретов, а тому нужен этот расчёт.
 */
class BroadcastSlotPlanner
{
    public const TZ = 'Europe/Moscow';

    /**
     * Часы публикации канала по возрастанию. Без slots — один час из period.
     *
     * @return list<int>
     */
    public function slots(TelegramChatBroadcast $broadcast): array
    {
        $slots = $broadcast->slots;
        if ($slots !== []) {
            return $slots;
        }

        if (preg_match('/_(\d{1,2})$/', trim((string) $broadcast->period), $m)) {
            return [max(0, min(23, (int) $m[1]))];
        }

        return [10];
    }

    /** Ключ слота: день и час по Москве. */
    public function key(CarbonInterface|Carbon|string $at): string
    {
        return Carbon::parse($at)->setTimezone(self::TZ)->format('Y-m-d H');
    }

    /**
     * Ближайший свободный слот в пределах horizon_days, null — всё занято.
     *
     * @param  bool  $respectLead  закрывать поздние слоты дальше fill_lead_days;
     *                             false передают пути, где пост ставит человек
     */
    public function nextFreeSlot(
        TelegramChatBroadcast $broadcast,
        Carbon $now,
        bool $respectLead = true,
    ): ?Carbon {
        $taken = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ])
            ->whereNotNull('publish_at')
            ->pluck('publish_at')
            ->map(fn ($d) => $this->key($d))
            ->all();

        $slots = $this->slots($broadcast);
        $lead = $respectLead ? $broadcast->fill_lead_days : null;
        $leadEdge = $lead !== null ? $now->copy()->addDays($lead) : null;

        for ($i = 0; $i < $broadcast->horizon_days; $i++) {
            $day = $now->copy()->setTimezone(self::TZ)->addDays($i)->startOfDay();

            foreach ($slots as $slotIndex => $hour) {
                $at = $day->copy()->setTime($hour, 0, 0);
                if ($at->lt($now)) {
                    continue;
                }
                // копия правила fill_lead_days из fillFeedDays, правятся вместе
                if ($leadEdge !== null && $slotIndex > 0 && $at->gt($leadEdge)) {
                    continue;
                }
                if (in_array($this->key($at), $taken, true)) {
                    continue;
                }

                return $at;
            }
        }

        return null;
    }
}
