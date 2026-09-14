<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Contracts\Telegram\TelegramChatBroadcastRepositoryInterface;
use App\Contracts\Telegram\TelegramChatRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Repositories\EventRepository;
use App\Services\Telegram\EventCaptionBuilder;
use App\Services\Telegram\TelegramChatBroadcastService;
use App\Support\BroadcastSafety;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    /** Сколько картинок уходит в пост. Столько же берёт автоподбор. */
    private const PHOTO_LIMIT = 3;

    /** Сколько картинок показываем на выбор — из них человек собирает пост. */
    private const PHOTO_CANDIDATES = 10;

    public function __construct(
        private readonly TelegramChatBroadcastService $broadcasts,
        private readonly EventCaptionBuilder $captions,
        // Репозитории — только для привязки канала: она пишет в telegram.chats
        // и заводит строку рассылки, а сервис таких методов не имеет.
        private readonly TelegramChatRepositoryInterface $chats,
        private readonly TelegramChatBroadcastRepositoryInterface $chatBroadcasts,
        // Только ради загрузки картинок всей ленты одним запросом.
        private readonly EventRepository $events,
        // Город канала: city_id вне fillable, и ставить его надо тем же
        // методом, которым это делают CLI и бот.
        private readonly \App\Services\Telegram\TelegramChatService $chatService,
    ) {}

    /** Каналы со сводкой: что в ленте, когда последний пост, молчит ли. */
    public function channels(): JsonResponse
    {
        // Шаблоны отдаём вместе с каналами, чтобы админка не зашивала их
        // список у себя: он живёт в telegram.message_templates.

        // Порядок стабильный: без него список приходил как ляжет, и в
        // интерфейсе первым оказывался выключенный канал.
        // chat.city — чтобы название города не тянулось отдельным запросом
        // на каждый канал.
        $rows = TelegramChatBroadcast::query()->with('chat.city')->orderBy('id')->get();

        $templates = \App\Models\TelegramMessageTemplate::query()
            ->where('locale', 'ru')
            ->where('is_active', true)
            ->orderBy('code')
            ->pluck('code')
            ->all();

        // Города — оттуда же и по тому же правилу, что резолвит их запись:
        // только активные. Готовая ручка admin/select/cities статус не
        // фильтрует и отдала бы одиннадцать отключённых городов, которые
        // запись всё равно не примет.
        $cities = \App\Models\City::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])
            ->all();

        return response()->json([
            'data' => $rows->map(fn (TelegramChatBroadcast $b) => $this->channelPayload($b))->values(),
            'meta' => ['templates' => $templates, 'cities' => $cities],
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
                    // Ошибочные показываем обязательно: раньше такой пост
                    // просто исчезал с глаз и повторялся в фоне.
                    ->orWhere('status', TelegramChatBroadcastItem::STATUS_ERROR)
                    // Снятые автоматически — тоже: пост, убранный из ленты за
                    // просрочку или из-за прошедшего события, обязан оставить
                    // след. Иначе он исчезает из недельной сетки без причины и
                    // без способа вернуть.
                    ->orWhere(function ($w) {
                        $w->where('status', TelegramChatBroadcastItem::STATUS_SKIPPED)
                            ->where('updated_at', '>=', now()->subDays(7));
                    })
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
            ->get();

        // Площадки портретов — одним запросом. Без них интерфейс рисовал
        // литерал «(портрет площадки)» без названия: title и venue брались
        // только из события, а у портрета события нет.
        $venues = \App\Models\Venue::query()
            ->whereIn('id', $items->pluck('venue_id')->filter()->all())
            ->get(['id', 'name'])
            ->keyBy('id');

        // Картинки — одним запросом на всю ленту. Без этого itemPayload звал
        // findWithDetails на каждый пост, и дважды: под фактический набор и
        // под список кандидатов. На неделе это два десятка запросов вместо
        // одного.
        $this->events->hydrateImagesFor($events);
        $events = $events->keyBy('id');

        return response()->json([
            'data' => [
                'channel' => $this->channelPayload($broadcast),
                'items' => $items->map(fn (TelegramChatBroadcastItem $i) => $this->itemPayload(
                    $i,
                    $events->get($i->event_id),
                    $i->venue_id ? $venues->get($i->venue_id) : null,
                ))->values(),
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

        // Обложки одним проходом: карточкам предложений они нужны все сразу.
        app(\App\Repositories\EventRepository::class)->hydrateImagesFor($candidates);

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
                    'cover' => (is_array($e->getAttribute('images')) ? ($e->getAttribute('images')[0] ?? null) : null),
                    'end_time' => optional($e->end_time)?->toIso8601String(),
                    'price_min' => $e->price_min,
                    'price_max' => $e->price_max,
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

        // Всё, что читает и меняет ленту, — под одной блокировкой. Между
        // поиском занявшего день и записью нового поста вторая вкладка
        // успевала вклиниться: обе не находили занявшего, обе писали свой
        // день, и на дне оказывалось два поста.
        return DB::transaction(function () use ($broadcast, $event, $data) {
            TelegramChatBroadcast::query()
                ->whereKey($broadcast->id)
                ->lockForUpdate()
                ->first();

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
            // Та же проверка, что при переносе: пост не может уйти после события.
            // Общий список карточек не привязан ко дню, и перетаскиванием на
            // дальний день можно было поставить анонс уже прошедшего.
            $publishAt = $this->toUtc($data['publish_at'] ?? null);
            if ($publishAt && $event->start_time) {
                $endsAt = $event->end_time ?: $event->start_time;
                if (Carbon::parse($endsAt)->lt($publishAt)) {
                    return response()->json([
                        'ok' => false,
                        'error' => 'К этому дню событие уже пройдёт — пост будет про прошлое.',
                    ], 422);
                }
            }

            // Занятый день уступает место. Раньше сюда нельзя было поставить
            // ничего: неделя собирается на все 7 дней, свободных слотов не
            // остаётся, и любое перетаскивание карточки упиралось в отказ — со
            // стороны это выглядело так, будто перетаскивание сломалось.
            // Прежний пост не удаляем, а возвращаем в общую очередь: он остаётся
            // в ленте без дня и его можно поставить обратно одним движением.
            $displaced = null;
            if ($publishAt !== null) {
                $occupant = $this->dayOccupant(
                    (int) $broadcast->id,
                    $publishAt,
                    exceptEventId: (int) $event->id,
                    bySlot: $broadcast->slots !== [],
                );

                if ($occupant && $occupant->is_pinned) {
                    return response()->json([
                        'ok' => false,
                        'error' => 'В этот день закреплён пост — сначала снимите закрепление.',
                    ], 409);
                }

                if ($occupant) {
                    $occupant->publish_at = null;
                    $occupant->save();
                    // Текст пересобираем: в шаблонном есть «сегодня»/«завтра»,
                    // и без пересборки пост унёс бы их от прежнего дня.
                    $this->regenerateCaption($occupant, $broadcast);
                    $displacedEvent = $occupant->event_id ? Event::query()->find($occupant->event_id) : null;
                    $displaced = [
                        'id' => $occupant->id,
                        'title' => $displacedEvent?->title,
                    ];
                }
            }

            $item = $existing ?: new TelegramChatBroadcastItem;
            $item->broadcast_id = $broadcast->id;
            $item->event_id = $event->id;
            $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
            $item->error_message = null;
            $item->claimed_at = null;
            $item->claim_token = null;
            $item->publish_at = $publishAt;
            // Текст пересобираем, если его не писали руками.
            if ($item->caption_source !== TelegramChatBroadcastItem::CAPTION_MANUAL) {
                $item->caption = null;
                $item->caption_source = null;
            }
            $item->save();

            $this->fillCaption($item, $broadcast, $event);

            return response()->json([
                'data' => $this->itemPayload(
                    $item->fresh(),
                    $event,
                    $item->venue_id ? \App\Models\Venue::query()->find($item->venue_id, ['id', 'name']) : null,
                ),
                'meta' => ['displaced' => $displaced],
            ]);
        });
    }

    /** Правка: текст, дата публикации, закрепление. */
    public function update(Request $request, int $itemId): JsonResponse
    {
        $data = $request->validate([
            'caption' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'publish_at' => ['sometimes', 'nullable', 'date'],
            'is_pinned' => ['sometimes', 'boolean'],
            // null = вернуть автоподбор; массив = ровно эти картинки
            // (пустой массив — осознанное «без картинок»).
            'photo_urls' => ['sometimes', 'nullable', 'array', 'max:10'],
            'photo_urls.*' => ['string', 'max:1000'],
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

        if ($request->has('photo_urls')) {
            $chosen = $data['photo_urls'] ?? null;

            if ($chosen === null) {
                $item->photo_urls = null;
            } else {
                // Берём ТОЛЬКО картинки самого события. Иначе через ручку
                // можно было бы отправить в канал любую чужую ссылку, а
                // ошибка в адресе всплыла бы уже при публикации.
                $available = $item->event_id
                    ? $this->broadcasts->eventPhotos((int) $item->event_id, self::PHOTO_CANDIDATES)
                    : [];

                $clean = [];
                foreach ($chosen as $url) {
                    $url = trim((string) $url);
                    if ($url !== '' && in_array($url, $available, true) && ! in_array($url, $clean, true)) {
                        $clean[] = $url;
                    }
                }

                if (count($clean) !== count($chosen)) {
                    return response()->json([
                        'ok' => false,
                        'error' => 'Среди выбранных картинок есть чужие или повторные — обновите страницу.',
                    ], 422);
                }

                // Телеграм принимает в альбом не больше десяти, но постом
                // уходит три: больше — стена картинок вместо анонса.
                $item->photo_urls = array_slice($clean, 0, self::PHOTO_LIMIT);
            }
        }

        if ($request->has('publish_at')) {
            $newAt = $this->toUtc($data['publish_at']);

            // Те же две проверки, что при постановке и переносе. Раньше их
            // здесь не было ни одной, и через карточку правки можно было
            // поставить два поста на один день или увести анонс за событие —
            // мимо всех защит, которые стоят на соседних путях.
            if ($newAt !== null) {
                $refusal = DB::transaction(function () use ($item, $newAt) {
                    $event = $item->event_id ? Event::query()->find($item->event_id) : null;
                    if ($event && $event->start_time) {
                        $endsAt = $event->end_time ?: $event->start_time;
                        if (Carbon::parse($endsAt)->lt($newAt)) {
                            return ['К этому дню событие уже пройдёт — пост будет про прошлое.', 422];
                        }
                    }

                    $broadcast = TelegramChatBroadcast::query()->find($item->broadcast_id);
                    $occupant = $this->dayOccupant(
                        (int) $item->broadcast_id,
                        $newAt,
                        exceptItemId: (int) $item->id,
                        bySlot: $broadcast && $broadcast->slots !== [],
                    );

                    if ($occupant && $occupant->is_pinned) {
                        return ['В этот день закреплён пост — сначала снимите закрепление.', 409];
                    }

                    if ($occupant) {
                        // Как при постановке: прежний пост не удаляем, а
                        // возвращаем в общую очередь без дня.
                        $occupant->publish_at = null;
                        $occupant->save();
                        if ($broadcast) {
                            $this->regenerateCaption($occupant, $broadcast);
                        }
                    }

                    return null;
                });

                if ($refusal !== null) {
                    return response()->json(['ok' => false, 'error' => $refusal[0]], $refusal[1]);
                }
            }

            $before = optional($item->publish_at)?->toDateString();
            $item->publish_at = $newAt;
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
            'data' => $this->itemPayload(
                $item->fresh(),
                Event::query()->with('venue:id,name')->find($item->event_id),
                $item->venue_id ? \App\Models\Venue::query()->find($item->venue_id, ['id', 'name']) : null,
            ),
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

        // Сколько из снимаемого — те, что ждали свободного дня. Их человек
        // положил туда руками (вытеснив предложением), и молча выметать их
        // нельзя: в интерфейсе написано «дождитесь, пока день освободится».
        $waitingDropped = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereIn('status', $this->openStatuses())
            ->where('is_pinned', false)
            ->whereNull('posted_at')
            ->whereNull('publish_at')
            ->where(function ($q) {
                $q->whereNull('kind')->orWhere('kind', '<>', TelegramChatBroadcastItem::KIND_VENUE);
            })
            ->count();

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

        return response()->json(['data' => ['dropped' => $dropped, 'waiting_dropped' => $waitingDropped] + $filled]);
    }

    /**
     * Перенести пост на другой день — под перетаскивание в ленте.
     *
     * Одной ручкой, а не двумя PATCH подряд: если на целевом дне уже стоит
     * пост, дни МЕНЯЮТСЯ МЕСТАМИ, и делать это двумя запросами нельзя —
     * между ними лента окажется с двумя постами на одном дне и дырой на
     * другом, а при обрыве так и останется.
     *
     * Текст обоих пересобирается: в нём есть «сегодня» и «завтра», и они
     * считаются от дня публикации. Свой текст не трогаем.
     */
    public function move(Request $request, int $broadcastId): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'publish_at' => ['required', 'date'],
        ]);

        $broadcast = TelegramChatBroadcast::query()->findOrFail($broadcastId);

        $item = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->findOrFail((int) $data['item_id']);

        if ($item->posted_at !== null) {
            return response()->json(['ok' => false, 'error' => 'Пост уже опубликован.'], 409);
        }

        $target = $this->toUtc($data['publish_at']);
        if ($target === null) {
            return response()->json(['ok' => false, 'error' => 'Не разобрал дату.'], 422);
        }

        // Пост не может уйти ПОСЛЕ события — получится анонс задним числом.
        // Перетаскивание в ленте позволяло это сделать в один жест: пост про
        // концерт 9-го уезжал на 10-е и говорил «9 сен» в прошедшем времени.
        // Правило то же, что у подбора: день в день можно, пока не началось.
        $event = $item->event_id ? Event::query()->find($item->event_id) : null;
        if ($event && $event->start_time) {
            $endsAt = $event->end_time ?: $event->start_time;
            if (Carbon::parse($endsAt)->lt($target)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'К этому дню событие уже пройдёт — пост будет про прошлое.',
                ], 422);
            }
        }

        $targetDay = $target->copy()->setTimezone('Europe/Moscow')->toDateString();

        // Кто уже занимает этот день.
        $occupant = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            // Ошибочный пост день занимает — в сетке он виден.
            ->whereIn('status', [...$this->openStatuses(), TelegramChatBroadcastItem::STATUS_ERROR])
            ->whereNull('posted_at')
            ->whereNotNull('publish_at')
            ->where('id', '<>', $item->id)
            ->get()
            ->first(fn (TelegramChatBroadcastItem $x) => Carbon::parse($x->publish_at)
                ->setTimezone('Europe/Moscow')->toDateString() === $targetDay);

        if ($occupant && $occupant->is_pinned) {
            return response()->json([
                'ok' => false,
                'error' => 'В этот день закреплён пост — сначала снимите закрепление.',
            ], 409);
        }

        $from = $item->publish_at;

        // Обмен двусторонний: второй пост тоже не должен уехать за своё
        // событие. Иначе одним перетаскиванием ломается соседний день.
        if ($occupant && $from !== null && $occupant->event_id) {
            $otherEvent = Event::query()->find($occupant->event_id);
            if ($otherEvent && $otherEvent->start_time) {
                $otherEnds = $otherEvent->end_time ?: $otherEvent->start_time;
                if (Carbon::parse($otherEnds)->lt($from)) {
                    return response()->json([
                        'ok' => false,
                        'error' => 'Обмен невозможен: второй пост уехал бы за своё событие.',
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($item, $occupant, $target, $from) {
            $item->publish_at = $target;
            $item->save();

            if ($occupant) {
                // Меняемся местами. Если у переносимого дня не было, соседу
                // достаётся пустая дата — он вернётся в общую очередь.
                $occupant->publish_at = $from;
                $occupant->save();
            }
        });

        foreach (array_filter([$item, $occupant]) as $changed) {
            $this->regenerateCaption($changed, $broadcast);
        }

        return response()->json(['ok' => true, 'data' => ['swapped' => $occupant !== null]]);
    }

    /** Пересобрать шаблонный текст под новый день публикации. */
    private function regenerateCaption(TelegramChatBroadcastItem $item, TelegramChatBroadcast $broadcast): void
    {
        if ($item->caption_source === TelegramChatBroadcastItem::CAPTION_MANUAL) {
            return;
        }
        $event = Event::query()->find($item->event_id);
        if (! $event) {
            return;
        }
        $item->caption = null;
        $item->caption_source = null;
        $item->save();
        $this->fillCaption($item, $broadcast, $event);
    }

    /**
     * Отправить пост сейчас.
     *
     * НЕ публикует напрямую: ставит publish_at на текущий момент, и пост
     * забирает обычный поллер на ближайшем тике. Именно поэтому такую кнопку
     * убрали из бота — там она слала пост МИМО очереди и без claim-токена,
     * то есть очередь о посте не знала и анти-дубли его не видели. Здесь всё
     * идёт штатным путём, просто без ожидания расписания.
     */
    public function publishNow(int $itemId): JsonResponse
    {
        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);

        if ($item->posted_at !== null) {
            return response()->json(['ok' => false, 'error' => 'Пост уже опубликован.'], 409);
        }

        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->publish_at = Carbon::now();
        // Придержку снимаем: она ждала генерации текста, а текст уже есть.
        $item->planned_at = null;
        $item->error_message = null;
        $item->claimed_at = null;
        $item->claim_token = null;
        $item->save();

        $broadcast = TelegramChatBroadcast::query()->with('chat')->find($item->broadcast_id);
        if ($broadcast) {
            $this->regenerateCaption($item, $broadcast);
        }

        // Говорим прямо, уйдёт ли пост на самом деле. Раньше админка обещала
        // «в ближайшую минуту» и на стенде, где отправка запрещена: время
        // публикации проставлялось, задача боту не выдавалась, и человек ждал
        // поста, которого не будет, без единого сообщения.
        $willSend = $broadcast?->chat?->telegram_chat_id
            ? BroadcastSafety::postingAllowed((int) $broadcast->chat->telegram_chat_id)
            : false;

        // И вторая причина подождать: зазор между постами канала. Пост уйдёт,
        // но не сию минуту — и лучше сказать это здесь, чем оставить человека
        // смотреть на ленту, где ничего не происходит.
        $waitUntil = $broadcast
            ? $this->broadcasts->nextPostAllowedAt((int) $broadcast->id, Carbon::now())
            : null;

        return response()->json(['ok' => true, 'data' => [
            'will_send' => $willSend,
            'wait_minutes' => $waitUntil ? (int) ceil(Carbon::now()->diffInSeconds($waitUntil) / 60) : 0,
        ]]);
    }

    /** Вернуть пост в очередь после ошибки — попробовать ещё раз. */
    public function retry(int $itemId): JsonResponse
    {
        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);

        if ($item->posted_at !== null) {
            return response()->json(['ok' => false, 'error' => 'Пост уже опубликован.'], 409);
        }

        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->error_message = null;
        $item->claimed_at = null;
        $item->claim_token = null;
        // День у ошибочной записи — вчерашний по определению: она ошиблась
        // тогда, когда должна была уйти. Без этой строки «Повторить» отдавало
        // бы пост прямиком под отсечку просрочки, то есть кнопка повтора
        // молча удаляла бы пост.
        $item->publish_at = Carbon::now();
        $item->save();

        return response()->json(['ok' => true]);
    }

    /** Шаблоны постов: тексты, которыми собираются все неправленые посты. */
    public function templates(): JsonResponse
    {
        $rows = \App\Models\TelegramMessageTemplate::query()
            ->where('locale', 'ru')
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($t) => [
                'code' => (string) $t->code,
                'name' => $t->name,
                'description' => $t->description,
                'body' => (string) $t->body,
                'is_active' => (bool) $t->is_active,
                'max_images' => $t->max_images,
                // Каналы, которые сейчас на этом шаблоне: правка коснётся их.
                'used_by' => TelegramChatBroadcast::query()->get()
                    ->filter(fn (TelegramChatBroadcast $b) => $b->template_code === $t->code)
                    ->map(fn (TelegramChatBroadcast $b) => $b->chat?->username ?: (string) $b->id)
                    ->values(),
            ])->values(),
            'meta' => ['placeholders' => $this->placeholderHelp()],
        ]);
    }

    /** Сохранить текст шаблона. */
    public function updateTemplate(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
        ]);

        $tpl = \App\Models\TelegramMessageTemplate::query()
            ->where('locale', 'ru')
            ->where('code', $code)
            ->firstOrFail();

        $tpl->body = (string) $data['body'];
        $tpl->save();

        Log::info('admin.broadcast.template_updated', ['code' => $code]);

        return response()->json(['ok' => true]);
    }

    /**
     * Превью шаблона на настоящем событии, БЕЗ сохранения.
     *
     * Собирается тем же кодом, что и настоящий пост (EventCaptionBuilder),
     * иначе превью врало бы — а ради «увидеть, что получится» редактор и
     * делается. Событие берём ближайшее из ленты канала, чтобы текст был
     * похож на то, что реально уходит.
     */
    public function previewTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
            'event_id' => ['nullable', 'integer'],
        ]);

        $event = isset($data['event_id'])
            ? Event::query()->find((int) $data['event_id'])
            : Event::query()->active()->upcoming()->orderBy('start_time')->first();

        if (! $event) {
            return response()->json(['ok' => false, 'error' => 'Не нашёл события для превью.'], 404);
        }

        try {
            $text = $this->captions->buildWithBody($event, (string) $data['body']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Шаблон не собрался: '.$e->getMessage()], 422);
        }

        return response()->json(['data' => [
            'text' => $text,
            'event' => [
                'id' => (int) $event->id,
                'title' => (string) $event->title,
                'start_time' => optional($event->start_time)?->toIso8601String(),
            ],
        ]]);
    }

    /**
     * Подсказка по плейсхолдерам. Держим здесь, а не в админке: список
     * задаётся сборщиком текста, и разъезжаться им нельзя.
     *
     * @return list<array{name: string, about: string}>
     */
    private function placeholderHelp(): array
    {
        return [
            ['name' => '{title}', 'about' => 'название события, экранируется'],
            ['name' => '{address}', 'about' => 'город и площадка одной строкой'],
            ['name' => '{start_time|human}', 'about' => '«сегодня, 19:00», «12 сен» — от дня публикации'],
            ['name' => '{price_label}', 'about' => '«Бесплатно», «от 500 ₽», «800 ₽–1500 ₽»'],
            ['name' => '{description|slice:0..400|escape_html}', 'about' => 'описание, обрезка по числу символов'],
            ['name' => '{url}', 'about' => 'ссылка на событие на сайте'],
            ['name' => '{tags|prepend:"🏷 "}', 'about' => 'сейчас всегда пусто — строка не печатается'],
        ];
    }

    /** Настройки канала. */

    /**
     * Проверить чат в Telegram и привязать его как канал рассылки.
     *
     * Зачем. Канал попадал в базу единственным путём — событием
     * my_chat_member, то есть в момент, когда бота добавляют в чат или
     * повышают до администратора. Событие приходит ровно один раз: для
     * канала, где бот админ давно, привязку взять было неоткуда, и старый
     * канал нельзя было подключить вообще никак, кроме правки базы руками.
     *
     * Почему нельзя «показать все каналы, где бот админ». В Bot API нет
     * такого метода: каждый метод про чаты требует идентификатор, который
     * уже знаешь. Поэтому проверка адресная — по @username или id.
     *
     * Почему ходим в бот, а не в Telegram напрямую: токен есть только у бота.
     */
    public function linkChannel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'chat' => ['required', 'string', 'max:128'],
        ]);

        $base = rtrim((string) config('services.bot.url'), '/');
        $token = (string) config('services.bot.shared_token');
        if ($base === '' || $token === '') {
            return response()->json([
                'ok' => false,
                'error' => 'Не настроен адрес бота или общий токен (KUDAB_BOT_URL / BOT_SHARED_TOKEN).',
            ], 503);
        }

        try {
            $res = Http::withToken($token)
                ->timeout((int) config('services.bot.timeout', 10))
                ->acceptJson()
                ->post($base.'/internal/check-chat', ['chat' => $data['chat']]);
        } catch (\Throwable $e) {
            Log::warning('admin.broadcast.link.bot_unreachable', ['error' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'error' => 'Бот не отвечает — проверить чат не получилось.',
            ], 502);
        }

        if ($res->status() === 422) {
            return response()->json([
                'ok' => false,
                'error' => (string) ($res->json('detail') ?: 'Не разобрал идентификатор чата.'),
            ], 422);
        }

        if (! $res->successful()) {
            Log::warning('admin.broadcast.link.bot_error', ['status' => $res->status(), 'body' => $res->body()]);

            return response()->json([
                'ok' => false,
                'error' => 'Бот ответил ошибкой '.$res->status().'.',
            ], 502);
        }

        $body = (array) $res->json();

        if (! ($body['found'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error' => (string) ($body['message'] ?? 'Telegram не знает такого чата.'),
            ], 422);
        }

        $chatInfo = (array) ($body['chat'] ?? []);
        $botInfo = (array) ($body['bot'] ?? []);

        if (! ($body['postable_type'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error' => 'Это не канал и не группа — рассылать туда нечего.',
            ], 422);
        }

        // Единственный настоящий признак связи. get_chat на стороне бота
        // отвечает успехом для ЛЮБОГО публичного канала — по нему можно было
        // бы «привязать» чужой чат, куда бота никто не звал.
        if (! ($botInfo['is_admin'] ?? false)) {
            $botName = $botInfo['username'] ?? null;

            return response()->json([
                'ok' => false,
                'error' => $botName
                    ? 'Бот @'.$botName.' не администратор в этом чате. Добавьте его администратором и повторите.'
                    : 'Бот не администратор в этом чате.',
            ], 422);
        }

        if (($chatInfo['type'] ?? null) === 'channel' && ($botInfo['can_post'] ?? null) === false) {
            return response()->json([
                'ok' => false,
                'error' => 'Бот администратор, но без права публиковать. Включите ему «Публикация сообщений».',
            ], 422);
        }

        $telegramChatId = (int) ($chatInfo['id'] ?? 0);
        if ($telegramChatId === 0) {
            return response()->json(['ok' => false, 'error' => 'Telegram не вернул id чата.'], 502);
        }

        // Владельца не указываем: привязку делает веб-админ, за которым нет
        // телеграм-пользователя. У существующей записи владельца сохраняем.
        $chat = TelegramChat::query()->where('telegram_chat_id', $telegramChatId)->first();
        $existed = $chat !== null;

        $chat = $this->chats->linkChat(
            $chat?->telegram_user_id !== null ? (int) $chat->telegram_user_id : null,
            $telegramChatId,
            (string) ($chatInfo['type'] ?? 'channel'),
            $chatInfo['title'] ?? null,
            $chatInfo['username'] ?? null,
        );

        $broadcast = $this->chatBroadcasts->getOrCreateByChatId($chat->id);

        return response()->json([
            'data' => $this->channelPayload($broadcast->fresh()->load('chat')),
            'meta' => [
                'existed' => $existed,
                'bot_username' => $botInfo['username'] ?? null,
            ],
        ]);
    }

    public function updateChannel(Request $request, int $broadcastId): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'period' => ['sometimes', 'string', 'max:32'],
            'template_code' => ['sometimes', 'string', 'max:32'],
            'feed_limit' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'city_id' => ['sometimes', 'nullable', 'integer'],
            'slots' => ['sometimes', 'array', 'max:'.TelegramChatBroadcast::MAX_SLOTS],
            'slots.*' => ['integer', 'between:0,23'],
            'horizon_days' => ['sometimes', 'integer', 'min:1', 'max:31'],
        ]);

        $broadcast = TelegramChatBroadcast::query()->with('chat')->findOrFail($broadcastId);

        // Включение канала без владельца — самый тихий из отказов: поллер
        // пропустит канал, ЛС-сигнал писать некому, в ленте посты будут
        // копиться. Лучше отказать здесь, где есть кому прочитать причину.
        if ($request->has('enabled') && (bool) $data['enabled'] && $broadcast->chat?->telegram_user_id === null) {
            return response()->json([
                'ok' => false,
                'error' => 'У канала нет владельца — включать его бессмысленно: посты не уйдут, '
                    .'а сигнал о простое писать некому. Привяжите канал через бота, чтобы владелец появился.',
            ], 422);
        }

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
        if ($request->has('slots')) {
            $broadcast->slots = $data['slots'];
        }
        if ($request->has('horizon_days')) {
            $broadcast->horizon_days = (int) $data['horizon_days'];
        }

        // Город пишем ЧЕРЕЗ сервис: city_id вне $fillable у модели чата, и
        // наивный update() вернул бы 200, ничего не изменив. Сервис заодно
        // проверяет, что город существует и активен.
        if ($request->has('city_id') && $broadcast->chat) {
            $cityId = $data['city_id'] ?? null;
            if ($cityId === null) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Город нельзя убрать: без него канал перестанет публиковать.',
                ], 422);
            }

            try {
                $this->chatService->forceSetChatCity($broadcast->chat, (string) $cityId);
            } catch (\RuntimeException $e) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        $broadcast->save();

        return response()->json(['data' => $this->channelPayload($broadcast->fresh('chat'))]);
    }

    // ------------------------------------------------------------------

    /** @return list<string> */
    /**
     * Ровно те картинки и в том порядке, что уйдут в канал.
     *
     * Повторяет выбор из TelegramChatBroadcastService: ручной состав сильнее
     * автоподбора, NULL — «собрать автоматически».
     */
    private function effectivePhotos(TelegramChatBroadcastItem $i, ?Event $event = null): array
    {
        if (is_array($i->photo_urls)) {
            return array_values(array_filter($i->photo_urls, 'is_string'));
        }

        return $this->candidatePhotos($i, $event, self::PHOTO_LIMIT);
    }

    /**
     * Кандидаты в альбом. Если событие уже загружено вместе с картинками
     * (лента делает это одним запросом) — берём из него, иначе идём за ним
     * сами. Отбор в обоих случаях один и тот же, общий с задачей боту.
     */
    private function candidatePhotos(
        TelegramChatBroadcastItem $i,
        ?Event $event = null,
        int $limit = self::PHOTO_CANDIDATES,
    ): array {
        if (! $i->event_id) {
            return [];
        }

        $images = $event ? ($event->getAttributes()['images'] ?? null) : null;
        if (is_array($images)) {
            return TelegramChatBroadcastService::pickPhotos($images, $limit);
        }

        return $this->broadcasts->eventPhotos((int) $i->event_id, $limit);
    }

    /**
     * Кто занимает этот день в ленте канала.
     *
     * Один запрос на все пути, которые пишут publish_at: раньше он был
     * скопирован в постановку и перенос, а правка текста ставила день вообще
     * без проверок — через неё в один день клались два поста.
     */
    private function dayOccupant(
        int $broadcastId,
        Carbon $publishAt,
        ?int $exceptEventId = null,
        ?int $exceptItemId = null,
        bool $bySlot = false,
    ): ?TelegramChatBroadcastItem {
        // Без слотов место в ленте — это ДЕНЬ: два поста на один день канал
        // без слотов показать не умеет, и сетка в админке строится по дням.
        // Со слотами место — день плюс час, иначе два слота схлопнутся в один.
        $format = $bySlot ? 'Y-m-d H' : 'Y-m-d';
        $targetDay = $publishAt->copy()->setTimezone('Europe/Moscow')->format($format);

        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            // event_id <> ? в SQL молча выбрасывает строки с NULL, а это
            // портреты площадок: занятый ими день выглядел свободным.
            ->when($exceptEventId !== null, fn ($q) => $q->where(function ($w) use ($exceptEventId) {
                $w->whereNull('event_id')->orWhere('event_id', '<>', $exceptEventId);
            }))
            ->when($exceptItemId !== null, fn ($q) => $q->where('id', '<>', $exceptItemId))
            // Пост со статусом «ошибка» день занимает: в сетке он виден, и
            // класть поверх него второй — значит показать два поста на одном дне.
            ->whereIn('status', [...$this->openStatuses(), TelegramChatBroadcastItem::STATUS_ERROR])
            ->whereNull('posted_at')
            ->whereNotNull('publish_at')
            ->get()
            ->first(fn (TelegramChatBroadcastItem $x) => Carbon::parse($x->publish_at)
                ->setTimezone('Europe/Moscow')->format($format) === $targetDay);
    }

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
        // Считаем ОТДЕЛЬНО занятые дни и записи без дня. Раньше был один
        // счётчик на всё, и «в ленте» показывало 8 из 7: посты без даты
        // (вытесненные или не получившие день) попадали в тот же итог.
        $openEventsQuery = fn () => TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $b->id)
            ->whereIn('status', $this->openStatuses())
            ->where(function ($q) {
                $q->whereNull('kind')->orWhere('kind', '<>', TelegramChatBroadcastItem::KIND_VENUE);
            });

        $openEvents = $openEventsQuery()->whereNotNull('publish_at')->count();
        $waitingEvents = $openEventsQuery()->whereNull('publish_at')->count();

        $lastPosted = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $b->id)
            ->whereNotNull('posted_at')
            ->max('posted_at');

        $errors = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $b->id)
            ->where('status', TelegramChatBroadcastItem::STATUS_ERROR)
            ->count();

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
            // Часы публикации внутри дня по Москве. Пустой список — один пост
            // в день, час берётся из расписания.
            'slots' => $b->slots,
            'horizon_days' => $b->horizon_days,
            'in_feed' => $openEvents,
            'waiting' => $waitingEvents,
            'last_posted_at' => $lastPosted ? Carbon::parse($lastPosted)->toIso8601String() : null,
            'silent_days' => $lastPosted ? (int) Carbon::parse($lastPosted)->diffInDays(now()) : null,
            'posted_total' => $postedTotal,
            'venue_in_feed' => $openVenue,
            'errors_count' => $errors,
            // Владелец канала — не украшение: без него поллер молча пропускает
            // канал целиком (TelegramChatBroadcastService: skipped_no_owner).
            // Привязка из админки владельца не пишет — за веб-админом нет
            // телеграм-пользователя, — поэтому признак обязан быть виден.
            'has_owner' => $b->chat?->telegram_user_id !== null,
            // Город канала: без него подбирать события не из чего, и до сих
            // пор его не было видно в админке вовсе — только текст проблемы.
            'city_id' => $b->chat?->city_id ? (int) $b->chat->city_id : null,
            'city_name' => $b->chat?->city?->name,
            // Может ли ЭТОТ стенд вообще отправлять в этот канал. На проде
            // всегда да; на стенде — нет, если канал не назван в
            // KUDAB_ADMIN_BROADCAST разрешении (см. BroadcastSafety).
            'posting_allowed' => $b->chat?->telegram_chat_id
                ? BroadcastSafety::postingAllowed((int) $b->chat->telegram_chat_id)
                : false,
            'idle_notified_at' => optional($b->idle_notified_at)?->toIso8601String(),
            // Признаки неблагополучия считаем ЗДЕСЬ, а не в админке: правила
            // (сколько окон пропущено, что считается простоем) заданы сервером,
            // и разъезжаться двум их версиям нельзя.
            'problems' => $this->channelProblems($b, $lastPosted, $errors, $openEvents),
            // Ревью-гейт — ГЛОБАЛЬНЫЙ env-флаг, а не настройка канала. Отдаём
            // только для показа: рисовать тумблер, за которым ничего нет,
            // было бы враньём.
            'review_gate' => (bool) config('services.bot.broadcast_review_gate'),
        ];
    }

    /**
     * Что не так с каналом — человеческими фразами.
     *
     * Сигнал о простое уже существовал: бот пишет владельцу в личку, когда
     * канал молчит дольше двух своих окон. Но в админке этого не было видно
     * вовсе — раздел про рассылку не показывал, что рассылка стоит.
     *
     * @return list<array{level: string, text: string}>
     */
    private function channelProblems(
        TelegramChatBroadcast $b,
        mixed $lastPosted,
        int $errors,
        int $openEvents,
    ): array {
        $out = [];

        // Первым делом: если стенду вообще запрещено постить, всё остальное
        // не имеет значения — пост не уйдёт, сколько ни нажимай.
        if ($b->chat?->telegram_chat_id && ! BroadcastSafety::postingAllowed((int) $b->chat->telegram_chat_id)) {
            $out[] = [
                'level' => 'warning',
                'text' => 'Это не прод: стенд не публикует в этот чат — посты будут копиться в ленте. '
                    .'Чтобы разрешить для проверки, добавьте '.$b->chat->telegram_chat_id.' в '
                    .BroadcastSafety::ALLOW_KEY.' и перезапустите kudab-api.',
            ];
        }

        if (! $b->enabled || $b->period === 'off') {
            $out[] = ['level' => 'info', 'text' => 'Автопостинг выключен — посты не уходят.'];

            return $out;
        }

        // Раньше города: без владельца не уйдёт ни один пост, даже если
        // город задан и лента полна.
        if ($b->chat?->telegram_user_id === null) {
            $out[] = [
                'level' => 'danger',
                'text' => 'У канала нет владельца — посты не уходят вовсе, и сигнал о простое писать некому. '
                    .'Владелец появляется, когда канал привязывают через бота: добавьте бота администратором '
                    .'канала или откройте привязку из телеграма.',
            ];
        }

        if (! $b->chat?->city_id) {
            $out[] = ['level' => 'danger', 'text' => 'У канала не задан город — подбирать события не из чего.'];
        }

        // Порог тот же, что у ЛС-сигнала, и правило берём ОТТУДА ЖЕ: здесь
        // стояла вторая копия, и со слотами они разъехались бы — окно делится
        // на число постов в день, а копия про слоты не знает.
        $windowHours = $this->broadcasts->periodWindowHours($b) ?? 24;
        if ($lastPosted) {
            $silent = (int) Carbon::parse($lastPosted)->diffInHours(now());
            if ($silent >= $windowHours * 2) {
                $days = intdiv($silent, 24);
                $out[] = [
                    'level' => 'danger',
                    'text' => "Канал молчит {$days} дн. — это дольше двух окон расписания.",
                ];
            }
        } else {
            $out[] = ['level' => 'warning', 'text' => 'В канале не было ни одного поста.'];
        }

        if ($errors > 0) {
            $out[] = [
                'level' => 'danger',
                'text' => $errors === 1
                    ? 'Один пост не удалось отправить — посмотрите причину в ленте.'
                    : "{$errors} постов не удалось отправить — посмотрите причины в ленте.",
            ];
        }

        if ($openEvents === 0) {
            $out[] = ['level' => 'warning', 'text' => 'Лента пуста — следующего поста нет.'];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function itemPayload(TelegramChatBroadcastItem $i, ?Event $event, ?\App\Models\Venue $venue = null): array
    {
        return [
            'id' => (int) $i->id,
            'kind' => $i->kind ?? 'event',
            'status' => $i->status,
            'event_id' => $i->event_id ? (int) $i->event_id : null,
            'title' => $event?->title ?? ($venue ? 'Портрет: '.$venue->name : null),
            'venue' => $event?->venue?->name ?? $venue?->name,
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
            // Причина — и для ошибки, и для автоматического снятия: markSkipped
            // пишет её в то же поле, а человеку нужно понимать, почему поста
            // больше нет в ленте.
            'error_message' => in_array($i->status, [
                TelegramChatBroadcastItem::STATUS_ERROR,
                TelegramChatBroadcastItem::STATUS_SKIPPED,
            ], true) ? $i->error_message : null,
            // Ровно те картинки и в том порядке, что уйдут в канал: у события
            // — через тот же eventPhotos, которым собирается задача боту;
            // у портрета площадки картинка лежит на самой записи.
            'photos' => $i->kind === TelegramChatBroadcastItem::KIND_VENUE
                ? array_values(array_filter([$i->photo_url]))
                : $this->effectivePhotos($i, $event),
            // Всё, из чего можно собрать альбом. Портрет площадки не
            // собирают руками: там одна картинка, и она на самой записи.
            'photo_candidates' => $i->kind === TelegramChatBroadcastItem::KIND_VENUE || ! $i->event_id
                ? []
                : $this->candidatePhotos($i, $event),
            // Состав выбран руками — пересборка ленты его не тронет.
            'photos_manual' => is_array($i->photo_urls),
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
