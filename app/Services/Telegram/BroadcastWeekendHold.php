<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Support\WeekendWindow;
use Carbon\Carbon;

/**
 * Выходные под подборку «На выходных»: пока она не вышла, обычный пост не берёт события этих
 * субботы и воскресенья. Подборка вычитает всё, что уже стоит в ленте, и без брони к пятнице
 * получала бы остатки. Вышедшая или снятая подборка выходные отпускает.
 */
final class BroadcastWeekendHold
{
    private const TZ = 'Europe/Moscow';

    private const OPEN = [
        TelegramChatBroadcastItem::STATUS_PENDING,
        TelegramChatBroadcastItem::STATUS_PLANNED,
        TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
        TelegramChatBroadcastItem::STATUS_APPROVED,
        TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
    ];

    /**
     * Выходы подборок «На выходных»: вставшие брони и будущие дни расписания. Расписание нужно
     * потому, что бронь встаёт только на ближайший выход, а лента на неделю вперёд выбирает раньше.
     *
     * @return list<array{until: Carbon, from: Carbon, to: Carbon}> until — выход подборки, from/to — её выходные
     */
    public function windows(int $broadcastId): array
    {
        $slugs = array_column(array_filter(
            (array) config('broadcast_digest.themes', []),
            fn ($theme) => ($theme['window'] ?? '') === 'weekend',
        ), 'slug');

        $broadcast = TelegramChatBroadcast::query()->find($broadcastId);
        if ($slugs === [] || $broadcast === null) {
            return [];
        }

        $moments = [];
        // час, занятый другим постом или снятой подборкой: по расписанию подборки там не будет
        $blocked = [];
        $items = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->where('is_off_grid', false)
            ->whereNull('posted_at')
            ->where('publish_at', '>', Carbon::now()->utc())
            ->get();

        foreach ($items as $item) {
            $key = Carbon::parse($item->publish_at)->setTimezone(self::TZ)->format('Y-m-d H');
            $open = in_array($item->status, self::OPEN, true);
            $isDigest = $item->kind === TelegramChatBroadcastItem::KIND_DIGEST;

            if ($open && $isDigest) {
                // собранная подборка могла уйти в другую рубрику, если на выходные не набралось
                $theme = $item->digestTheme() ?? (($item->digest_meta ?? [])['theme_wanted'] ?? null);
                if (in_array($theme, $slugs, true)) {
                    $moments[$key] = Carbon::parse($item->publish_at);
                }
            }
            if ($open || ($isDigest && in_array($item->status, [
                TelegramChatBroadcastItem::STATUS_SKIPPED,
                TelegramChatBroadcastItem::STATUS_REJECTED,
            ], true))) {
                $blocked[$key] = true;
            }
        }

        // с завтрашнего дня, как и бронь; до дня, о котором может рассказать самый дальний пост ленты
        $day = Carbon::now(self::TZ)->startOfDay()->addDay();
        $last = $day->copy()->addDays($broadcast->horizon_days + TelegramChatBroadcastService::CANDIDATE_HORIZON_DAYS);
        for (; $day->lt($last); $day->addDay()) {
            foreach ($broadcast->digest_slots as $slot) {
                if ($slot['weekday'] !== $day->isoWeekday() || ! in_array($slot['theme'], $slugs, true)) {
                    continue;
                }
                $at = $day->copy()->setTime($slot['hour'], 0);
                $key = $at->format('Y-m-d H');
                if (! isset($blocked[$key])) {
                    $moments[$key] = $at;
                }
            }
        }

        $windows = [];
        foreach ($moments as $until) {
            [$from, $to] = WeekendWindow::for($until->copy()->setTimezone(self::TZ));
            $windows[] = ['until' => $until, 'from' => $from, 'to' => $to];
        }

        return $windows;
    }

    /**
     * Убрать из запроса события выходных, которые держит подборка, выходящая позже поста.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  string  $table  префикс колонок: 'events' или алиас
     */
    public function applyTo($query, int $broadcastId, Carbon $postAt, string $table = 'events'): void
    {
        foreach ($this->windows($broadcastId) as $w) {
            if ($postAt->gte($w['until'])) {
                continue;
            }

            // в UTC: время без пояса база прочтёт как UTC, и московские выходные съедут на три часа
            $query->where(function ($q) use ($w, $table) {
                $q->whereNull($table.'.start_time')
                    ->orWhere($table.'.start_time', '<', $w['from']->copy()->utc())
                    ->orWhere($table.'.start_time', '>', $w['to']->copy()->utc());
            });
        }
    }
}
