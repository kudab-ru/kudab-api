<?php

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramChatBroadcastRepositoryInterface;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Постановка «портрета площадки» в очередь рассылки (этап 2 venue-portrait).
 *
 * Портрет едет по СУЩЕСТВУЮЩИМ рельсам событийной рассылки (claim-lease, ревью-
 * гейт, отправка ботом) — этот сервис только НАПОЛНЯЕТ очередь venue-айтемами.
 * Доставку (poll → send → mark) делают TelegramChatBroadcastService::collectDueSingleRuns
 * (ветка kind=venue) и bot-cron.
 *
 * Ключевое требование владельца — НЕ ПОВТОРЯТЬСЯ. Три слоя анти-повтора:
 *  1. Ротация: берём площадку, которая дольше всех не выходила портретом (или ни
 *     разу) — пока не пройдём весь пул, ни одна не повторится.
 *  2. Кулдаун COOLDOWN_DAYS: даже после круга площадка не выйдет повторно раньше.
 *  3. Кросс-формат: не постим портрет площадки, чьё СОБЫТИЕ ушло спотлайтом за
 *     последнюю неделю (одно место не мелькает дважды подряд).
 *  + текст (venues.tg_portrait) перегенерится парсером при новых данных.
 *
 * Каденс — раз в неделю (WEEKLY_COOLDOWN_DAYS с последнего портрета канала).
 */
class TelegramVenuePortraitService
{
    /** Публичный сайт для ссылок в посте. */
    private const SITE = 'https://kudab.ru';

    /** Портрет одной площадки не чаще раза в N дней (анти-повтор). */
    private const COOLDOWN_DAYS = 90;

    /** Кросс-формат: окно, в котором событие площадки блокирует её портрет. */
    private const CROSS_FORMAT_DAYS = 7;

    /** Каденс канала: не чаще одного портрета в N дней (≈ раз в неделю). */
    private const WEEKLY_COOLDOWN_DAYS = 7;

    private const MONTHS = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    public function __construct(
        private readonly TelegramChatBroadcastRepositoryInterface $broadcastRepository,
    ) {}

    /**
     * Наполнить очередь портретами площадок для всех enabled city-каналов, которым
     * пора (недельный каденс) и у которых сейчас пусто (одно в полёте).
     *
     * Идёт из scheduler (broadcast:enqueue-venue-portraits). Дедупликация постов —
     * через ротацию/кулдаун (не через last_run_at — он у событийного расписания).
     *
     * @return array{checked:int,due:int,enqueued:int,skipped_no_city:int,skipped_queue_busy:int,no_candidate:int,skipped_no_reviewer:int}
     */
    public function enqueueDueVenuePortraits(Carbon $now, bool $dryRun = false): array
    {
        $summary = [
            'checked' => 0, 'due' => 0, 'enqueued' => 0,
            'skipped_no_city' => 0, 'skipped_queue_busy' => 0,
            'no_candidate' => 0, 'skipped_no_reviewer' => 0,
        ];

        foreach ($this->broadcastRepository->listEnabledWithSchedule() as $broadcast) {
            $summary['checked']++;

            if (! $this->venuePortraitDue($broadcast->id, $now)) {
                continue;
            }
            $summary['due']++;

            $chat = $broadcast->chat;
            if (! $chat instanceof TelegramChat || ! $chat->city_id || ! $chat->telegram_chat_id) {
                $summary['skipped_no_city']++;

                continue;
            }

            // Одно в полёте: делим очередь с событиями. Пока висит любой незакрытый
            // айтем (событие или прошлый портрет) — не плодим второй.
            if ($this->openItemsCount($broadcast->id) > 0) {
                $summary['skipped_queue_busy']++;

                continue;
            }

            $venue = $this->pickNextVenueForChat((int) $chat->city_id, (int) $broadcast->id, $now);
            if (! $venue) {
                $summary['no_candidate']++;
                Log::info('venue_portrait.enqueue.no_candidate', [
                    'broadcast_id' => $broadcast->id,
                    'city_id' => $chat->city_id,
                    'hint' => 'нет площадки с tg_portrait вне кулдауна/кросс-формата',
                ]);

                continue;
            }

            $caption = $this->buildVenueCaption($venue, $now);
            $photoUrl = $this->venueCoverUrl((int) $venue->id);

            $reviewGate = (bool) config('services.bot.broadcast_review_gate');
            if ($reviewGate) {
                $reviewerTelegramId = $chat->owner?->telegram_id;
                if (! $reviewerTelegramId) {
                    $summary['skipped_no_reviewer']++;

                    continue;
                }
                if (! $dryRun) {
                    $deadline = $now->copy()->addMinutes(
                        (int) config('services.bot.broadcast_review_timeout_minutes', 120),
                    );
                    $this->persist($broadcast->id, $venue->id, $caption, $photoUrl, [
                        'status' => TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                        'review_reviewer_telegram_id' => (int) $reviewerTelegramId,
                        'review_deadline_at' => $deadline,
                    ]);
                }
            } elseif (! $dryRun) {
                $this->persist($broadcast->id, $venue->id, $caption, $photoUrl, [
                    'status' => TelegramChatBroadcastItem::STATUS_PENDING,
                ]);
            }

            $summary['enqueued']++;
        }

        return $summary;
    }

