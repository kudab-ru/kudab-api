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
     * В `eve` завтрашние посты выбираются после последнего сегодняшнего, на ближайшем пятиминутном прогоне.
     * Админка повторяет его в `eveOpensAt` (broadcast/index.vue) и `evePickTime` (broadcast/settings.vue).
     */
    public const EVE_DELAY_MINUTES = 5;

    /** Слот «по возможности» в последний час не решаем: анонсу нужно время, а человеку — увидеть пост заранее. */
    public const OPTIONAL_CUTOFF_MINUTES = 60;

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

    /**
     * Часы, которые заполняются каждый день; остальные — «по возможности».
     *
     * @return list<int>
     */
    public function requiredSlots(TelegramChatBroadcast $broadcast): array
    {
        return array_values(array_diff($this->slots($broadcast), $broadcast->optional_slots));
    }

    /** Слот «по возможности»: заполняется только в насыщенный день, см. TelegramChatBroadcastService::optionalSupply. */
    public function isOptional(TelegramChatBroadcast $broadcast, CarbonInterface $at): bool
    {
        return in_array((int) $at->copy()->setTimezone(self::TZ)->hour, $broadcast->optional_slots, true);
    }

    /** Ключ слота: день и час по Москве. */
    public function key(CarbonInterface|Carbon|string $at): string
    {
        return Carbon::parse($at)->setTimezone(self::TZ)->format('Y-m-d H');
    }

    /**
     * С какого момента слот открыт автоподбору; null — как только попал в горизонт. В `eve` и у слота
     * «по возможности» — накануне, через EVE_DELAY_MINUTES после последнего слота дня по Москве. В `week`
     * поздние обязательные слоты ждут fill_lead_days: половина афиши объявляется поздно.
     */
    public function opensAt(TelegramChatBroadcast $broadcast, Carbon $slotAt): ?Carbon
    {
        if ($broadcast->picksOnEve() || $this->isOptional($broadcast, $slotAt)) {
            return $slotAt->copy()->setTimezone(self::TZ)->subDay()
                ->setTime(max($this->slots($broadcast)), self::EVE_DELAY_MINUTES, 0);
        }

        $lead = $broadcast->fill_lead_days;
        $hour = (int) $slotAt->copy()->setTimezone(self::TZ)->hour;
        if ($lead === null || $hour === ($this->requiredSlots($broadcast)[0] ?? null)) {
            return null;
        }

        return $slotAt->copy()->subDays($lead);
    }

    /**
     * Слоты подборок, под которые бронь ещё не встала: обычный пост туда не ставим, иначе бронь
     * уедет на неделю (см. BroadcastDigestBooking::slotAt). Снятая подборка свой слот отпускает.
     *
     * @return array<string, true> ключи key()
     */
    public function digestHeld(TelegramChatBroadcast $broadcast, Carbon $from, int $days): array
    {
        $slots = $broadcast->digest_slots;
        if ($slots === []) {
            return [];
        }

        $start = $from->copy()->setTimezone(self::TZ)->startOfDay();
        $held = [];
        // с завтрашнего дня: на сегодня бронь уже не встанет, она ищет день начиная с завтра
        for ($i = 1; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            foreach ($slots as $slot) {
                if ($day->isoWeekday() === $slot['weekday']) {
                    $held[$this->key($day->copy()->setTime($slot['hour'], 0))] = true;
                }
            }
        }

        if ($held === []) {
            return [];
        }

        $released = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_SKIPPED,
                TelegramChatBroadcastItem::STATUS_REJECTED,
            ])
            ->where('publish_at', '>=', $start->copy()->utc())
            ->pluck('publish_at');

        foreach ($released as $at) {
            unset($held[$this->key($at)]);
        }

        return $held;
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
     * Ближайший свободный обязательный слот, null — всё занято. Слоты «по возможности» и
     * слоты подборок отсюда не отдаём: первые решает насыщенность дня, вторые держит бронь.
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
        $held = $this->digestHeld($broadcast, $now, $days);

        for ($i = 0; $i < $days; $i++) {
            $day = $now->copy()->setTimezone(self::TZ)->addDays($i)->startOfDay();

            foreach ($this->openSlots($broadcast, $day, $now, $respectLead, withOptional: false) as $at) {
                $key = $this->key($at);
                if (! in_array($key, $taken, true) && ! isset($held[$key])) {
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
    public function openSlots(
        TelegramChatBroadcast $broadcast,
        Carbon $day,
        Carbon $now,
        bool $respectLead = true,
        bool $withOptional = true,
    ): array {
        $open = [];
        foreach ($this->slots($broadcast) as $hour) {
            $at = $day->copy()->setTimezone(self::TZ)->setTime($hour, 0, 0);
            // прошедший слот не заполняем: пост встал бы просроченным и тут же потерял бы день
            if ($at->lt($now)) {
                continue;
            }
            $optional = $this->isOptional($broadcast, $at);
            if ($optional && ! $withOptional) {
                continue;
            }
            if ($respectLead) {
                $opensAt = $this->opensAt($broadcast, $at);
                if ($opensAt !== null && $now->lt($opensAt)) {
                    continue;
                }
                if ($optional && $now->gte($at->copy()->subMinutes(self::OPTIONAL_CUTOFF_MINUTES))) {
                    continue;
                }
            }
            $open[] = $at;
        }

        return $open;
    }
}
