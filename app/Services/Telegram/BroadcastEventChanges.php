<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Event;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Services\EventChanges;
use App\Support\BroadcastSafety;
use App\Support\CalmCaps;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Событие из вышедшего поста отменили или перенесли: отвечаем на этот пост коротким сообщением.
 * Ответ — запись очереди с готовым текстом и `reply_to`, как напоминание о празднике (`BroadcastOccasions`).
 */
final class BroadcastEventChanges
{
    private const TZ = 'Europe/Moscow';

    // на пост месячной давности отвечать поздно: про событие уже забыли
    private const POSTED_WITHIN_DAYS = 21;

    private const RULE = 'event_change';

    /** @return array{replied: int, checked: int} */
    public function run(Carbon $now, bool $dryRun = false): array
    {
        $summary = ['replied' => 0, 'checked' => 0];
        try {
            $changes = DB::table('event_changes')->whereNull('notified_at')->orderBy('id')->limit(50)->get();
        } catch (\Throwable) {
            // при выкате код встаёт раньше миграции, и таблицы ещё может не быть
            return $summary;
        }

        foreach ($changes as $change) {
            $summary['checked']++;
            $event = Event::query()->find($change->event_id);
            $new = $change->kind === EventChanges::MOVED && $change->new_event_id !== null
                ? Event::query()->whereKey($change->new_event_id)->where('status', 'active')->first()
                : null;
            // перенесённому важна новая дата: ответ нужен, пока она впереди, даже если прежняя прошла
            if ($event !== null && $this->notOverYet($new ?? $event, $now)) {
                foreach ($this->postsAbout((int) $event->id, $now) as $parent) {
                    if (! $dryRun) {
                        $this->reply($parent, $event, (string) $change->kind, $new, $now);
                    }
                    $summary['replied']++;
                }
            }
            if (! $dryRun) {
                DB::table('event_changes')->where('id', $change->id)->update(['notified_at' => $now, 'updated_at' => $now]);
            }
        }

        return $summary;
    }

    /** Отмена прошедшего события подписчику уже ничего не меняет. */
    private function notOverYet(Event $event, Carbon $now): bool
    {
        $start = $event->start_time !== null
            ? Carbon::parse($event->start_time)
            : ($event->start_date !== null ? Carbon::parse($event->start_date, self::TZ)->endOfDay() : null);

        return $start !== null && $start->greaterThan($now);
    }

    /**
     * Последний вышедший пост о событии в каждом канале: его собственный или подборка, где оно строкой.
     * Ответы самой ленты (напоминания, прежние ответы) не берём: отвечаем на исходный пост.
     *
     * @return list<TelegramChatBroadcastItem>
     */
    private function postsAbout(int $eventId, Carbon $now): array
    {
        $items = TelegramChatBroadcastItem::query()
            ->where('status', TelegramChatBroadcastItem::STATUS_POSTED)
            ->whereNotNull('message_id')
            ->where('posted_at', '>=', $now->copy()->subDays(self::POSTED_WITHIN_DAYS))
            ->whereRaw("digest_meta->>'reply_to' is null")
            ->where(fn ($q) => $q->where('event_id', $eventId)
                ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('telegram.chat_broadcast_item_events as l')
                    ->whereColumn('l.item_id', 'telegram.chat_broadcast_items.id')->where('l.event_id', $eventId)))
            ->orderByDesc('posted_at')
            ->get();

        $out = [];
        foreach ($items->groupBy('broadcast_id') as $broadcastId => $posts) {
            $chat = TelegramChatBroadcast::query()->with('chat')->find($broadcastId)?->chat;
            if ($chat !== null && BroadcastSafety::postingAllowed((int) $chat->telegram_chat_id)) {
                $out[] = $posts->first();
            }
        }

        return $out;
    }

    private function reply(TelegramChatBroadcastItem $parent, Event $event, string $kind, ?Event $new, Carbon $now): void
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $parent->broadcast_id;
        $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->is_off_grid = true;
        $item->publish_at = $now->copy()->addMinutes(5)->utc();
        // ручная подпись: доставка не пересобирает её как подборку, а пустой список картинок — пост без фото
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_MANUAL;
        $item->photo_urls = [];
        $item->digest_meta = ['theme' => self::RULE, 'theme_title' => 'Отмена и перенос', 'reply_to' => (int) $parent->id];
        $item->pick_meta = PickReason::meta(PickReason::BY_DIGEST, self::RULE);
        $item->save();

        $item->caption = $this->text($event, $kind, $new, (int) $item->id);
        $item->save();

        // состав нужен, иначе сборка заранее приняла бы запись за пустую бронь и собрала заново
        DB::table('telegram.chat_broadcast_item_events')->insertOrIgnore([
            'item_id' => $item->id, 'event_id' => $new?->id ?? $event->id, 'position' => 1, 'created_at' => now(),
        ]);

        Log::info('broadcast.event_change.reply', [
            'item_id' => $item->id, 'reply_to' => $parent->id, 'event_id' => $event->id, 'kind' => $kind,
        ]);
    }

    /** «Праздник русской культуры и спорта 24 октября отменили.» */
    private function text(Event $event, string $kind, ?Event $new, int $itemId): string
    {
        $title = htmlspecialchars(CalmCaps::title((string) $event->title), ENT_NOQUOTES);
        $day = $this->day($event);

        if ($kind === EventChanges::MOVED && $new !== null) {
            $base = rtrim((string) (config('app.url') ?: 'https://kudab.ru'), '/');
            $link = htmlspecialchars(PostLink::utm($base.'/events/'.$new->id, PostLink::MEDIUM_DIGEST, $itemId), ENT_QUOTES);

            return '<a href="'.$link.'">'.$title.'</a> перенесли на '.$this->day($new, withTime: true).'.';
        }

        return match ($kind) {
            EventChanges::CANCELLED => $title.' '.$day.' отменили.',
            default => $title.' '.$day.' перенесли, новую дату пока не назвали.',
        };
    }

    private function day(Event $event, bool $withTime = false): string
    {
        $at = $event->start_time !== null ? Carbon::parse($event->start_time)->setTimezone(self::TZ) : Carbon::parse($event->start_date, self::TZ);
        $hasTime = $event->start_time !== null && $event->time_precision !== 'date';

        return $at->locale('ru')->isoFormat('D MMMM').($withTime && $hasTime ? ', '.$at->format('H:i') : '');
    }
}
