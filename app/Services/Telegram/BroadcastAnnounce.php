<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Support\BroadcastSafety;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * «Объявили»: крупный концерт или спектакль, который появился в афише за последние сутки, —
 * пост в тот же день, вне сетки. Это отдельная запись с одним событием в составе, а не пост
 * события: тот у события один на канал и нужен ближе к дате.
 */
final class BroadcastAnnounce
{
    private const TZ = 'Europe/Moscow';

    /** То, на что билеты берут заранее. */
    private const KINDS = ['music', 'theatre', 'standup', 'festival', 'circus-show'];

    /** Событие считаем новым, если впервые увидели его не раньше стольких часов назад. */
    private const FRESH_HOURS = 36;

    /** Ближе к дате это уже не новость, а обычный пост. */
    private const AHEAD_DAYS = 21;

    /** Столько новых сразу бывает только у только что подключённого источника: это старая афиша, а не новости. */
    private const BURST = 25;

    private const PER_WEEK = 3;

    /** В эти часы ставим пост: между постами сетки 10:00, 15:00 и 19:00. */
    private const HOURS = [12, 16];

    public function __construct(
        private readonly TelegramChatBroadcastService $broadcasts,
        private readonly EventCaptionBuilder $captions,
        private readonly Scoring\EventBroadcastScorer $scorer,
    ) {}

    /** @return array{announced: int, burst: int} */
    public function run(Carbon $now, bool $dryRun = false): array
    {
        $summary = ['announced' => 0, 'burst' => 0];
        if (! in_array((int) $now->copy()->setTimezone(self::TZ)->hour, self::HOURS, true)) {
            return $summary;
        }

        foreach (TelegramChatBroadcast::query()->with('chat')->get() as $broadcast) {
            $chat = $broadcast->chat;
            if (! $broadcast->enabled || $broadcast->period === 'off' || $broadcast->autofill_paused
                || ! $chat instanceof TelegramChat || ! $chat->city_id
                || ! BroadcastSafety::postingAllowed((int) $chat->telegram_chat_id)
                || ! $this->roomFor($broadcast, $now)) {
                continue;
            }

            $candidates = $this->candidates($chat, $broadcast, $now);
            if ($candidates->count() > self::BURST) {
                $summary['burst']++;
                Log::info('broadcast.announce.burst', ['broadcast_id' => $broadcast->id, 'candidates' => $candidates->count()]);

                continue;
            }

            $best = $this->best($candidates->all());
            if ($best === null) {
                continue;
            }
            if (! $dryRun) {
                try {
                    // запись, подпись и состав — вместе: без подписи пост уйти не должен
                    DB::transaction(fn () => $this->announce($broadcast, $best[0], $best[1], $now));
                } catch (\Throwable $e) {
                    Log::warning('broadcast.announce.failed', ['broadcast_id' => $broadcast->id, 'event_id' => $best[0]->id, 'error' => $e->getMessage()]);

                    continue;
                }
            }
            $summary['announced']++;
        }

        return $summary;
    }

    /** Не больше одного в день и `PER_WEEK` в неделю. */
    private function roomFor(TelegramChatBroadcast $broadcast, Carbon $now): bool
    {
        $recent = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->whereRaw("digest_meta->>'theme' = ?", [TelegramChatBroadcastItem::THEME_ANNOUNCE])
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->pluck('created_at');

        $today = $now->copy()->setTimezone(self::TZ)->startOfDay();

        return $recent->count() < self::PER_WEEK
            && ! $recent->contains(fn ($at) => Carbon::parse($at)->gte($today));
    }