    /**
     * Пора ли каналу постить портрет: последний портрет постнут ≥ недели назад
     * (или ни разу). Каденс независим от событийного last_run_at.
     */
    private function venuePortraitDue(int $broadcastId, Carbon $now): bool
    {
        $last = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->where('kind', TelegramChatBroadcastItem::KIND_VENUE)
            ->where('status', TelegramChatBroadcastItem::STATUS_POSTED)
            ->max('posted_at');

        if (! $last) {
            return true;
        }

        return Carbon::parse($last)->lt($now->copy()->subDays(self::WEEKLY_COOLDOWN_DAYS));
    }

    /** Незакрытые айтемы канала (любого типа) — «в полёте». */
    private function openItemsCount(int $broadcastId): int
    {
        return (int) TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ])
            ->count();
    }

    /**
     * Выбрать следующую площадку для портрета (ротация + кулдаун + кросс-формат).
     * Возвращает площадку, которая дольше всех не выходила (или ни разу).
     */
    public function pickNextVenueForChat(int $cityId, int $broadcastId, Carbon $now): ?Venue
    {
        // 1) на кулдауне: постились портретом за COOLDOWN_DAYS
        $onCooldown = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->where('kind', TelegramChatBroadcastItem::KIND_VENUE)
            ->where('status', TelegramChatBroadcastItem::STATUS_POSTED)
            ->where('posted_at', '>=', $now->copy()->subDays(self::COOLDOWN_DAYS))
            ->whereNotNull('venue_id')
            ->pluck('venue_id')->all();

        // 2) кросс-формат: чьё событие ушло спотлайтом за неделю
        $recentEventVenues = TelegramChatBroadcastItem::query()
            ->from('telegram.chat_broadcast_items as i')
            ->join('events as e', 'e.id', '=', 'i.event_id')
            ->where('i.broadcast_id', $broadcastId)
            ->where('i.kind', TelegramChatBroadcastItem::KIND_EVENT)
            ->where('i.status', TelegramChatBroadcastItem::STATUS_POSTED)
            ->where('i.posted_at', '>=', $now->copy()->subDays(self::CROSS_FORMAT_DAYS))
            ->whereNotNull('e.venue_id')
            ->pluck('e.venue_id')->all();

        $exclude = array_values(array_unique(array_merge(
            array_map('intval', $onCooldown),
            array_map('intval', $recentEventVenues),
        )));

        // последняя дата портрета по каждой площадке — для ротации (кто дольше молчал)
        $lastPortraitAt = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->where('kind', TelegramChatBroadcastItem::KIND_VENUE)
            ->where('status', TelegramChatBroadcastItem::STATUS_POSTED)
            ->whereNotNull('venue_id')
            ->groupBy('venue_id')
            ->selectRaw('venue_id, MAX(posted_at) as last_at')
            ->pluck('last_at', 'venue_id');

        $eligible = Venue::query()
            ->active()
            ->where('city_id', $cityId)
            ->whereNotNull('tg_portrait')
            ->where('tg_portrait', '<>', '')
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('id', $exclude))
            ->get(['id', 'name', 'tg_portrait']);

        if ($eligible->isEmpty()) {
            return null;
        }

        // ротация: сначала ни разу не постнутые (ключ '0'), затем по дате портрета asc
        return $eligible
            ->sortBy(fn (Venue $v) => (string) ($lastPortraitAt[$v->id] ?? '0'))
            ->first();
    }

    /**
     * Готовый текст поста: 🏛 имя + живая проза (tg_portrait) + «ближайшее тут»
     * (у живых площадок) / зов в афишу (у площадок без будущего).
     */
    public function buildVenueCaption(Venue $venue, Carbon $now): string
    {
        $lines = [
            '🏛 <b>'.$this->esc((string) $venue->name).'</b>',
            '',
            $this->esc(trim((string) $venue->tg_portrait)),
            '',
        ];

        $next = $this->nextEvent((int) $venue->id, $now);
        if ($next) {
            $lines[] = '🎟 <b>Ближайшее:</b> <a href="'.self::SITE.'/events/'.$next->id.'">'.$this->esc((string) $next->title).'</a>';
            $lines[] = '🗓 '.$this->ruDate($next->start_time);
            $lines[] = '';
            $lines[] = '📅 <a href="'.self::SITE.'/venues/'.$venue->id.'">Все события площадки →</a>';
        } else {
            $lines[] = '📅 <a href="'.self::SITE.'/venues/'.$venue->id.'">Афиша площадки →</a>';
        }

        return implode("\n", $lines);
    }

    /** Экранирование динамики для HTML parse_mode Telegram (только < > &). */
    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
    }

    /** Ближайшее будущее видимое событие площадки. */
    private function nextEvent(int $venueId, Carbon $now): ?Event
    {
        return Event::query()
            ->active()
            ->upcoming()
            ->where('venue_id', $venueId)
            ->whereNotNull('start_time')
            ->orderBy('start_time')
            ->first(['id', 'title', 'start_time']);
    }

    /**
     * До $limit РАЗНЫХ обложек-прокси из событий площадки — для альбома в посте
     * (своих фото у venue нет, берём первые картинки её событий, дедуп по URL).
     *
     * @return list<string>
     */
    public function venuePhotoUrls(int $venueId, int $limit = 4): array
    {
        $urls = DB::table('events as e')
            ->join('event_sources as es', 'es.event_id', '=', 'e.id')
            ->where('e.venue_id', $venueId)
            ->whereNull('e.deleted_at')
            ->whereNotNull('es.images')
            ->whereRaw('json_array_length(es.images) > 0')
            ->orderByRaw('e.start_time DESC NULLS LAST')
            ->limit(max(1, $limit) * 4)
            ->selectRaw('es.images->>0 as url')
            ->pluck('url');

        $seen = [];
        $out = [];
        foreach ($urls as $u) {
            $u = (string) $u;
            if ($u === '' || isset($seen[$u])) {
                continue;
            }
            $seen[$u] = true;
            $out[] = $u;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** Одна обложка (совместимость / ревью-превью). */
    private function venueCoverUrl(int $venueId): ?string
    {
        return $this->venuePhotoUrls($venueId, 1)[0] ?? null;
    }

    private function ruDate(mixed $ts): string
    {
        $c = Carbon::parse($ts)->setTimezone('Europe/Moscow');

        return $c->day.' '.self::MONTHS[$c->month].' в '.$c->format('H:i');
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function persist(int $broadcastId, int $venueId, string $caption, ?string $photoUrl, array $attrs): TelegramChatBroadcastItem
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcastId;
        $item->kind = TelegramChatBroadcastItem::KIND_VENUE;
        $item->venue_id = $venueId;
        $item->caption = $caption;
        $item->photo_url = $photoUrl;
        foreach ($attrs as $k => $v) {
            $item->{$k} = $v;
        }
        $item->save();

        return $item;
    }

    /**
     * РУЧНАЯ постановка портрета конкретной площадки (админ-триггер: бот/панель/CLI).
     *
     * Игнорирует недельный каденс (постим сейчас, on-demand), НО уважает защиту
     * «одно в полёте» (без $force): пока в очереди висит незакрытый пост — второй
     * не ставим. Это и есть «таймаут» — ручной пост не приведёт к двойному.
     * После отправки posted_at площадки обновится → авто-каденс сдвинется на неделю.
     */
    public function enqueueVenueManually(
        int $broadcastId,
        int $venueId,
        Carbon $now,
        bool $force = false,
        bool $reviewGate = false,
        ?int $reviewerTelegramId = null,
    ): TelegramChatBroadcastItem {
        if (! $force && $this->openItemsCount($broadcastId) > 0) {
            throw new RuntimeException('В очереди уже есть незакрытый пост — дождитесь отправки (защита от двойного поста). --force чтобы всё равно.');
        }

        $venue = Venue::query()->active()->whereKey($venueId)->first();
        if (! $venue) {
            throw new RuntimeException("Площадка #{$venueId} не найдена или не активна.");
        }
        if (trim((string) $venue->tg_portrait) === '') {
            throw new RuntimeException("У «{$venue->name}» нет tg_portrait — сначала parser:tg:venue-portrait --venue={$venueId} --save.");
        }

        $caption = $this->buildVenueCaption($venue, $now);
        $photo = $this->venueCoverUrl($venueId);

        $attrs = ['status' => TelegramChatBroadcastItem::STATUS_PENDING];
        if ($reviewGate) {
            if (! $reviewerTelegramId) {
                throw new RuntimeException('Ревью-гейт включён, но у канала нет owner для превью.');
            }
            $attrs = [
                'status' => TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                'review_reviewer_telegram_id' => $reviewerTelegramId,
                'review_deadline_at' => $now->copy()->addMinutes((int) config('services.bot.broadcast_review_timeout_minutes', 120)),
            ];
        }

        return $this->persist($broadcastId, $venue->id, $caption, $photo, $attrs);
    }
}
