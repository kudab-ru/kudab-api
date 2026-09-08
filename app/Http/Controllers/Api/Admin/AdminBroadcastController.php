<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Services\Telegram\EventCaptionBuilder;
use App\Services\Telegram\TelegramChatBroadcastService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Управление телеграм-рассылкой из админки.
 *
 * ЗАЧЕМ ОТДЕЛЬНАЯ ГРУППА, А НЕ /api/bot/broadcast/*. Те ручки авторизуются
 * общим токеном бота, а права считают по telegram_id оператора в теле запроса.
 * Ходить в них из админки значило бы действовать «от имени» конкретного
 * телеграм-пользователя и хранить где-то его id. Здесь обычная админская
 * авторизация: auth:sanctum + role:admin|superadmin, как у остальных разделов.
 *
 * Что здесь есть и чего не было нигде: лента канала на неделю вперёд,
 * закрепление поста, правка текста, перенос даты и пул предложений с
 * причинами. Раньше очередь снаружи можно было только пропустить (skip) да
 * одобрить/отклонить в ревью.
 */
class AdminBroadcastController extends Controller
{
    /** Сколько предложений отдавать в пул за раз. */
    private const SUGGESTIONS_LIMIT = 40;

    public function __construct(
        private readonly TelegramChatBroadcastService $broadcasts,
        private readonly EventCaptionBuilder $captions,
    ) {}

    /** Каналы со сводкой: что в ленте, когда последний пост, молчит ли. */
    public function channels(): JsonResponse
    {
        // Шаблоны отдаём вместе с каналами, чтобы админка не зашивала их
        // список у себя: он живёт в telegram.message_templates.

        // Порядок стабильный: без него список приходил как ляжет, и в
        // интерфейсе первым оказывался выключенный канал.
        $rows = TelegramChatBroadcast::query()->with('chat')->orderBy('id')->get();

        $templates = \App\Models\TelegramMessageTemplate::query()
            ->where('locale', 'ru')
            ->where('is_active', true)
            ->orderBy('code')
            ->pluck('code')
            ->all();

        return response()->json([
            'data' => $rows->map(fn (TelegramChatBroadcast $b) => $this->channelPayload($b))->values(),
            'meta' => ['templates' => $templates],
        ]);
    }

