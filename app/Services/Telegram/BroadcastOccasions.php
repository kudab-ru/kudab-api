<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Support\BroadcastSafety;
use App\Support\CalmCaps;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Поводы из config/broadcast_digest.php (`occasion`): за `lead_days` до праздника ставим подборку
 * вне сетки, в сам день — короткое напоминание ответом на неё. Состав и текст подборке соберёт
 * broadcast:prepare-digests, как любой другой; напоминание собирается здесь из её состава.
 */
final class BroadcastOccasions
{
    private const TZ = 'Europe/Moscow';

    /** Бронь ставим не больше чем за неделю до выхода: иначе она неделями висела бы в ленте админки. */
    public const BOOK_AHEAD_DAYS = 7;

    /** Пометка записи, которой праздник пропущен в календаре админки: бронь на этот год не ставится. */
    public const SKIPPED_IN_CALENDAR = 'пропущено в календаре';

    /** После этого часа напоминание о сегодняшнем празднике уже не нужно. */
    private const REMIND_UNTIL_HOUR = 20;

    /** @return array{booked: int, reminded: int} */
    public function run(Carbon $now, bool $dryRun = false): array
    {
        $summary = ['booked' => 0, 'reminded' => 0];
        $themes = collect((array) config('broadcast_digest.themes', []))
            ->filter(fn ($t) => isset($t['occasion']))
            ->values()
            ->all();
        if ($themes === []) {
            return $summary;
        }

        foreach (TelegramChatBroadcast::query()->with('chat')->get() as $broadcast) {
            $chat = $broadcast->chat;
            if (! $broadcast->enabled || $broadcast->period === 'off' || $broadcast->autofill_paused
                || ! $chat?->city_id || ! BroadcastSafety::postingAllowed((int) $chat->telegram_chat_id)) {
                continue;
            }

            foreach ($themes as $theme) {
                if ($this->bookMain($broadcast, $theme, $now, $dryRun)) {
                    $summary['booked']++;
                }
                if ($this->remind($broadcast, $theme, $now, $dryRun)) {
                    $summary['reminded']++;
                }
            }
        }

        return $summary;
    }

    /** Когда выходит пост к празднику: за `lead_days` до дня в `hour`. */
    public static function plannedAt(array $theme, Carbon $day): Carbon
    {
        return $day->copy()->subDays((int) ($theme['lead_days'] ?? 7))->setTime((int) ($theme['hour'] ?? 12), 0);
    }

    /** Подборка к празднику: за `lead_days` в `hour`, а если опоздали — как можно скорее, но не позже чем за день. */
    private function bookMain(TelegramChatBroadcast $broadcast, array $theme, Carbon $now, bool $dryRun): bool
    {
        $day = BroadcastDigestComposer::occasionDate($theme, $now);
        if ($day === null) {
            return false;
        }
        $planned = self::plannedAt($theme, $day);
        $msk = $now->copy()->setTimezone(self::TZ);

        if ($msk->lt($planned->copy()->subDays(self::BOOK_AHEAD_DAYS)) || $msk->gte($day->copy()->subDay())) {
            return false;
        }

        $at = $planned->max($msk->copy()->addMinutes(30));

        // снятую или отклонённую человеком бронь не возвращаем, а черновик без срока, собранный
        // руками, и есть этот пост: ставим на время повода его, а не второй
        $existing = $this->occasionItems($broadcast, $theme, $day)->get();
        if ($existing->isNotEmpty()) {
            $draft = $existing->first(fn (TelegramChatBroadcastItem $i) => $i->publish_at === null
                && $i->status === TelegramChatBroadcastItem::STATUS_PENDING);
            if ($draft === null || $existing->contains(fn (TelegramChatBroadcastItem $i) => $i->publish_at !== null)) {
                return false;
            }
            if (! $dryRun) {
                $draft->publish_at = $at->copy()->utc();
                $draft->save();
                Log::info('broadcast.occasion.draft_scheduled', ['item_id' => $draft->id, 'publish_at' => $at->toIso8601String()]);
            }

            return true;
        }

        if ($dryRun) {
            return true;
        }

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->is_off_grid = true;
        $item->publish_at = $at->copy()->utc();
        $item->digest_meta = ['theme_wanted' => (string) $theme['slug']];
        $item->pick_meta = PickReason::meta(PickReason::BY_DIGEST, 'occasion');
        $item->save();

        Log::info('broadcast.occasion.booked', [
            'broadcast_id' => $broadcast->id, 'item_id' => $item->id,
            'theme' => $theme['slug'], 'publish_at' => $at->toIso8601String(),
        ]);

        return true;
    }

