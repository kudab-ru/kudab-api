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

    /** В `eve` завтрашние посты выбираются после последнего сегодняшнего, на ближайшем пятиминутном прогоне. */
    public const EVE_DELAY_MINUTES = 5;

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
     * С какого момента слот открыт автоподбору; null — открыт, как только попал в горизонт.
     * В `eve` — накануне, через EVE_DELAY_MINUTES после последнего слота дня по Москве,
     * fill_lead_days там не действует. В `week` поздние слоты дня ждут fill_lead_days:
     * половина афиши объявляется поздно, а первый слот дня собирается на весь горизонт.
     */
    public function opensAt(TelegramChatBroadcast $broadcast, Carbon $slotAt, int $slotIndex): ?Carbon
    {
        if ($broadcast->picksOnEve()) {
            $slots = $this->slots($broadcast);

            return $slotAt->copy()->setTimezone(self::TZ)->subDay()
                ->setTime(max($slots), self::EVE_DELAY_MINUTES, 0);
        }

        $lead = $broadcast->fill_lead_days;
        if ($slotIndex === 0 || $lead === null) {
            return null;
        }

        return $slotAt->copy()->subDays($lead);
    }

    /**
     * Сколько дней от сегодняшнего смотрит автоподбор. В `eve` дальше завтра слоты
     * закрыты, а горизонт в этом режиме не настраивается: при горизонте в сутки завтра не выбралось бы.
     */
    public function autoPickDays(TelegramChatBroadcast $broadcast): int
    {
        return $broadcast->picksOnEve() ? 2 : $broadcast->horizon_days;
    }

    /**
     * Ближайший свободный слот, null — всё занято.
     *
     * @param  bool  $respectLead  держать закрытыми слоты, которые автоподбору ещё рано занимать
     *                             (см. opensAt); false передают пути, где пост ставит человек
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

        $days = $respectLead ? $this->autoPickDays($broadcast) : $broadcast->horizon_days;

        for ($i = 0; $i < $days; $i++) {
            $day = $now->copy()->setTimezone(self::TZ)->addDays($i)->startOfDay();

            foreach ($this->openSlots($broadcast, $day, $now, $respectLead) as $at) {
                if (! in_array($this->key($at), $taken, true)) {
                    return $at;
                }
            }
        }

        return null;
    }

    /** Открыт ли этот слот автоподбору сейчас: тот же отбор дней и слотов, что у наполнителя. */
    public function isOpen(TelegramChatBroadcast $broadcast, Carbon $at, Carbon $now): bool
    {
        $day = $at->copy()->setTimezone(self::TZ)->startOfDay();
        $offset = (int) $now->copy()->setTimezone(self::TZ)->startOfDay()->diffInDays($day, false);
        if ($offset < 0 || $offset >= $this->autoPickDays($broadcast)) {
            return false;
        }

        foreach ($this->openSlots($broadcast, $day, $now) as $slot) {
            if ($this->key($slot) === $this->key($at)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Слоты дня, которые автоподбору уже можно занимать, по Москве.
     *
     * @param  bool  $respectLead  false — все будущие слоты дня: пост ставит человек
     * @return list<Carbon>
     */
    public function openSlots(TelegramChatBroadcast $broadcast, Carbon $day, Carbon $now, bool $respectLead = true): array
    {
        $open = [];
        foreach ($this->slots($broadcast) as $slotIndex => $hour) {
            $at = $day->copy()->setTimezone(self::TZ)->setTime($hour, 0, 0);
            // прошедший слот не заполняем: пост встал бы просроченным и тут же потерял бы день
            if ($at->lt($now)) {
                continue;
            }
            $opensAt = $respectLead ? $this->opensAt($broadcast, $at, $slotIndex) : null;
            if ($opensAt !== null && $now->lt($opensAt)) {
                continue;
            }
            $open[] = $at;
        }

        return $open;
    }
}