    /** Лента канала: что стоит в очереди и что ушло за последние дни. */
    public function feed(int $broadcastId): JsonResponse
    {
        $broadcast = TelegramChatBroadcast::query()->with('chat')->findOrFail($broadcastId);

        $items = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where(function ($q) {
                $q->whereIn('status', $this->openStatuses())
                    ->orWhere(function ($w) {
                        $w->where('status', TelegramChatBroadcastItem::STATUS_POSTED)
                            ->where('posted_at', '>=', now()->subDays(7));
                    });
            })
            ->orderByRaw('COALESCE(publish_at, planned_at, posted_at, created_at) ASC')
            ->get();

        $events = Event::query()
            ->with('venue:id,name')
            ->whereIn('id', $items->pluck('event_id')->filter()->all())
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => [
                'channel' => $this->channelPayload($broadcast),
                'items' => $items->map(fn (TelegramChatBroadcastItem $i) => $this->itemPayload($i, $events->get($i->event_id)))->values(),
            ],
        ]);
    }

    /**
     * Пул предложений — с ПРИЧИНАМИ, а не с баллом.
     *
     * Балл сюда намеренно не отдаём: 133 события из 138 набирают 80 и выше при
     * потолке 120, и сортировка по нему почти случайна. Человеку полезнее
     * знать, чем событие отличается от того, что уже стоит в ленте.
     */
    public function suggestions(Request $request, int $broadcastId): JsonResponse
    {
        $broadcast = TelegramChatBroadcast::query()->with('chat')->findOrFail($broadcastId);
        $chat = $broadcast->chat;

        // МОМЕНТ публикации, под который подбираем: день слота плюс час
        // расписания канала. Сравнивать с началом дня мало — пост уходит в
        // 10:00, и событие, которое было в 08:00 того же дня, предлагать
        // нельзя. День в день можно, но только пока событие не началось.
        $publishAt = null;
        if ($request->query('date')) {
            $hour = 10;
            if (preg_match('/_(\d{1,2})$/', (string) $broadcast->period, $m)) {
                $hour = max(0, min(23, (int) $m[1]));
            }
            $publishAt = Carbon::parse((string) $request->query('date'), 'Europe/Moscow')
                ->startOfDay()
                ->setTime($hour, 0)
                ->utc();
        }

        if (! $chat || ! $chat->city_id) {
            return response()->json(['data' => [], 'meta' => ['reason' => 'у канала не задан город']]);
        }

        $inFeed = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereIn('status', $this->openStatuses())
            ->pluck('event_id')
            ->filter()
            ->all();

        $feedVenueIds = Event::query()->whereIn('id', $inFeed)->pluck('venue_id')->filter()->unique()->all();

        $candidates = Event::query()
            ->active()
            ->upcoming()
            ->with('venue:id,name')
            ->whereHas('community', fn ($q) => $q->where('city_id', $chat->city_id))
            ->whereNotIn('id', $inFeed)
            // Опубликованное не предлагаем: повторить пост нельзя, на
            // (broadcast_id, event_id) стоит UNIQUE.
            ->whereDoesntHave('broadcastItems', function ($q) use ($broadcast) {
                $q->where('broadcast_id', $broadcast->id)->whereNotNull('posted_at');
            })
            // Отклонённое и снятое остывает 30 дней — тот же срок, что у
            // автоподбора (REJECTED_COOLDOWN_DAYS), иначе пул и предложения
            // расходились бы во мнениях.
            ->whereDoesntHave('broadcastItems', function ($q) use ($broadcast) {
                $q->where('broadcast_id', $broadcast->id)
                    // Только rejected: skipped значит «снято из ленты», и
                    // прятать за это событие на месяц было бы наказанием
                    // за обычную перестановку.
                    ->where('status', TelegramChatBroadcastItem::STATUS_REJECTED)
                    ->where('updated_at', '>=', now()->subDays(30));
            })
            ->where('start_time', '<=', now()->addDays(14))
            ->when(
                $publishAt !== null,
                // Событие ещё не должно начаться к моменту публикации.
                // Многодневное считаем годным, пока не кончилось.
                fn ($q) => $q->where(function ($w) use ($publishAt) {
                    $w->where('start_time', '>=', $publishAt)
                        ->orWhere(function ($x) use ($publishAt) {
                            $x->whereNotNull('end_time')->where('end_time', '>=', $publishAt);
                        });
                }),
            )
            ->orderBy('start_time')
            ->limit(self::SUGGESTIONS_LIMIT)
            ->get();

        // Раскладываем по сетям и берём по кругу: иначе сверху окажутся
        // четыре Quest Brothers подряд — сеть держит 17 квестов на неделю и
        // при сортировке по времени занимает весь первый экран.
        $byChain = [];
        foreach ($candidates as $e) {
            $key = $this->chainKey((string) ($e->venue?->name ?? '')) ?: ('venue:'.(string) $e->venue_id);
            $byChain[$key][] = $e;
        }
        $chainSizes = array_map('count', $byChain);

        $ordered = [];
        while ($byChain !== []) {
            foreach (array_keys($byChain) as $key) {
                $ordered[] = [array_shift($byChain[$key]), $key];
                if ($byChain[$key] === []) {
                    unset($byChain[$key]);
                }
            }
        }

        return response()->json([
            'data' => collect($ordered)->map(function (array $pair) use ($feedVenueIds, $chainSizes) {
                [$e, $key] = $pair;

                return [
                    'event_id' => (int) $e->id,
                    'title' => (string) $e->title,
                    'venue' => $e->venue?->name,
                    'chain' => $key,
                    // Сколько ещё событий той же сети в пуле — по этому числу
                    // интерфейс сворачивает сеть в одну строку.
                    'chain_size' => $chainSizes[$key] ?? 1,
                    'start_time' => optional($e->start_time)?->toIso8601String(),
                    'price_status' => $e->price_status,
                    'reasons' => $this->reasons($e, $feedVenueIds),
                    'event_url' => $this->siteUrl().'/events/'.$e->id,
                ];
            })->values(),
        ]);
    }

    /** Поставить событие в ленту канала. */
    public function enqueue(Request $request, int $broadcastId): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'integer'],
            'publish_at' => ['nullable', 'date'],
        ]);

        $broadcast = TelegramChatBroadcast::query()->findOrFail($broadcastId);

        $event = Event::query()->find((int) $data['event_id']);
        if (! $event) {
            return response()->json(['ok' => false, 'error' => 'Событие не найдено.'], 404);
        }

        // На (broadcast_id, event_id) стоит UNIQUE, поэтому вторую запись под
        // то же событие создать нельзя. А записи копятся: у канала Воронежа
        // 69 опубликованных, 22 отклонённых и 5 снятых. Раньше любая из них
        // давала 409 — при том что пул предложений снятые и отклонённые
        // показывает. Человек жал «Поставить» и получал отказ на ровном месте.
        $existing = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('event_id', $event->id)
            ->first();

        if ($existing && $existing->posted_at !== null) {
            return response()->json([
                'ok' => false,
                'error' => 'Это событие уже публиковалось в канале.',
            ], 409);
        }

        if ($existing && in_array($existing->status, $this->openStatuses(), true)) {
            return response()->json([
                'ok' => false,
                'error' => 'Это событие уже стоит в ленте канала.',
            ], 409);
        }

        // Снятое или отклонённое оживляем: человек прямо сейчас сказал, что
        // хочет этот пост, и его прошлое решение больше не в силе.
        $item = $existing ?: new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->event_id = $event->id;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->error_message = null;
        $item->claimed_at = null;
        $item->claim_token = null;
        $item->publish_at = $this->toUtc($data['publish_at'] ?? null);
        // Текст пересобираем, если его не писали руками.
        if ($item->caption_source !== TelegramChatBroadcastItem::CAPTION_MANUAL) {
            $item->caption = null;
            $item->caption_source = null;
        }
        $item->save();

        $this->fillCaption($item, $broadcast, $event);

        return response()->json(['data' => $this->itemPayload($item->fresh(), $event)]);
    }

    /** Правка: текст, дата публикации, закрепление. */
    public function update(Request $request, int $itemId): JsonResponse
    {
        $data = $request->validate([
            'caption' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'publish_at' => ['sometimes', 'nullable', 'date'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);

        if ($item->posted_at !== null) {
            return response()->json([
                'ok' => false,
                'error' => 'Пост уже опубликован — править нечего.',
            ], 409);
        }

        if ($request->has('caption')) {
            $caption = trim((string) $data['caption']);
            if ($caption === '') {
                // Пустая правка = «вернуть шаблонный»: сбрасываем и собираем заново.
                $item->caption = null;
                $item->caption_source = null;
                $broadcast = TelegramChatBroadcast::query()->find($item->broadcast_id);
                $event = Event::query()->find($item->event_id);
                if ($broadcast && $event) {
                    $this->fillCaption($item, $broadcast, $event);
                }
            } else {
                $item->caption = $caption;
                // С этой минуты пересборка ленты текст не трогает.
                $item->caption_source = TelegramChatBroadcastItem::CAPTION_MANUAL;
            }
        }

        if ($request->has('publish_at')) {
            $before = optional($item->publish_at)?->toDateString();
            $item->publish_at = $this->toUtc($data['publish_at']);
            $after = optional($item->publish_at)?->toDateString();

            // Дата поменялась — шаблонный текст пересобираем: в нём есть
            // «сегодня» и «завтра», и они считаются от дня публикации.
            // Свой текст не трогаем: его писал человек.
            if (
                $before !== $after
                && $item->caption_source !== TelegramChatBroadcastItem::CAPTION_MANUAL
                && ! $request->has('caption')
            ) {
                $item->caption = null;
                $item->caption_source = null;
                $bc = TelegramChatBroadcast::query()->find($item->broadcast_id);
                $ev = Event::query()->find($item->event_id);
                if ($bc && $ev) {
                    $item->save();
                    $this->fillCaption($item, $bc, $ev);
                }
            }
        }

        if ($request->has('is_pinned')) {
            $item->is_pinned = (bool) $data['is_pinned'];
        }

        $item->save();

        return response()->json([
            'data' => $this->itemPayload($item->fresh(), Event::query()->with('venue:id,name')->find($item->event_id)),
        ]);
    }

    /**
     * Убрать пост из ленты.
     *
     * Два РАЗНЫХ действия, и путать их нельзя:
     *   ?reject=0 (по умолчанию) — просто снять из очереди. Событие сразу
     *     возвращается в пул предложений: человек переставляет ленту, а не
     *     отказывается от события.
     *   ?reject=1 — «больше не предлагать». Событие уходит из подбора на
     *     30 дней (REJECTED_COOLDOWN_DAYS).
     *
     * Раньше «убрать» всегда ставило skipped и вместе с остыванием прятало
     * событие на месяц — то есть переставить пост было нельзя, не потеряв его.
     */
    public function remove(Request $request, int $itemId): JsonResponse
    {
        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);

        if ($item->posted_at !== null) {
            return response()->json(['ok' => false, 'error' => 'Пост уже опубликован.'], 409);
        }

        $reject = $request->boolean('reject');

        $item->status = $reject
            ? TelegramChatBroadcastItem::STATUS_REJECTED
            : TelegramChatBroadcastItem::STATUS_SKIPPED;
        $item->error_message = $reject ? 'отклонено в админке' : 'снято из ленты';
        $item->claimed_at = null;
        $item->claim_token = null;
        $item->save();

        return response()->json(['ok' => true, 'data' => ['rejected' => $reject]]);
    }

    /**
     * Пересобрать ленту.
     *
     * Закреплённые и правленные руками не трогаем — в этом и смысл кнопки
     * «закрепить»: она защищает пост именно от пересборки.
     */
    public function rebuild(int $broadcastId): JsonResponse
    {
        $broadcast = TelegramChatBroadcast::query()->findOrFail($broadcastId);

        $dropped = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereIn('status', $this->openStatuses())
            ->where('is_pinned', false)
            ->whereNull('posted_at')
            // Портреты площадок пересборка НЕ трогает: у них свой недельный
            // каденс и свой слот в неделе, они не конкурируют с событиями за
            // место. Снести портрет заодно с лентой значило бы сбросить его
            // ротацию ни за что.
            ->where(function ($q) {
                $q->whereNull('kind')->orWhere('kind', '<>', TelegramChatBroadcastItem::KIND_VENUE);
            })
            ->update([
                'status' => TelegramChatBroadcastItem::STATUS_SKIPPED,
                'error_message' => 'снято при пересборке ленты',
                'claimed_at' => null,
                'claim_token' => null,
                'updated_at' => now(),
            ]);

        // Заполняем ПО ДНЯМ, а не дёргаем планировщик. Тот подчиняется
        // расписанию и добавляет пост, только если окно «пора», — для кнопки,
        // которую человек нажал сейчас, это неверно: она часто добавляла ноль.
        $filled = $this->broadcasts->fillFeedDays(
            $broadcast->fresh('chat'),
            Carbon::now(),
        );

        return response()->json(['data' => ['dropped' => $dropped] + $filled]);
    }

    /** Настройки канала. */
    public function updateChannel(Request $request, int $broadcastId): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'period' => ['sometimes', 'string', 'max:32'],
            'template_code' => ['sometimes', 'string', 'max:32'],
            'feed_limit' => ['sometimes', 'integer', 'min:1', 'max:31'],
        ]);

        $broadcast = TelegramChatBroadcast::query()->with('chat')->findOrFail($broadcastId);

        if ($request->has('enabled')) {
            $broadcast->enabled = (bool) $data['enabled'];
        }
        if ($request->has('period')) {
            $broadcast->period = (string) $data['period'];
        }
        if ($request->has('template_code')) {
            $broadcast->template_code = (string) $data['template_code'];
        }
        if ($request->has('feed_limit')) {
            $broadcast->feed_limit = (int) $data['feed_limit'];
        }

        $broadcast->save();

        return response()->json(['data' => $this->channelPayload($broadcast->fresh('chat'))]);
    }

    // ------------------------------------------------------------------

    /** @return list<string> */
    private function openStatuses(): array
    {
        return [
            TelegramChatBroadcastItem::STATUS_PENDING,
            TelegramChatBroadcastItem::STATUS_PLANNED,
            TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
            TelegramChatBroadcastItem::STATUS_APPROVED,
            TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
        ];
    }

    /** @return array<string, mixed> */
    private function channelPayload(TelegramChatBroadcast $b): array
    {
        $openEvents = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $b->id)
            ->whereIn('status', $this->openStatuses())
            ->where(function ($q) {
                $q->whereNull('kind')->orWhere('kind', '<>', TelegramChatBroadcastItem::KIND_VENUE);
            })
            ->count();

        $lastPosted = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $b->id)
            ->whereNotNull('posted_at')
            ->max('posted_at');

        $postedTotal = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $b->id)
            ->whereNotNull('posted_at')
            ->count();

        $openVenue = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $b->id)
            ->whereIn('status', $this->openStatuses())
            ->where('kind', TelegramChatBroadcastItem::KIND_VENUE)
            ->count();

        return [
            'id' => (int) $b->id,
            'title' => $b->chat?->title,
            'username' => $b->chat?->username,
            'enabled' => (bool) $b->enabled,
            'period' => $b->period,
            'template_code' => $b->template_code,
            'feed_limit' => $b->feed_limit,
            'in_feed' => $openEvents,
            'last_posted_at' => $lastPosted ? Carbon::parse($lastPosted)->toIso8601String() : null,
            'silent_days' => $lastPosted ? (int) Carbon::parse($lastPosted)->diffInDays(now()) : null,
            'posted_total' => $postedTotal,
            'venue_in_feed' => $openVenue,
            // Ревью-гейт — ГЛОБАЛЬНЫЙ env-флаг, а не настройка канала. Отдаём
            // только для показа: рисовать тумблер, за которым ничего нет,
            // было бы враньём.
            'review_gate' => (bool) config('services.bot.broadcast_review_gate'),
        ];
    }

    /** @return array<string, mixed> */
    private function itemPayload(TelegramChatBroadcastItem $i, ?Event $event): array
    {
        return [
            'id' => (int) $i->id,
            'kind' => $i->kind ?? 'event',
            'status' => $i->status,
            'event_id' => $i->event_id ? (int) $i->event_id : null,
            'title' => $event?->title,
            'venue' => $event?->venue?->name,
            'event_start_time' => optional($event?->start_time)?->toIso8601String(),
            'event_end_time' => optional($event?->end_time)?->toIso8601String(),
            'event_address' => $event?->address,
            'event_city' => $event?->city,
            'price_status' => $event?->price_status,
            'price_min' => $event?->price_min,
            'price_max' => $event?->price_max,
            // Ссылка на карточку события: из админки удобно уйти посмотреть,
            // что именно уходит в канал.
            'event_url' => $event ? $this->siteUrl().'/events/'.$event->id : null,
            'caption' => $i->caption,
            'caption_source' => $i->caption_source,
            'is_pinned' => (bool) $i->is_pinned,
            'publish_at' => optional($i->publish_at)?->toIso8601String(),
            'posted_at' => optional($i->posted_at)?->toIso8601String(),
            // Ровно те картинки и в том порядке, что уйдут в канал: у события
            // — через тот же eventPhotos, которым собирается задача боту;
            // у портрета площадки картинка лежит на самой записи.
            'photos' => $i->kind === TelegramChatBroadcastItem::KIND_VENUE
                ? array_values(array_filter([$i->photo_url]))
                : ($i->event_id ? $this->broadcasts->eventPhotos((int) $i->event_id) : []),
        ];
    }

    /**
     * Почему это событие стоит взять. Без цифр: человеку нужен повод,
     * а не балл, который у всех одинаковый.
     *
     * @param  list<int>  $feedVenueIds
     * @return list<string>
     */
    private function reasons(Event $e, array $feedVenueIds): array
    {
        $out = [];

        if ($e->venue_id === null || ! in_array((int) $e->venue_id, $feedVenueIds, true)) {
            $out[] = 'площадки ещё нет в ленте';
        }
        if ($e->price_status === 'free') {
            $out[] = 'бесплатно';
        }
        if ($e->start_time && Carbon::parse($e->start_time)->lessThan(now()->addDays(3))) {
            $out[] = 'скоро начнётся';
        }

        return $out;
    }

    /**
     * Ключ сети площадок — та же логика, что в подборе
     * (TelegramChatBroadcastService::venueChainKey): отрезаем хвост по « на »,
     * но только если в остатке хотя бы два слова, иначе «Театр на Таганке»
     * склеил бы все театры.
     */
    private function chainKey(string $name): string
    {
        $name = trim(mb_strtolower($name));
        if ($name === '') {
            return '';
        }

        $head = trim((string) preg_split('/\\s+на\\s+/u', $name, 2)[0]);
        $words = preg_split('/\\s+/u', $head) ?: [];

        return count($words) >= 2 ? (string) preg_replace('/\\s+/u', ' ', $head) : $name;
    }

    /**
     * Разобрать дату из админки и привести к UTC.
     *
     * ->utc() здесь обязателен. Carbon разбирает «10:00+03:00» правильно, но
     * при сохранении Laravel форматирует дату в ЕЁ СОБСТВЕННОМ поясе строкой
     * без смещения, и Postgres принимает «10:00» за UTC. Пост уехал бы на три
     * часа вперёд, причём молча — поймано только проверкой с московским
     * смещением, на UTC-строке расхождения не видно.
     */
    private function toUtc(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->utc();
    }

    /**
     * Адрес сайта для ссылок В АДМИНКЕ. Не app.url: тот боевой и в разработке
     * тоже, потому что уходит в текст постов. Здесь нужен тот сайт, который
     * админ может открыть прямо сейчас.
     */
    private function siteUrl(): string
    {
        $custom = trim((string) config('services.bot.admin_site_url'));

        return rtrim($custom !== '' ? $custom : (string) (config('app.url') ?: 'https://kudab.ru'), '/');
    }

    private function fillCaption(TelegramChatBroadcastItem $item, TelegramChatBroadcast $broadcast, Event $event): void
    {
        try {
            $item->caption = $this->captions->build(
                $event,
                (string) $broadcast->template_code,
                // «Сегодня»/«завтра» — от дня публикации, а не от дня сборки.
                $item->publish_at
                    ? \Carbon\CarbonImmutable::parse($item->publish_at)->setTimezone('Europe/Moscow')
                    : null,
            );
            $item->caption_source = TelegramChatBroadcastItem::CAPTION_TEMPLATE;
            $item->save();
        } catch (\Throwable $e) {
            Log::warning('admin.broadcast.caption_failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
