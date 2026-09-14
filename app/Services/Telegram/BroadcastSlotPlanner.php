<?php

namespace App\Services\Telegram;

use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Где в ленте канала свободно.
 *
 * Отдельный класс, а не метод сервиса рассылки: портреты площадок должны
 * назначать день тем же расчётом, что и события, но TelegramChatBroadcastService
 * уже зависит от сервиса портретов — прямая связь замкнула бы круг.
 *
 * Место в ленте — это СЛОТ: день плюс час по Москве. Пока слот был днём,
 * занятость считалась через ->toDateString() в четырёх местах, и два поста в
 * одном дне схлопывались в один.
 */
class BroadcastSlotPlanner
{
    public const TZ = 'Europe/Moscow';

    /**
     * Часы публикации канала по возрастанию.
     *
     * Слоты заданы — они. Не заданы — один час из расписания: так канал без
     * слотов ведёт себя ровно как раньше.
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
     * Ближайший свободный слот канала начиная с $now, или null — всё занято.
     *
     * Прошедшие слоты пропускаем: пост встал бы просроченным и тут же потерял
     * бы день.
     */
    public function nextFreeSlot(TelegramChatBroadcast $broadcast, Carbon $now): ?Carbon
    {
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

        for ($i = 0; $i < $broadcast->horizon_days; $i++) {
            $day = $now->copy()->setTimezone(self::TZ)->addDays($i)->startOfDay();

            foreach ($slots as $hour) {
                $at = $day->copy()->setTime($hour, 0, 0);
                if ($at->lt($now)) {
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
