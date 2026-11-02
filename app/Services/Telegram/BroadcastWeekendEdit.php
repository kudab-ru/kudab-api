<?php

namespace App\Services\Telegram;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * «На выходных» выходит в четверг, первым в нём вечер пятницы. В субботу утром бот переписывает пост
 * без пятницы, и в закреплённом остаётся только то, что впереди. Текст собирает
 * `BroadcastDigestComposer::saturdayCaption` в момент правки, ход правки лежит в `digest_meta.saturday_edit`.
 */
class BroadcastWeekendEdit
{
    private const THEME = 'na-vyhodnyh';

    /** с этого часа субботы по Москве; в воскресенье ещё правим, если в субботу не вышло */
    private const HOUR = 5;

    private const TRIES = 3;

    private const PAUSE_MINUTES = 15;

    public function __construct(private BroadcastDigestComposer $composer) {}

    /** @return array<string, mixed>|null задача боту; null — править нечего */
    public function task(TelegramChatBroadcast $broadcast, TelegramChat $chat, Carbon $now): ?array
    {
        $msk = $now->copy()->setTimezone('Europe/Moscow');
        if (! ($msk->isSaturday() && $msk->hour >= self::HOUR) && ! $msk->isSunday()) {
            return null;
        }

        $item = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->where('status', TelegramChatBroadcastItem::STATUS_POSTED)
            ->whereNotNull('message_id')
            ->where('posted_at', '>=', $msk->copy()->startOfWeek(Carbon::MONDAY)->addDays(3)->utc())
            ->whereRaw("digest_meta->>'theme' = ?", [self::THEME])
            ->whereRaw("(digest_meta->'saturday_edit'->>'done_at') is null")
            ->orderByDesc('posted_at')
            ->first();
        if ($item === null) {
            return null;
        }

        $state = (array) (($item->digest_meta ?? [])['saturday_edit'] ?? []);
        $tries = (int) ($state['tries'] ?? 0);
        if ($tries >= self::TRIES
            || (isset($state['tried_at']) && Carbon::parse($state['tried_at'])->gt($now->copy()->subMinutes(self::PAUSE_MINUTES)))) {
            return null;
        }

        try {
            $caption = $this->composer->saturdayCaption($item, $broadcast, $now);
        } catch (\Throwable $e) {
            Log::warning('broadcast.weekend_edit.caption_failed', ['item_id' => $item->id, 'err' => mb_substr($e->getMessage(), 0, 200)]);
            $this->save($item, ['tries' => $tries + 1, 'tried_at' => $now->toIso8601String(), 'error' => 'текст на субботу не собрался']);

            return null;
        }
        if ($caption === null) {
            $this->save($item, ['done_at' => $now->toIso8601String(), 'skipped' => true]);

            return null;
        }

        // попытку считаем до ответа бота, иначе при недоступном телеграме правка уходила бы каждую минуту
        $this->save($item, ['tries' => $tries + 1, 'tried_at' => $now->toIso8601String(), 'caption' => $caption]);

        return [
            'type' => 'edit',
            'item_id' => (int) $item->id,
            'telegram_chat_id' => (int) $chat->telegram_chat_id,
            'message_id' => (int) $item->message_id,
            'caption' => $caption,
        ];
    }

    public function record(int $itemId, bool $ok, ?string $error, Carbon $now): bool
    {
        $item = TelegramChatBroadcastItem::query()->find($itemId);
        if ($item === null) {
            return false;
        }

        $this->save($item, $ok ? ['done_at' => $now->toIso8601String(), 'error' => null] : ['error' => mb_substr((string) $error, 0, 300)]);

        return true;
    }

    /** @param  array<string, mixed>  $patch */
    private function save(TelegramChatBroadcastItem $item, array $patch): void
    {
        $meta = $item->digest_meta ?? [];
        $meta['saturday_edit'] = array_merge((array) ($meta['saturday_edit'] ?? []), $patch);
        $item->digest_meta = $meta;
        $item->save();
    }
}
