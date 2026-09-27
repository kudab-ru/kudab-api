<?php

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramChatBroadcastRepositoryInterface;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\Venue;
use App\Support\BroadcastSafety;
use App\Support\Telegram\VenueName;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Ставит портреты площадок в очередь рассылки. Доставка общая с событиями:
 * TelegramChatBroadcastService::collectDueSingleRuns, ветка kind=venue.
 */
class TelegramVenuePortraitService
{
    /** Публичный сайт для ссылок в посте. */
    private const SITE = 'https://kudab.ru';

    /** Портрет одной площадки не чаще раза в N дней (анти-повтор). */
    private const COOLDOWN_DAYS = 90;

    /** Кросс-формат: окно, в котором событие площадки блокирует её портрет. */
    private const CROSS_FORMAT_DAYS = 7;

    /** Площадок в пуле на один портрет в неделю: каждая после выхода заперта на COOLDOWN_DAYS. */
    public const POOL_PER_WEEKLY_POST = self::COOLDOWN_DAYS / 7;

    /**
     * Сколько дней не предлагать площадку после отклонённого, снятого или упавшего
     * портрета. Столько же, сколько TelegramChatBroadcastService::REJECTED_COOLDOWN_DAYS.
     */
    private const REFUSED_COOLDOWN_DAYS = 30;

    /** Сколько картинок уходит альбомом у портрета и подборки. Читается и доставкой, и админкой. */
    public const ALBUM_LIMIT = 4;

    /** Название+адрес ≤ этой длины (символов) — склеиваем в одну строку шапки. */
    private const HEADER_ONE_LINE_MAX = 42;

    private const MONTHS = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    public function __construct(
        private readonly TelegramChatBroadcastRepositoryInterface $broadcastRepository,
        private readonly BroadcastSlotPlanner $slotPlanner,
    ) {}