    /** @return \Illuminate\Support\Collection<int, Event> */
    private function candidates(TelegramChat $chat, TelegramChatBroadcast $broadcast, Carbon $now): \Illuminate\Support\Collection
    {
        $kinds = array_values(array_unique(array_merge(...array_map(
            fn ($slug) => BroadcastDigestComposer::interestTree($slug),
            self::KINDS,
        ))));
        $fresh = $now->copy()->subHours(self::FRESH_HOURS);

        return $this->broadcasts->channelCandidates($chat, (int) $broadcast->id)
            ->with('venue:id,name')
            ->where('events.created_at', '>=', $fresh)
            ->where('events.start_time', '>=', $now->copy()->addDays(self::AHEAD_DAYS))
            ->whereNotNull('events.venue_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('event_interest as ei')
                ->whereColumn('ei.event_id', 'events.id')
                ->where('ei.rank', 0)
                ->whereIn('ei.interest_id', $kinds ?: [0]))
            // те же правила платного, что у «Билетов заранее»
            ->whereNotIn('events.price_status', ['free', 'donation'])
            ->where(fn ($w) => $w->where('events.price_min', '>', 0)
                ->orWhere('events.price_status', 'external')
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('event_sources as es')
                    ->whereColumn('es.event_id', 'events.id')
                    ->whereIn('es.source', Scoring\EventInterestSignals::TICKET_SOURCES)))
            // своего поста объявление не занимает (`whereClaimsEvents`), поэтому объявленное отсекаем здесь —
            // вместе с другими датами того же шоу
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('telegram.chat_broadcast_item_events as l')
                ->join('telegram.chat_broadcast_items as i', 'i.id', '=', 'l.item_id')
                ->join('events as told', 'told.id', '=', 'l.event_id')
                ->where('i.broadcast_id', $broadcast->id)
                ->whereRaw("i.digest_meta->>'theme' = ?", [TelegramChatBroadcastItem::THEME_ANNOUNCE])
                ->where(fn ($w) => $w->whereColumn('told.id', 'events.id')
                    ->orWhereColumn('told.event_group_id', 'events.event_group_id')
                    ->orWhere(fn ($x) => $x->whereColumn('told.venue_id', 'events.venue_id')
                        ->whereRaw('lower(told.title) = lower(events.title)'))))
            // уже знали это шоу под другой датой или другой карточкой — тогда не новость
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('events as known')
                ->whereColumn('known.id', '<>', 'events.id')
                ->where('known.created_at', '<', $fresh)
                ->where(fn ($w) => $w->whereColumn('known.event_group_id', 'events.event_group_id')
                    ->orWhere(fn ($x) => $x->whereColumn('known.venue_id', 'events.venue_id')
                        ->whereRaw('lower(known.title) = lower(events.title)'))))
            ->limit(self::BURST + 1)
            ->get();
    }

    /**
     * Самое интересное с картинкой; при равной оценке — дороже билет, это и есть крупное событие.
     *
     * @param  list<Event>  $events
     * @return array{0: Event, 1: list<string>}|null
     */
    private function best(array $events): ?array
    {
        $ranked = $this->scorer->rankRows($events);
        usort($ranked, fn ($a, $b) => [$this->scorer->interest($b), (int) ($b->price_min ?? 0)]
            <=> [$this->scorer->interest($a), (int) ($a->price_min ?? 0)]);

        foreach ($ranked as $event) {
            $photos = $this->broadcasts->eventPhotos((int) $event->id);
            if ($photos !== []) {
                return [$event, $photos];
            }
        }

        return null;
    }

    /** @param  list<string>  $photos */
    private function announce(TelegramChatBroadcast $broadcast, Event $event, array $photos, Carbon $now): void
    {
        $at = $now->copy()->addMinutes(10)->startOfMinute();

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->is_off_grid = true;
        $item->publish_at = $at;
        // ручная подпись: доставка не пересобирает её как подборку
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_MANUAL;
        $item->photo_urls = $photos;
        $item->digest_meta = ['theme' => TelegramChatBroadcastItem::THEME_ANNOUNCE, 'theme_title' => 'Объявили'];
        $item->pick_meta = PickReason::meta(PickReason::BY_AUTO, 'announce');
        // анонс события пишет модель (parser:tg:describe-due), пост ждёт его, как «отправить сейчас»
        if ($broadcast->ai_text && trim((string) $event->tg_description) === '') {
            $item->text_requested_at = $now;
            $item->planned_at = $now->copy()->addMinutes(max(1, (int) config('services.bot.broadcast_text_grace_minutes', 6)));
        }
        $item->save();

        $caption = $this->captions->build(
            $event,
            $broadcast->templateCodeForDate($at),
            CarbonImmutable::parse($at)->setTimezone(self::TZ),
            (int) $item->id,
        );
        $item->caption = self::headed($caption, (string) $event->title);
        $item->save();

        // состав нужен, иначе сборка заранее приняла бы запись за пустую бронь подборки
        DB::table('telegram.chat_broadcast_item_events')->insertOrIgnore([
            'item_id' => $item->id, 'event_id' => $event->id, 'position' => 1, 'created_at' => now(),
        ]);

        Log::info('broadcast.announce.queued', [
            'broadcast_id' => $broadcast->id, 'item_id' => $item->id, 'event_id' => $event->id,
            'publish_at' => $at->toIso8601String(),
        ]);
    }

    /** «Объявили:» перед названием в первой жирной строке; шаблон без неё — отдельной строкой сверху. */
    public static function headed(string $caption, string $title): string
    {
        $bold = '<b>'.htmlspecialchars($title, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $at = mb_strpos($caption, $bold);

        return $at === false
            ? "<b>Объявили</b>\n".$caption
            : mb_substr($caption, 0, $at).'<b>Объявили: '.mb_substr($caption, $at + 3);
    }
}