    /**
     * В сам праздник — ответ на вышедшую подборку: что из неё ещё впереди сегодня, по времени.
     * Без вышедшей подборки или без сегодняшних событий в ней напоминать не о чем.
     */
    private function remind(TelegramChatBroadcast $broadcast, array $theme, Carbon $now, bool $dryRun): bool
    {
        $lead = trim((string) ($theme['reminder'] ?? ''));
        $msk = $now->copy()->setTimezone(self::TZ);
        $day = BroadcastDigestComposer::occasionDate($theme, $now);
        if ($lead === '' || $day === null || ! $msk->isSameDay($day) || $msk->hour >= self::REMIND_UNTIL_HOUR) {
            return false;
        }

        $main = $this->occasionItems($broadcast, $theme, $day)
            ->where('status', TelegramChatBroadcastItem::STATUS_POSTED)
            ->whereNotNull('message_id')
            ->whereRaw("digest_meta->>'reply_to' is null")
            ->orderByDesc('posted_at')
            ->first();
        if ($main === null
            || $this->occasionItems($broadcast, $theme, $day)->whereRaw("digest_meta->>'reply_to' = ?", [(string) $main->id])->exists()) {
            return false;
        }

        $events = DB::table('telegram.chat_broadcast_item_events as l')
            ->join('events as e', 'e.id', '=', 'l.event_id')
            ->where('l.item_id', $main->id)
            ->whereNull('e.deleted_at')
            ->where('e.status', 'active')
            ->whereRaw("coalesce(e.time_precision, 'datetime') <> 'date'")
            ->where('e.start_time', '>=', $msk->copy()->addMinutes(30)->utc())
            ->where('e.start_time', '<=', $day->copy()->endOfDay()->utc())
            ->orderBy('e.start_time')
            ->limit(3)
            ->get(['e.id', 'e.title', 'e.start_time']);
        if ($events->isEmpty()) {
            return false;
        }
        if ($dryRun) {
            return true;
        }

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->is_off_grid = true;
        $item->publish_at = $day->copy()->setTime((int) ($theme['reminder_hour'] ?? 12), 0)
            ->max($msk->copy()->addMinutes(5))->utc();
        // ручная подпись: доставка не пересобирает её как подборку, а пустой список картинок — пост без фото
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_MANUAL;
        $item->photo_urls = [];
        $item->digest_meta = [
            'theme' => (string) $theme['slug'],
            'theme_title' => (string) ($theme['title'] ?? $theme['slug']),
            'reply_to' => (int) $main->id,
        ];
        $item->pick_meta = PickReason::meta(PickReason::BY_DIGEST, 'occasion_reminder');
        $item->save();

        $item->caption = $this->reminderText($lead, $events->all(), (int) $item->id);
        $item->save();

        // состав нужен, иначе сборка заранее приняла бы запись за пустую бронь и собрала заново
        foreach ($events->values() as $i => $e) {
            DB::table('telegram.chat_broadcast_item_events')->insertOrIgnore([
                'item_id' => $item->id, 'event_id' => $e->id, 'position' => $i + 1, 'created_at' => now(),
            ]);
        }

        Log::info('broadcast.occasion.reminder', [
            'broadcast_id' => $broadcast->id, 'item_id' => $item->id, 'reply_to' => $main->id, 'events' => $events->count(),
        ]);

        return true;
    }

    /** «Хэллоуин сегодня. В 18:00 — вечеринка в ПараDice, в 19:00 — Кошмар на улице Кирова.» */
    private function reminderText(string $lead, array $events, int $itemId): string
    {
        $base = rtrim((string) (config('app.url') ?: 'https://kudab.ru'), '/');
        $parts = [];
        foreach ($events as $e) {
            $parts[] = 'в '.Carbon::parse($e->start_time)->setTimezone(self::TZ)->format('H:i').' — '
                .'<a href="'.htmlspecialchars(PostLink::utm($base.'/events/'.$e->id, PostLink::MEDIUM_DIGEST, $itemId), ENT_QUOTES).'">'
                .htmlspecialchars(CalmCaps::title((string) $e->title), ENT_NOQUOTES).'</a>';
        }

        $list = implode(', ', $parts);

        return htmlspecialchars($lead, ENT_NOQUOTES).'. '.mb_strtoupper(mb_substr($list, 0, 1)).mb_substr($list, 1).'.';
    }

    /** Подборки этого повода в канале: бронь, черновик, вышедшая и напоминание. Месяц назад — запас на черновик. */
    public function occasionItems(TelegramChatBroadcast $broadcast, array $theme, Carbon $day): \Illuminate\Database\Eloquent\Builder
    {
        $from = $day->copy()->subDays(30)->utc();
        $to = $day->copy()->addDays((int) ($theme['window_after'] ?? 0) + 1)->utc();

        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->whereRaw("coalesce(digest_meta->>'theme', digest_meta->>'theme_wanted') = ?", [(string) $theme['slug']])
            ->whereRaw('coalesce(publish_at, posted_at, created_at) between ? and ?', [$from, $to]);
    }
}
