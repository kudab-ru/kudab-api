<?php

namespace App\Services\Telegram;

use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Services\Occasions\OccasionCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Закреплённый пост канала: «На неделе», «На выходных», а в последнюю неделю перед праздником —
 * его подборка, и до конца праздника обычная её не сменяет. Что закреплено, лежит в настройках
 * канала: `pinned_message_id`, `pinned_until`.
 */
class BroadcastPin
{
    /** @return array{pin?: true, unpin_message_id?: int} поля задачи боту; пусто — не закреплять */
    public function forTask(TelegramChatBroadcastItem $item, TelegramChatBroadcast $broadcast, Carbon $now): array
    {
        $rule = $this->rule($item, $now);
        if ($rule === null) {
            return [];
        }

        $settings = $broadcast->settings ?? [];
        $held = $settings['pinned_until'] ?? null;
        if ($rule['until'] === null && $held !== null && Carbon::parse($held)->gt($now)) {
            return [];
        }

        $previous = (int) ($settings['pinned_message_id'] ?? 0);

        return ['pin' => true] + ($previous > 0 ? ['unpin_message_id' => $previous] : []);
    }

    /** Бот закрепил пост. Ошибка только в лог: пост уже в канале. */
    public function remember(int $itemId, int $messageId, Carbon $now): void
    {
        try {
            $item = TelegramChatBroadcastItem::query()->find($itemId);
            $rule = $item === null ? null : $this->rule($item, $now);
            $broadcast = $rule === null ? null : TelegramChatBroadcast::query()->find($item->broadcast_id);
            if ($broadcast === null) {
                return;
            }

            $settings = $broadcast->settings ?? [];
            $settings['pinned_message_id'] = $messageId;
            $settings['pinned_until'] = $rule['until']?->toIso8601String();
            $broadcast->settings = $settings;
            $broadcast->save();
        } catch (\Throwable $e) {
            Log::warning('broadcast.pin.remember_failed', ['item_id' => $itemId, 'err' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    /**
     * null — рубрика не закрепляется. `until` пустой — держится до следующей закрепляемой подборки,
     * дата — праздничная, держится до конца окна праздника.
     *
     * @return array{until: ?Carbon}|null
     */
    private function rule(TelegramChatBroadcastItem $item, Carbon $now): ?array
    {
        $slug = $item->digestTheme();
        if ($item->kind !== TelegramChatBroadcastItem::KIND_DIGEST || $slug === null || ! config('broadcast_pin.enabled', true)) {
            return null;
        }
        if (in_array($slug, (array) config('broadcast_pin.themes', []), true)) {
            return ['until' => null];
        }

        $theme = collect(OccasionCalendar::themes())->first(fn ($t) => ($t['slug'] ?? null) === $slug);
        $occurrence = $theme === null ? null : OccasionCalendar::occurrence($theme, $now, $item->broadcast?->chat?->city_id);
        if ($occurrence === null || $occurrence['from']->gt($now->copy()->addDays((int) config('broadcast_pin.occasion_days', 7)))) {
            return null;
        }

        return ['until' => $occurrence['to']];
    }
}
