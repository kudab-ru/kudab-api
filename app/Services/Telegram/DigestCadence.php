<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Рубрика с `every_days` выходит не чаще раза в этот срок: автосборка её не берёт, а слот с ней
 * не бронируется, пока рядом стоит такая же подборка.
 */
final class DigestCadence
{
    /**
     * Нет ли подборки этой рубрики ближе срока до или после момента. Срок на день короче:
     * подборка выходит на минуты позже слота, и слот через две недели иначе оказался бы рано.
     *
     * @param  array<string, mixed>  $theme
     */
    public static function due(int $broadcastId, array $theme, Carbon $at, ?int $exceptItemId = null): bool
    {
        $every = (int) ($theme['every_days'] ?? 0);
        $slug = (string) ($theme['slug'] ?? '');
        if ($every <= 0 || $slug === '') {
            return true;
        }

        $gap = $every - 1;

        return ! DB::table('telegram.chat_broadcast_items')
            ->where('broadcast_id', $broadcastId)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->when($exceptItemId !== null, fn ($q) => $q->where('id', '<>', $exceptItemId))
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_POSTED,
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ])
            ->whereRaw('coalesce(publish_at, posted_at) > ?', [$at->copy()->subDays($gap)])
            ->whereRaw('coalesce(publish_at, posted_at) < ?', [$at->copy()->addDays($gap)])
            // заказанная слотом рубрика тоже в счёт, хотя состав ещё не собран
            ->whereRaw("coalesce(digest_meta->>'theme', digest_meta->>'theme_wanted') = ?", [$slug])
            ->exists();
    }
}