    /**
     * @return array{checked:int,due:int,enqueued:int,skipped_no_city:int,skipped_queue_busy:int,no_candidate:int,skipped_no_reviewer:int}
     */
    public function enqueueDueVenuePortraits(Carbon $now, bool $dryRun = false): array
    {
        $summary = [
            'checked' => 0, 'due' => 0, 'enqueued' => 0,
            'skipped_no_city' => 0, 'skipped_queue_busy' => 0,
            'no_candidate' => 0, 'skipped_no_reviewer' => 0,
            'skipped_not_allowed' => 0, 'skipped_no_slot' => 0,
        ];

        foreach ($this->broadcastRepository->listEnabledWithSchedule() as $broadcast) {
            $summary['checked']++;

            if (! $this->venuePortraitDue($broadcast, $now)) {
                continue;
            }
            $summary['due']++;

            $chat = $broadcast->chat;
            if (! $chat instanceof TelegramChat || ! $chat->city_id || ! $chat->telegram_chat_id) {
                $summary['skipped_no_city']++;

                continue;
            }

            if (! BroadcastSafety::postingAllowed((int) $chat->telegram_chat_id)) {
                $summary['skipped_not_allowed']++;

                continue;
            }

            if ($this->openVenueItemsCount($broadcast->id) > 0) {
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

            // нет свободного слота — ждём следующего прогона, мимо расписания не ставим
            $publishAt = $this->slotPlanner->nextFreeSlot($broadcast, $now);
            if (! $publishAt) {
                $summary['skipped_no_slot']++;
                Log::info('venue_portrait.enqueue.no_free_slot', [
                    'broadcast_id' => $broadcast->id,
                    'venue_id' => $venue->id,
                ]);

                continue;
            }

            $caption = $this->buildVenueCaption($venue, $publishAt);
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
                        'publish_at' => $publishAt->copy()->utc(),
                    ], captionAt: $publishAt);
                }
            } elseif (! $dryRun) {
                $this->persist($broadcast->id, $venue->id, $caption, $photoUrl, [
                    'status' => TelegramChatBroadcastItem::STATUS_PENDING,
                    'publish_at' => $publishAt->copy()->utc(),
                ], captionAt: $publishAt);
            }

            $summary['enqueued']++;
        }

        return $summary;
    }

    /** Каденс portrait_every_days от последнего отправленного портрета, не от last_run_at: тот у событий. */
    private function venuePortraitDue(TelegramChatBroadcast $broadcast, Carbon $now): bool
    {
        $last = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_VENUE)
            ->where('status', TelegramChatBroadcastItem::STATUS_POSTED)
            ->max('posted_at');

        if (! $last) {
            return true;
        }

        return Carbon::parse($last)->lt($now->copy()->subDays($broadcast->portrait_every_days));
    }

    private function broadcastOf(int $broadcastId): TelegramChatBroadcast
    {
        return TelegramChatBroadcast::query()->findOrFail($broadcastId);
    }

    /**
     * Открытые записи портретов канала. Событийные не считаем: в ленте они
     * есть почти всегда, и портрет не встал бы в очередь никогда.
     */
    private function openVenueItemsCount(int $broadcastId): int
    {
        return (int) TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->where('kind', TelegramChatBroadcastItem::KIND_VENUE)
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
        $onCooldown = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->where('kind', TelegramChatBroadcastItem::KIND_VENUE)
            ->where('status', TelegramChatBroadcastItem::STATUS_POSTED)
            ->where('posted_at', '>=', $now->copy()->subDays(self::COOLDOWN_DAYS))
            ->whereNotNull('venue_id')
            ->pluck('venue_id')->all();

        $recentlyRefused = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->where('kind', TelegramChatBroadcastItem::KIND_VENUE)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_REJECTED,
                TelegramChatBroadcastItem::STATUS_SKIPPED,
                TelegramChatBroadcastItem::STATUS_ERROR,
            ])
            ->where('updated_at', '>=', $now->copy()->subDays(self::REFUSED_COOLDOWN_DAYS))
            ->whereNotNull('venue_id')
            ->pluck('venue_id')->all();

        // площадки, чьё событие было в канале за CROSS_FORMAT_DAYS или стоит в открытой ленте
        $recentEventVenues = TelegramChatBroadcastItem::query()
            ->from('telegram.chat_broadcast_items as i')
            // через таблицу связи, без фильтра по kind: площадка, названная
            // подборкой, тоже считается прозвучавшей
            ->join('telegram.chat_broadcast_item_events as l', 'l.item_id', '=', 'i.id')
            ->join('events as e', 'e.id', '=', 'l.event_id')
            ->where('i.broadcast_id', $broadcastId)
            ->where(function ($q) use ($now) {
                $q->where(function ($w) use ($now) {
                    $w->where('i.status', TelegramChatBroadcastItem::STATUS_POSTED)
                        ->where('i.posted_at', '>=', $now->copy()->subDays(self::CROSS_FORMAT_DAYS));
                })->orWhereIn('i.status', [
                    TelegramChatBroadcastItem::STATUS_PENDING,
                    TelegramChatBroadcastItem::STATUS_PLANNED,
                    TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                    TelegramChatBroadcastItem::STATUS_APPROVED,
                    TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
                ]);
            })
            ->whereNotNull('e.venue_id')
            ->pluck('e.venue_id')->all();

        // открытый портрет этой площадки: иначе пул предложит её второй раз
        $alreadyQueued = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->where('kind', TelegramChatBroadcastItem::KIND_VENUE)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ])
            ->whereNotNull('venue_id')
            ->pluck('venue_id')->all();

        $exclude = array_values(array_unique(array_merge(
            array_map('intval', $onCooldown),
            array_map('intval', $recentlyRefused),
            array_map('intval', $recentEventVenues),
            array_map('intval', $alreadyQueued),
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
            // без фото в ротацию не берём: портрет ушёл бы голым текстом.
            // условие то же, что в venuePhotoUrls
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('events as e')
                    ->join('event_sources as es', 'es.event_id', '=', 'e.id')
                    ->whereColumn('e.venue_id', 'venues.id')
                    ->whereNull('e.deleted_at')
                    ->whereNotNull('es.images')
                    ->whereRaw('json_array_length(es.images) > 0');
            })
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('id', $exclude))
            ->get(['id', 'name', 'tg_portrait', 'street', 'house', 'address', 'latitude', 'longitude']);

        if ($eligible->isEmpty()) {
            return null;
        }

        // ротация: сначала ни разу не постнутые (ключ '0'), затем по дате портрета asc
        return $eligible
            ->sortBy(fn (Venue $v) => (string) ($lastPortraitAt[$v->id] ?? '0'))
            ->first();
    }

    /**
     * @param  int|null  $itemId  id записи для utm-меток; null — записи ещё нет, см. persist
     */
    public function buildVenueCaption(Venue $venue, Carbon $now, ?int $itemId = null): string
    {
        $label = VenueName::label($venue->name);
        $name = $this->esc($label);
        $addr = $this->shortAddress($venue);
        $addrLink = $addr !== '' ? '📍 <a href="'.$this->mapsUrl($venue).'">'.$this->esc($addr).'</a>' : '';

        if ($addrLink !== '' && (mb_strlen($label) + mb_strlen($addr)) <= self::HEADER_ONE_LINE_MAX) {
            $lines = ['🏛 <b>'.$name.'</b>  ·  '.$addrLink];
        } else {
            $lines = ['🏛 <b>'.$name.'</b>'];
            if ($addrLink !== '') {
                $lines[] = $addrLink;
            }
        }

        $lines[] = '';
        $lines[] = $this->esc(trim((string) $venue->tg_portrait));
        $lines[] = '';

        $next = $this->nextEvent((int) $venue->id, $now);
        if ($next) {
            $lines[] = '🎟 <b>Ближайшее:</b> <a href="'
                .PostLink::utm(self::SITE.'/events/'.$next->id, PostLink::MEDIUM_VENUE, $itemId)
                .'">'.$this->esc((string) $next->title).'</a>';
            $lines[] = '🗓 '.$this->ruDate($next->start_time);
            $lines[] = '';
            $lines[] = '📅 <a href="'
                .PostLink::utm(self::SITE.'/venues/'.$venue->id, PostLink::MEDIUM_VENUE, $itemId)
                .'">Все события площадки</a>';
        } else {
            $lines[] = '📅 <a href="'
                .PostLink::utm(self::SITE.'/venues/'.$venue->id, PostLink::MEDIUM_VENUE, $itemId)
                .'">Афиша площадки</a>';
        }

        return implode("\n", $lines);
    }

    /** Экранирование динамики для HTML parse_mode Telegram (только < > &). */
    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8');
    }

    /** Короткий адрес: улица+дом; иначе адрес без индекса/области/города, обрезанный. */
    private function shortAddress(Venue $venue): string
    {
        $street = trim((string) ($venue->street ?? ''));
        $house = trim((string) ($venue->house ?? ''));
        if ($street !== '') {
            return $house !== '' ? $street.', '.$house : $street;
        }
        $addr = trim((string) ($venue->address ?? ''));
        if ($addr === '') {
            return '';
        }
        $addr = (string) preg_replace('/^\s*\d{5,6}\s*,\s*/u', '', $addr);          // индекс
        $addr = (string) preg_replace('/^[^,]*\bобл[^,]*,\s*/ui', '', $addr);        // область
        $addr = (string) preg_replace('/^\s*г\.?\s+[^,]+,\s*/ui', '', $addr);        // город
        $addr = trim($addr, ' ,');

        return mb_strlen($addr) > 60 ? mb_substr($addr, 0, 57).'…' : $addr;
    }

    private function mapsUrl(Venue $venue): string
    {
        if ($venue->latitude !== null && $venue->longitude !== null) {
            $ll = ((string) $venue->longitude).','.((string) $venue->latitude);

            return 'https://yandex.ru/maps/?ll='.$ll.'&z=17&pt='.$ll;
        }
        $q = trim((string) ($venue->address ?: $venue->name));

        return 'https://yandex.ru/maps/?text='.rawurlencode($q);
    }

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
     * До $limit разных картинок для альбома. Своих фото у площадки нет, берём
     * первую картинку её событий. Условие то же, что в фильтре pickNextVenueForChat.
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
    private function persist(
        int $broadcastId,
        int $venueId,
        string $caption,
        ?string $photoUrl,
        array $attrs,
        ?Carbon $captionAt = null,
    ): TelegramChatBroadcastItem {
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

        // id для utm-меток появляется только после save, поэтому подпись собираем второй раз
        if ($captionAt !== null) {
            $venue = Venue::query()->find($venueId);
            if ($venue) {
                $item->caption = $this->buildVenueCaption($venue, $captionAt, (int) $item->id);
                $item->save();
            }
        }

        return $item;
    }

    /**
     * Следующая площадка ротации как карточка пула для пустого слота.
     *
     * @return array{venue_id: int, name: string, cover: ?string, photos_count: int, weeks_since: ?int}|null
     */
    public function nextPortraitSuggestion(TelegramChatBroadcast $broadcast, Carbon $now): ?array
    {
        $chat = $broadcast->chat;
        if (! $chat instanceof TelegramChat || ! $chat->city_id) {
            return null;
        }

        $venue = $this->pickNextVenueForChat((int) $chat->city_id, (int) $broadcast->id, $now);
        if (! $venue) {
            return null;
        }

        $lastPosted = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_VENUE)
            ->where('status', TelegramChatBroadcastItem::STATUS_POSTED)
            ->where('venue_id', $venue->id)
            ->max('posted_at');

        $photos = $this->venuePhotoUrls((int) $venue->id, self::ALBUM_LIMIT);

        return [
            'venue_id' => (int) $venue->id,
            'name' => (string) $venue->name,
            'cover' => $photos[0] ?? $this->venueCoverUrl((int) $venue->id),
            'photos_count' => count($photos),
            'weeks_since' => $lastPosted
                ? (int) floor(Carbon::parse($lastPosted)->diffInDays($now) / 7)
                : null,
        ];
    }

    /**
     * Ручная постановка портрета (админка, бот, CLI): каденс не проверяет, берёт
     * ближайший свободный слот. Без $force не ставит второй открытый портрет.
     */
    public function enqueueVenueManually(
        int $broadcastId,
        int $venueId,
        Carbon $now,
        bool $force = false,
        bool $reviewGate = false,
        ?int $reviewerTelegramId = null,
    ): TelegramChatBroadcastItem {
        if (! $force && $this->openVenueItemsCount($broadcastId) > 0) {
            throw new RuntimeException('В очереди уже есть незакрытый портрет площадки — дождитесь отправки (защита от двойного поста). --force чтобы всё равно.');
        }

        $venue = Venue::query()->active()->whereKey($venueId)->first();
        if (! $venue) {
            throw new RuntimeException("Площадка #{$venueId} не найдена или не активна.");
        }
        if (trim((string) $venue->tg_portrait) === '') {
            throw new RuntimeException("У «{$venue->name}» нет tg_portrait — сначала parser:tg:venue-portrait --venue={$venueId} --save.");
        }

        // respectLead = false: правило позднего слота только для автомата, см. nextFreeSlot
        $publishAt = $this->slotPlanner->nextFreeSlot($this->broadcastOf($broadcastId), $now, false);
        if (! $publishAt) {
            throw new RuntimeException(
                'Неделя занята целиком: свободного слота нет. '
                .'Перетащите портрет на нужный день — он вытеснит стоящий там пост, '
                .'либо расширьте горизонт ленты в настройках.',
            );
        }

        $caption = $this->buildVenueCaption($venue, $publishAt);
        $photo = $this->venueCoverUrl($venueId);

        $attrs = [
            'status' => TelegramChatBroadcastItem::STATUS_PENDING,
            'publish_at' => $publishAt->copy()->utc(),
        ];
        if ($reviewGate) {
            if (! $reviewerTelegramId) {
                throw new RuntimeException('Ревью-гейт включён, но у канала нет owner для превью.');
            }
            $attrs = [
                'status' => TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                'review_reviewer_telegram_id' => $reviewerTelegramId,
                'review_deadline_at' => $now->copy()->addMinutes((int) config('services.bot.broadcast_review_timeout_minutes', 120)),
                'publish_at' => $publishAt->copy()->utc(),
            ];
        }

        return $this->persist($broadcastId, $venue->id, $caption, $photo, $attrs, captionAt: $publishAt);
    }
}
