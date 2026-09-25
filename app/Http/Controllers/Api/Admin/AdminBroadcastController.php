<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Contracts\Telegram\TelegramChatBroadcastRepositoryInterface;
use App\Contracts\Telegram\TelegramChatRepositoryInterface;
use App\Exceptions\DigestRecomposeFailed;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Repositories\EventRepository;
use App\Services\Telegram\BroadcastDigestComposer;
use App\Services\Telegram\EventCaptionBuilder;
use App\Services\Telegram\PostTiming;
use App\Services\Telegram\TelegramChatBroadcastService;
use App\Support\BroadcastSafety;
use App\Support\Telegram\CaptionLength;
use App\Support\Telegram\VenueName;
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
 * Раньше очередь снаружи можно было только пропустить (skip) да
 * одобрить/отклонить в ревью.
 */
class AdminBroadcastController extends Controller
{
    /** Сколько предложений отдавать в пул за раз. */
    private const SUGGESTIONS_LIMIT = 40;

    /**
     * Насколько далеко вперёд смотрит пул предложений, дни.
     *
     * Отсчитывается от МОМЕНТА ПУБЛИКАЦИИ, а не от «сейчас». Раньше было от
     * «сейчас», и окно [слот … сегодня+14] схлопывалось по мере удаления слота:
     * замер прода 21.09.2026 — для слота сегодня 177 кандидатов, через неделю 28,
     * через тринадцать дней 5, через четырнадцать РОВНО НОЛЬ. Владелец видел
     * «Что можно поставить» из двух карточек и был прав, что это важно:
     * лента наполняется на две недели вперёд, то есть дальние слоты оставались
     * без предложений именно тогда, когда их и надо заполнять.
     */
    private const SUGGESTIONS_HORIZON_DAYS = 14;

    /** Сколько картинок уходит в пост. Столько же берёт автоподбор. */
    private const PHOTO_LIMIT = 3;

    /** Сколько картинок показываем на выбор — из них человек собирает пост. */
    private const PHOTO_CANDIDATES = 10;

    /**
     * Предел длины подписи поста с картинками — ограничение Telegram.
     *
     * @deprecated Считать длину подписи — [[CaptionLength]]: там UTF-16 и без разметки.
     */
    public const CAPTION_LIMIT = CaptionLength::LIMIT;

    /**
     * Сколько дней отклонённое событие не предлагается заново.
     *
     * Источник один — сервис: там по этому сроку прячут событие от
     * автоподбора, здесь по нему же показывают отказ. Разойдутся — в ленте
     * окажутся отказы, которые уже не действуют, или исчезнут действующие.
     */
    private const REJECTED_COOLDOWN_DAYS = TelegramChatBroadcastService::REJECTED_COOLDOWN_DAYS;

    public function __construct(
        private readonly TelegramChatBroadcastService $broadcasts,
        private readonly EventCaptionBuilder $captions,
        private readonly BroadcastDigestComposer $digestComposer,
        private readonly \App\Services\Telegram\BroadcastDigestBooking $digestBooking,
        // Репозитории — только для привязки канала: она пишет в telegram.chats
        // и заводит строку рассылки, а сервис таких методов не имеет.
        private readonly TelegramChatRepositoryInterface $chats,
        private readonly TelegramChatBroadcastRepositoryInterface $chatBroadcasts,
        // Только ради загрузки картинок всей ленты одним запросом.
        private readonly EventRepository $events,
        // Город канала: city_id вне fillable, и ставить его надо тем же
        // методом, которым это делают CLI и бот.
        private readonly \App\Services\Telegram\TelegramChatService $chatService,
        // Портреты площадок: у них свой сборщик текста и свои картинки — у
        // записи нет события, из которого их берут событийные посты.
        private readonly \App\Services\Telegram\TelegramVenuePortraitService $venuePortraits,
    ) {}

    /** Каналы со сводкой: что в ленте, когда последний пост, молчит ли. */
    public function channels(): JsonResponse
    {
        // Порядок стабильный: без него список приходил как ляжет, и в
        // интерфейсе первым оказывался выключенный канал.
        // chat.city — чтобы название города не тянулось отдельным запросом
        // на каждый канал.
        $rows = TelegramChatBroadcast::query()->with('chat.city')->orderBy('id')->get();

        // Шаблоны отдаём вместе с каналами, чтобы админка не зашивала их
        // список у себя: он живёт в telegram.message_templates.
        // Коды остаются для совместимости (по ним сохраняется выбор), но рядом
        // едут человеческие имена: в отметках форм владелец не должен читать
        // «lead-below» — у каждого шаблона есть название.
        $templateRows = \App\Models\TelegramMessageTemplate::query()
            ->where('locale', 'ru')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name']);

        $templates = $templateRows->pluck('code')->all();
        $templateNames = $templateRows
            ->mapWithKeys(fn ($t) => [(string) $t->code => (string) ($t->name ?: $t->code)])
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
            'meta' => ['templates' => $templates, 'template_names' => $templateNames, 'cities' => $cities],
        ]);
    }

    /**
     * Лента канала: что стоит в очереди и что ушло за последние дни.
     *
     * Окно истории просит интерфейс (`history_days`): в ленте видно ближайшую
     * неделю, а «что вышло» человек разворачивает отдельно и иногда за месяц.
     * Отдавать месяц всегда нельзя — это сотни строк на каждое открытие
     * страницы, а лента читается часто.
     */
    public function feed(Request $request, int $broadcastId): JsonResponse
    {
        $broadcast = TelegramChatBroadcast::query()->with('chat')->findOrFail($broadcastId);

        $historyDays = max(1, min(90, (int) ($request->query('history_days') ?: self::HISTORY_DAYS)));

        $items = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where(function ($q) use ($historyDays) {
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
                    // Отклонённые — за всё время остывания: иначе отказ нечем
                    // отменить (см. unreject()).
                    ->orWhere(function ($w) {
                        $w->where('status', TelegramChatBroadcastItem::STATUS_REJECTED)
                            ->where('updated_at', '>=', now()->subDays(self::REJECTED_COOLDOWN_DAYS));
                    })
                    ->orWhere(function ($w) use ($historyDays) {
                        $w->where('status', TelegramChatBroadcastItem::STATUS_POSTED)
                            ->where('posted_at', '>=', now()->subDays($historyDays));
                    })
                    // Снятые из канала руками: пост вышел и был удалён, и
                    // след о нём нужен ровно затем же, зачем у снятых
                    // автоматически — иначе запись пропадает из сетки молча.
                    ->orWhere(function ($w) use ($historyDays) {
                        $w->where('status', TelegramChatBroadcastItem::STATUS_WITHDRAWN)
                            ->where('updated_at', '>=', now()->subDays($historyDays));
                    });
            })
            ->orderByRaw('COALESCE(publish_at, planned_at, posted_at, created_at) ASC')
            ->get();

        $events = Event::query()
            ->with(['venue:id,name', 'primaryInterests:id,name'])
            ->whereIn('id', $items->pluck('event_id')->filter()->all())
            ->get()
            ->keyBy('id');

        // Снятые, которые уже не вернуть, в списке не нужны: решать по ним
        // нечего, а копятся они быстрее всех. Смотрим на САМО событие, а не на
        // текст причины: запись, снятая руками неделю назад, к сегодняшнему дню
        // тоже могла протухнуть — и список предлагал вернуть то, что возврат
        // честно отклонял («Вернул 0, не вышло 12»).
        $now = Carbon::now();
        $items = $items->reject(function (TelegramChatBroadcastItem $i) use ($events, $now) {
            if (! in_array($i->status, [
                TelegramChatBroadcastItem::STATUS_SKIPPED,
                TelegramChatBroadcastItem::STATUS_REJECTED,
            ], true)) {
                return false;
            }
            if ($i->hasReadyCaption()) {
                // Портрет и подборка не протухают: у них нет своего события, по
                // которому можно было бы судить. Снятая подборка обязана
                // остаться видимой — иначе она исчезает из недельной сетки без
                // причины и без следа, а причина у неё говорящая.
                return false;
            }
            if (! $i->event_id) {
                return true; // ни события, ни площадки — возвращать нечего
            }

            $event = $events->get($i->event_id);
            if (! $event) {
                return true;
            }

            // Тем же правилом, что и возврат ([[PostTiming]]): иначе список
            // предлагает вернуть то, что возврат отдаст без дня, а уборщик
            // через час снимет обратно — кольцо, в котором человек нажимает,
            // а запись исчезает.
            return ! PostTiming::fits($event, $now);
        })->values();

        // Площадки портретов — одним запросом. Без них интерфейс рисовал
        // литерал «(портрет площадки)» без названия: title и venue брались
        // только из события, а у портрета события нет.
        $venues = \App\Models\Venue::query()
            ->whereIn('id', $items->pluck('venue_id')->filter()->all())
            // tg_portrait — тот самый текст, который пишет модель по заявке:
            // без него плашка «текст пишет ИИ» у портрета врала бы всегда.
            ->get(['id', 'name', 'tg_portrait'])
            ->keyBy('id');

        // Картинки — одним запросом на всю ленту. Без этого itemPayload звал
        // findWithDetails на каждый пост, и дважды: под фактический набор и
        // под список кандидатов. На неделе это два десятка запросов вместо
        // одного.
        $this->events->hydrateImagesFor($events);

        // Анонс пишется на ВСЮ группу повторов сразу — один текст на все даты
        // одного спектакля. Значит кнопка «написать заново» в одном посте
        // меняет текст и у соседних, и человек должен это видеть до клика.
        $groupIds = $events->pluck('event_group_id')->filter()->unique()->values();
        $repeats = $groupIds->isEmpty()
            ? collect()
            : Event::query()
                ->whereIn('event_group_id', $groupIds)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->where('start_time', '>=', Carbon::now())
                ->selectRaw('event_group_id, count(*) as c')
                ->groupBy('event_group_id')
                ->pluck('c', 'event_group_id');

        // Подпись пересобираем на чтении — ту же, что уйдёт в канал.
        //
        // Пост стоит в очереди днями, и за это время текст меняется под ним:
        // парсер пишет событию ТГ-анонс, чистка правит описание, у события
        // двигается время. Показывать в админке то, что собрали при постановке,
        // значит врать в превью — а превью для того и сделано, чтобы человек
        // видел настоящий пост. Отправка пересобирает подпись ровно так же.
        //
        // Сборка бесплатная (подстановка в шаблон), запись идёт только если
        // текст действительно изменился. Свой текст не трогаем: его писал
        // человек.
        foreach ($items as $i) {
            if ($i->caption_source === TelegramChatBroadcastItem::CAPTION_MANUAL) {
                continue;
            }
            if (! in_array($i->status, $this->openStatuses(), true)) {
                continue;
            }

            // Портрет собирается на постановке и лежит в очереди неделю: к
            // этому моменту «ближайшее тут» зовёт на прошедшее, а переписанный
            // по заявке tg_portrait в подпись не попадает вовсе. Ровно та же
            // причина, что у событий абзацем выше.
            if ($i->kind === TelegramChatBroadcastItem::KIND_VENUE) {
                $this->buildCaptionFor($i, $broadcast);

                continue;
            }

            if ($i->kind !== TelegramChatBroadcastItem::KIND_EVENT || ! $i->event_id) {
                continue;
            }
            $event = $events->get($i->event_id);
            if ($event) {
                $this->fillCaption($i, $broadcast, $event);
            }
        }

        return response()->json([
            'data' => [
                'channel' => $this->channelPayload($broadcast),
                'items' => $items->map(function (TelegramChatBroadcastItem $i) use ($events, $venues, $repeats) {
                    $event = $events->get($i->event_id);
                    $group = $event?->event_group_id;

                    return $this->itemPayload(
                        $i,
                        $event,
                        $i->venue_id ? $venues->get($i->venue_id) : null,
                        $group ? (int) ($repeats[$group] ?? 1) : null,
                    );
                })->values(),
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

        // Что канал уже показывает — по связи «пост → события», а не по колонке
        // записи: пост-подборка рассказывает о нескольких событиях сразу, и по
        // колонке ни одно из них не считалось бы занятым. На этом читателе
        // держится весь анти-дубль пула: события, стоящие в ленте, исключаются
        // отсюда, а не проверками ниже.
        $inFeed = DB::table('telegram.chat_broadcast_item_events as l')
            ->join('telegram.chat_broadcast_items as i', 'i.id', '=', 'l.item_id')
            ->where('i.broadcast_id', $broadcast->id)
            ->whereIn('i.status', $this->openStatuses())
            ->pluck('l.event_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $feedVenueIds = Event::query()->whereIn('id', $inFeed)->pluck('venue_id')->filter()->unique()->all();

        // Чем занята неделя: первичные темы всего, что канал уже показывает.
        // По ним считается и причина «такой темы ещё не было», и колонка в
        // интерфейсе — считать их дважды в двух местах незачем.
        $feedThemeIds = DB::table('event_interest')
            ->whereIn('event_id', $inFeed)
            ->where('rank', 0)
            ->pluck('interest_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $candidates = Event::query()
            ->active()
            ->upcoming()
            ->with(['venue:id,name', 'primaryInterests:id,name'])
            ->whereHas('community', fn ($q) => $q->where('city_id', $chat->city_id))
            ->whereNotIn('id', $inFeed)
            // Опубликованное не предлагаем. Раньше это объяснялось через
            // UNIQUE(broadcast_id, event_id), но для события, названного
            // подборкой, строки очереди нет и UNIQUE ничего не гарантирует —
            // теперь заслон именно здесь, по связи.
            ->whereDoesntHave('broadcastPosts', function ($q) use ($broadcast) {
                $q->where('broadcast_id', $broadcast->id)->whereNotNull('posted_at');
            })
            // Отклонённое и снятое остывает 30 дней — тот же срок, что у
            // автоподбора (REJECTED_COOLDOWN_DAYS), иначе пул и предложения
            // расходились бы во мнениях.
            ->whereDoesntHave('broadcastPosts', function ($q) use ($broadcast) {
                $q->where('broadcast_id', $broadcast->id)
                    // Только rejected: skipped значит «снято из ленты», и
                    // прятать за это событие на месяц было бы наказанием
                    // за обычную перестановку.
                    ->where('status', TelegramChatBroadcastItem::STATUS_REJECTED)
                    // Полным именем — см. докблок Event::broadcastPosts().
                    ->where('telegram.chat_broadcast_items.updated_at', '>=', now()->subDays(self::REJECTED_COOLDOWN_DAYS));
            })
            // Горизонт от момента публикации: пост 4 октября вправе звать на
            // событие 18-го. От «сейчас» это окно закрывалось бы само собой.
            ->where('start_time', '<=', ($publishAt ?? now())->copy()->addDays(self::SUGGESTIONS_HORIZON_DAYS))
            ->when(
                $publishAt !== null,
                // Правило общее со всеми дверями и буквально одним выражением
                // ([[PostTiming]]::applyFits): не позже начала, а у многодневки
                // — не позже закрытия. Своя копия здесь пускала в предложения
                // уже начавшееся однодневное, а порог многодневки был зашит
                // числом мимо константы.
                fn ($q) => PostTiming::applyFits($q, $publishAt),
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

        $rows = collect($ordered)->map(function (array $pair) use ($feedVenueIds, $chainSizes, $feedThemeIds) {
            [$e, $key] = $pair;

            return [
                'kind' => 'event',
                'event_id' => (int) $e->id,
                'title' => (string) $e->title,
                'venue' => VenueName::label($e->venue?->name) ?: null,
                'chain' => $key,
                // Сколько ещё событий той же сети в пуле — по этому числу
                // интерфейс сворачивает сеть в одну строку.
                'chain_size' => $chainSizes[$key] ?? 1,
                'start_time' => optional($e->start_time)?->toIso8601String(),
                'price_status' => $e->price_status,
                'theme' => $this->themePayload($e),
                'reasons' => $this->reasons($e, $feedVenueIds, $feedThemeIds),
                'event_url' => $this->siteUrl().'/events/'.$e->id,
                // Источник, из которого событие пришло: в посте на него
                // ведёт «Открыть оригинал», а в админке открыть было нечем.
                'original_url' => $this->originalUrl($e),
                'cover' => (is_array($e->getAttribute('images')) ? ($e->getAttribute('images')[0] ?? null) : null),
                'end_time' => optional($e->end_time)?->toIso8601String(),
                'price_min' => $e->price_min,
                'price_max' => $e->price_max,
            ];
        })->values();

        // Портрет площадки — такой же кандидат на пустой слот, как событие.
        // В макете он третьей карточкой: «Портрет: бар «Архив» · площадка · не
        // показывали 6 недель». Ротацию и порядок считает сервис портретов.
        $portrait = $this->venuePortraits->nextPortraitSuggestion($broadcast, $publishAt ?? now());
        if ($portrait !== null) {
            // В НАЧАЛО: под пустой слот админка берёт три первые карточки, а
            // событийных строк до сорока — в хвосте портрет не увидел бы никто.
            $rows->prepend([
                'kind' => 'venue',
                'event_id' => null,
                'venue_id' => $portrait['venue_id'],
                'title' => 'Портрет: '.$portrait['name'],
                'venue' => $portrait['name'],
                'chain' => 'venue:'.$portrait['venue_id'],
                'chain_size' => 1,
                // У площадки темы нет: она не событие. Поле обязано быть у
                // всех карточек пула, иначе фильтр по теме молча потеряет её.
                'theme' => null,
                'start_time' => null,
                'price_status' => null,
                // Сколько фотографий уйдёт — первой строкой: портрет без фото
                // это просто текст в канале, и узнавать об этом постфактум
                // человеку незачем. Фото у портрета берутся у СОБЫТИЙ
                // площадки, поэтому у места без событий их не бывает.
                'photos_count' => (int) ($portrait['photos_count'] ?? 0),
                'reasons' => array_values(array_filter([
                    ((int) ($portrait['photos_count'] ?? 0)) === 0
                        ? 'без фото — уйдёт текстом'
                        : $portrait['photos_count'].' фото',
                    $portrait['weeks_since'] === null
                        ? 'ни разу не показывали'
                        : 'не показывали '.$portrait['weeks_since'].' нед.',
                ])),
                // Ссылка на страницу площадки: без неё портрет был единственной
                // карточкой пула, которую нельзя посмотреть перед тем, как
                // ставить, — а решение принимают именно глядя.
                'event_url' => isset($portrait['venue_id'])
                    ? $this->siteUrl().'/venues/'.$portrait['venue_id']
                    : null,
                'original_url' => null,
                'cover' => $portrait['cover'],
                'end_time' => null,
                'price_min' => null,
                'price_max' => null,
            ]);
        }

        return response()->json(['data' => $rows->values()]);
    }

    /**
     * Опубликовать предложение вне очереди — одним действием.
     *
     * Иначе это два клика с промежуточным состоянием: «Поставить» (а при
     * полной неделе она откажет — свободного слота нет) и затем «Отправить
     * сейчас». Пост вне очереди в сетку не встаёт и ничей день не занимает:
     * его момент — сейчас, а не слот. Зазор между постами при этом остаётся —
     * он стоит на выдаче задач боту.
     */
    public function publishSuggestion(Request $request, int $broadcastId): JsonResponse
    {
        $data = $request->validate(['event_id' => ['required', 'integer']]);

        $broadcast = TelegramChatBroadcast::query()->with('chat')->findOrFail($broadcastId);

        $event = Event::query()->find((int) $data['event_id']);
        if (! $event) {
            return response()->json(['ok' => false, 'error' => 'Событие не найдено.'], 404);
        }

        if (! PostTiming::fits($event, Carbon::now())) {
            return response()->json([
                'ok' => false,
                'error' => $this->tooLateMessage($event),
            ], 422);
        }

        return DB::transaction(function () use ($broadcast, $event) {
            TelegramChatBroadcast::query()->whereKey($broadcast->id)->lockForUpdate()->first();

            $item = TelegramChatBroadcastItem::query()
                ->where('broadcast_id', $broadcast->id)
                ->where('event_id', $event->id)
                ->first();

            if ($item && $item->posted_at !== null) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Это событие уже публиковалось в канале.',
                ], 409);
            }

            // И ДРУГИМ постом — например подборкой, которая его назвала. Своей
            // строки очереди у такого события нет, поэтому проверка выше его
            // не видит.
            $taken = $this->eventTakenByAnotherPost((int) $broadcast->id, (int) $event->id, $item?->id);
            if ($taken) {
                return response()->json(['ok' => false, 'error' => $this->takenMessage($taken)], 409);
            }

            // Снятое и отклонённое оживляем — как при обычной постановке: на
            // (broadcast_id, event_id) стоит UNIQUE, второй записи не создать.
            $item ??= new TelegramChatBroadcastItem;
            $item->broadcast_id = $broadcast->id;
            $item->event_id = $event->id;
            $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
            $item->error_message = null;
            $item->claimed_at = null;
            $item->claim_token = null;
            $item->publish_at = Carbon::now();
            // Вне сетки: момент поста — «сейчас», а не слот. Без признака
            // занятость слота считалась бы по ЧАСУ нажатия, и кнопка,
            // нажатая в 19:05 при слоте 19:00, съедала бы вечерний пост.
            $item->is_off_grid = true;
            if ($item->caption_source !== TelegramChatBroadcastItem::CAPTION_MANUAL) {
                $item->caption = null;
                $item->caption_source = null;
            }
            // Событию без анонса даём время его написать — зачем, см. publishNow().
            $waitForText = $broadcast->ai_text
                && $item->caption_source !== TelegramChatBroadcastItem::CAPTION_MANUAL
                && trim((string) $event->tg_description) === '';
            $item->planned_at = $waitForText ? Carbon::now()->addMinutes($this->textGraceMinutes()) : null;
            if ($waitForText) {
                $item->text_requested_at = Carbon::now();
            }
            $item->save();

            $this->fillCaption($item, $broadcast, $event);

            $willSend = $broadcast->chat?->telegram_chat_id
                ? BroadcastSafety::postingAllowed((int) $broadcast->chat->telegram_chat_id)
                : false;
            $waitUntil = $this->broadcasts->nextPostAllowedAt((int) $broadcast->id, Carbon::now());

            return response()->json([
                'data' => $this->itemPayload(
                    $item->fresh(),
                    Event::query()->with('venue:id,name')->find($item->event_id),
                ),
                'meta' => [
                    'will_send' => $willSend,
                    'wait_minutes' => $waitUntil
                        ? (int) ceil(Carbon::now()->diffInSeconds($waitUntil) / 60)
                        : 0,
                    'waiting_for_text' => $waitForText,
                ],
            ]);
        });
    }

    /** Поставить портрет площадки в ленту канала. */
    public function enqueueVenue(Request $request, int $broadcastId): JsonResponse
    {
        $data = $request->validate([
            'venue_id' => ['required', 'integer'],
            'publish_at' => ['nullable', 'date'],
        ]);

        $broadcast = TelegramChatBroadcast::query()->with('chat')->findOrFail($broadcastId);

        // Портрет этой площадки уже в ленте. Повтор ловить больше нечем: UNIQUE
        // стоит на (broadcast_id, event_id), а у портретов event_id пуст — в
        // постгресе такие строки не конфликтуют, и два поста про одно место
        // прошли бы молча.
        $already = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_VENUE)
            ->where('venue_id', (int) $data['venue_id'])
            ->whereIn('status', $this->openStatuses())
            ->exists();

        if ($already) {
            return response()->json([
                'ok' => false,
                'error' => 'Портрет этой площадки уже стоит в ленте — дождитесь отправки или снимите его.',
            ], 422);
        }

        $publishAt = ! empty($data['publish_at']) ? $this->toUtc($data['publish_at']) : null;

        return DB::transaction(function () use ($broadcast, $data, $publishAt) {
            // Тот же замок, что у постановки события: две вкладки иначе
            // положат два поста в один слот.
            TelegramChatBroadcast::query()->whereKey($broadcast->id)->lockForUpdate()->first();

            $displaced = null;
            if ($publishAt !== null) {
                $occupant = $this->dayOccupant(
                    (int) $broadcast->id,
                    $publishAt,
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
                    $this->regenerateCaption($occupant, $broadcast);
                    $displacedEvent = $occupant->event_id ? Event::query()->find($occupant->event_id) : null;
                    $displaced = ['id' => $occupant->id, 'title' => $displacedEvent?->title];
                }
            }

            try {
                $item = $this->venuePortraits->enqueueVenueManually(
                    (int) $broadcast->id,
                    (int) $data['venue_id'],
                    Carbon::now(),
                    // «Одно в полёте» среди портретов здесь не запрет: человек
                    // ставит руками и осознанно, а повтор той же площадки уже
                    // отклонён выше.
                    force: true,
                    reviewGate: (bool) config('services.bot.broadcast_review_gate'),
                    reviewerTelegramId: $broadcast->chat?->owner?->telegram_id
                        ? (int) $broadcast->chat->owner->telegram_id
                        : null,
                );
            } catch (\RuntimeException $e) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            // День пришёл из интерфейса — ставим его. Не пришёл: постановка уже
            // выбрала ближайший свободный слот сама.
            if ($publishAt !== null) {
                $item->publish_at = $publishAt;
                $item->save();
                $this->buildCaptionFor($item, $broadcast);
            }

            return response()->json([
                'data' => $this->itemPayload(
                    $item->fresh(),
                    null,
                    \App\Models\Venue::query()->find($item->venue_id, ['id', 'name']),
                ),
                'meta' => ['displaced' => $displaced],
            ]);
        });
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

            // И ДРУГИМ постом — см. eventTakenByAnotherPost.
            $taken = $this->eventTakenByAnotherPost((int) $broadcast->id, (int) $event->id, $existing?->id);
            if ($taken) {
                return response()->json(['ok' => false, 'error' => $this->takenMessage($taken)], 409);
            }

            // Та же проверка, что при переносе: пост не может уйти после события.
            // Общий список карточек не привязан ко дню, и перетаскиванием на
            // дальний день можно было поставить анонс уже прошедшего.
            $publishAt = $this->toUtc($data['publish_at'] ?? null);
            if ($publishAt && ! PostTiming::fits($event, $publishAt)) {
                return response()->json([
                    'ok' => false,
                    'error' => $this->tooLateMessage($event),
                ], 422);
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
                    exceptItemId: $existing?->id,
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
            // Предел считаем по ВИДИМОМУ тексту ([[CaptionLength]]): разметка и
            // адреса ссылок в длину не входят, иначе править подборку было
            // нельзя в принципе.
            //
            // Верхняя граница на саму строку остаётся: она про размер запроса,
            // а не про Telegram.
            'caption' => ['sometimes', 'nullable', 'string', 'max:8192', function ($attribute, $value, $fail) {
                if ($value !== null && ! CaptionLength::fits((string) $value)) {
                    $fail('Подпись длиннее '.CaptionLength::LIMIT.' символов — Telegram не примет её к картинкам.');
                }
            }],
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

        // Состояние ДО любых присваиваний — из него соберётся строка истории.
        // Имя намеренно не $before: ниже этим словом уже назван день публикации
        // до правки, и вторая переменная молча затирала бы первую.
        $snapshot = [
            'caption' => $item->caption,
            'caption_source' => $item->caption_source,
            'photo_urls' => $item->photo_urls,
        ];

        // Что человек ДЕЙСТВИТЕЛЬНО изменил. Сравниваем со значением в базе, а
        // не верим факту прихода поля: форма правки шлёт текст всегда, и без
        // сравнения сохранение с одной лишь подвинутой датой навсегда метило бы
        // текст «своим» — после чего он переставал обновляться вслед за
        // событием, причём молча.
        $edited = [];

        if ($request->has('caption')) {
            $caption = trim((string) $data['caption']);
            if ($caption === '') {
                // Пустая правка = «вернуть шаблонный»: сбрасываем и собираем
                // заново — и у события, и у портрета площадки.
                $broadcast = TelegramChatBroadcast::query()->find($item->broadcast_id);
                if ($broadcast) {
                    $item->caption_source = null;
                    $item->forgetEdit(TelegramChatBroadcastItem::EDIT_CAPTION);
                    $item->save();
                    $this->buildCaptionFor($item, $broadcast);
                }
            } elseif ($caption !== trim((string) $item->caption)) {
                $item->caption = $caption;
                // С этой минуты пересборка ленты текст не трогает.
                $item->caption_source = TelegramChatBroadcastItem::CAPTION_MANUAL;
                $edited[] = TelegramChatBroadcastItem::EDIT_CAPTION;
            }
        }

        if ($request->has('photo_urls')) {
            $chosen = $data['photo_urls'] ?? null;

            $wasPhotos = $item->photo_urls;

            if ($chosen === null) {
                $item->photo_urls = null;
                $item->forgetEdit(TelegramChatBroadcastItem::EDIT_PHOTOS);
            } else {
                // Берём ТОЛЬКО картинки самого события. Иначе через ручку
                // можно было бы отправить в канал любую чужую ссылку, а
                // ошибка в адресе всплыла бы уже при публикации.
                $available = match (true) {
                    $item->kind === TelegramChatBroadcastItem::KIND_VENUE && $item->venue_id !== null => $this->venuePortraits->venuePhotoUrls((int) $item->venue_id, self::PHOTO_CANDIDATES),
                    // У подборки своего события нет: её картинки — обложки
                    // названных событий. Без этой ветки список разрешённых
                    // оставался пустым, и любой выбор человека отбивался
                    // ошибкой «среди выбранных есть чужие» — при том, что
                    // сами картинки лента ему показывала.
                    $item->kind === TelegramChatBroadcastItem::KIND_DIGEST => $this->broadcasts->digestPhotoUrls((int) $item->id, self::PHOTO_CANDIDATES),
                    $item->event_id !== null => $this->broadcasts->eventPhotos((int) $item->event_id, self::PHOTO_CANDIDATES),
                    default => [],
                };

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
                if ($item->photo_urls !== $wasPhotos) {
                    $edited[] = TelegramChatBroadcastItem::EDIT_PHOTOS;
                }
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
                    $itemEvents = $this->itemEvents($item);
                    if (! PostTiming::fitsAll($itemEvents, $newAt)) {
                        return [$this->tooLateMessageForEvents($itemEvents), 422];
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
            $wasAt = optional($item->publish_at)?->toIso8601String();
            $item->publish_at = $newAt;
            $after = optional($item->publish_at)?->toDateString();

            // Перенос поста — тоже ручная правка, и до сих пор он не оставлял
            // никакого следа: лента выглядела одинаково, собрал ли её сервис
            // или переставил человек.
            if ($wasAt !== optional($item->publish_at)?->toIso8601String()) {
                $edited[] = TelegramChatBroadcastItem::EDIT_TIME;
            }

            // Дата поменялась — шаблонный текст пересобираем: в нём есть
            // «сегодня» и «завтра», и они считаются от дня публикации.
            // Свой текст не трогаем: его писал человек.
            if (
                $before !== $after
                && $item->caption_source !== TelegramChatBroadcastItem::CAPTION_MANUAL
                && ! $request->has('caption')
            ) {
                $bc = TelegramChatBroadcast::query()->find($item->broadcast_id);
                if ($bc) {
                    $item->caption_source = null;
                    $item->save();
                    // Раньше здесь требовалось событие, и у портрета площадки
                    // перенос дня просто обнулял текст: запись уходила в бота
                    // пустой и висела там вечно.
                    $this->buildCaptionFor($item, $bc);
                }
            }
        }

        if ($request->has('is_pinned')) {
            // Закрепление в след не пишем: у него своя пометка в ленте, и
            // «правлено» на каждом закреплённом посте значило бы уже ничего.
            $item->is_pinned = (bool) $data['is_pinned'];
        }

        // Пишем только когда что-то действительно изменилось: иначе история
        // заросла бы пустыми строками от каждого открытия карточки.
        if ($edited !== []) {
            $this->saveRevision($item, $edited, $snapshot);
        }

        $item->markEdited($edited);
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
     * Отменить отказ — вернуть событие в пул предложений.
     *
     * «Больше не предлагать» прячет событие от подбора на 30 дней
     * (REJECTED_COOLDOWN_DAYS), и до сих пор это было необратимо из интерфейса:
     * запись со статусом rejected не показывалась нигде, а событие просто
     * пропадало из предложений. Случайное нажатие стоило месяца.
     *
     * Возвращаем в skipped, а не удаляем строку: skipped означает «снято из
     * ленты» и подбору не мешает, а история отказа остаётся. Плюс на
     * (broadcast_id, event_id) стоит UNIQUE — второй строки всё равно не
     * создать, и удалять существующую значило бы терять след.
     */
    public function unreject(int $itemId): JsonResponse
    {
        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);

        if ($item->status !== TelegramChatBroadcastItem::STATUS_REJECTED) {
            return response()->json([
                'ok' => false,
                'error' => 'Это событие не отклоняли — возвращать нечего.',
            ], 409);
        }

        $item->status = TelegramChatBroadcastItem::STATUS_SKIPPED;
        $item->error_message = 'отказ отменён — событие снова в пуле';
        $item->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Что попадёт в подборку, если бы она уходила сейчас.
     *
     * GET /api/admin/broadcast/channels/{id}/digest-preview?at=ISO
     *
     * Ничего не сохраняет. Подборка собирается перед самой отправкой, и до
     * этого момента запись в ленте пуста: человек видит бронь, но не знает,
     * что в ней окажется. Превью отвечает на этот вопрос, не фиксируя состав —
     * фиксировать его заранее нельзя, в том и смысл поздней сборки.
     */
    public function digestPreview(Request $request, int $broadcastId): JsonResponse
    {
        $broadcast = TelegramChatBroadcast::query()->with('chat.city')->findOrFail($broadcastId);

        $at = $request->query('at')
            ? Carbon::parse((string) $request->query('at'))
            : Carbon::now();

        // Открытая бронь этого канала — не «чужой пост»: если состав ей уже
        // собрали, превью без этой оговорки показало бы СЛЕДУЮЩУЮ тройку и
        // соврало бы про то, что лежит в записи.
        $booked = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
            ->whereNull('posted_at')
            ->orderBy('publish_at')
            ->first();

        $draft = $this->digestComposer->compose($broadcast, $at, $booked);

        if ($draft === null) {
            return response()->json(['data' => null, 'meta' => [
                'reason' => 'Ни одна тема не набрала состава: нужно минимум '
                    .(int) config('broadcast_digest.min_events', 5).' событий на '
                    .(int) config('broadcast_digest.min_venues', 3).' площадках.',
            ]]);
        }

        return response()->json(['data' => [
            'theme' => $draft['theme']['title'] ?? null,
            'caption' => $draft['caption'],
            'total' => $draft['total'],
            'venues' => $draft['venues'],
            'event_ids' => $draft['event_ids'],
            // Состав на момент показа, а не на момент отправки: за неделю он
            // изменится, и обещать обратное было бы враньём.
            'at' => $at->toIso8601String(),
        ]]);
    }

    /**
     * Поставить подборку ВНЕ ОЧЕРЕДИ.
     *
     * POST /api/admin/broadcast/channels/{id}/digest-now
     *
     * Рубрика выходит раз в неделю, своим днём. Этой ручкой владелец ставит
     * ещё одну — когда в афише случилось что-то, чего ждать до вторника
     * глупо: бесплатные выходные, фестиваль, дешёвая неделя.
     *
     * ВНЕ СЕТКИ (`is_off_grid`), и это не косметика:
     *  — слот дня остаётся свободным для обычных постов (планировщик считает
     *    занятость по ключу «дата + час» и внесеточные пропускает);
     *  — очередная недельная подборка не отменяется (BroadcastDigestBooking
     *    считает открытой только сеточную).
     *
     * Состав собираем сразу, а не перед отправкой: человек нажал кнопку,
     * чтобы УВИДЕТЬ, что получится, и успеть поправить. Рубрику выбирает тот
     * же отбор, что и автосборку, — с оглядкой на прошлые посты.
     */
    public function digestNow(int $channelId): JsonResponse
    {
        $broadcast = TelegramChatBroadcast::query()->with('chat.city')->findOrFail($channelId);

        $at = Carbon::now()->addMinutes(
            max(1, (int) config('services.bot.broadcast_text_grace_minutes', 6)),
        );

        $draft = $this->digestComposer->compose($broadcast, $at);
        if ($draft === null) {
            return response()->json([
                'ok' => false,
                'error' => 'Ни одна рубрика не набрала состава — ставить пока нечего.',
            ], 422);
        }

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->publish_at = $at;
        $item->is_off_grid = true;
        $item->save();

        $this->broadcasts->applyDigestDraft($item, $draft);

        Log::info('admin:broadcast:digest-now', [
            'actor_id' => request()->user()?->id,
            'broadcast_id' => $broadcast->id,
            'item_id' => $item->id,
            'theme' => $draft['theme_slug'] ?? null,
            'named' => count($draft['event_ids']),
        ]);

        return response()->json(['data' => $this->itemPayload($item->fresh(), null, null)], 201);
    }

    /**
     * Собрать подборку сейчас: записать текст и состав в саму запись.
     *
     * POST /api/admin/broadcast/items/{id}/compose
     * Тело: keep_roster — не трогать выбор событий, только пересобрать подпись.
     *
     * Обычно подборка собирается перед отправкой — так в неё попадает вся
     * неделя. Эта ручка нужна, когда человек хочет увидеть и ПОПРАВИТЬ текст
     * заранее: после неё запись перестаёт быть пустой, и перед отправкой
     * пересобираться не будет. Состав тоже фиксируется — названные события
     * закрываются для собственных постов сразу.
     *
     * ДВЕ РАЗНЫЕ ОПЕРАЦИИ ПОД ОДНОЙ КНОПКОЙ — так было, и так стоило поста.
     * «Собрать заново» ПЕРЕИЗБИРАЛО тройку, и вместе с ней обнулялся текст, за
     * который уже заплачено, и ручная правка подписи. Человек же чаще хочет
     * другого: тех же событий со свежими ценой, временем и диапазоном дат в
     * шапке. Это `keep_roster`, и он зовёт recompose — состав не трогается,
     * строки модели остаются на своих событиях.
     */
    public function composeDigest(Request $request, int $itemId): JsonResponse
    {
        $data = $request->validate([
            'keep_roster' => ['sometimes', 'boolean'],
        ]);

        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);

        if ($item->kind !== TelegramChatBroadcastItem::KIND_DIGEST) {
            return response()->json(['ok' => false, 'error' => 'Собрать можно только подборку.'], 422);
        }
        if ($item->posted_at !== null) {
            return response()->json(['ok' => false, 'error' => 'Пост уже опубликован.'], 409);
        }
        if ($blocked = $this->digestNotEditable($item)) {
            return $blocked;
        }

        $broadcast = TelegramChatBroadcast::query()->with('chat.city')->findOrFail($item->broadcast_id);
        $at = $item->publish_at ?? Carbon::now();

        // Держать состав можно только если он есть; у пустой брони держать
        // нечего, и просьба молча превращается в обычную сборку.
        $keep = (bool) ($data['keep_roster'] ?? false)
            && DB::table('telegram.chat_broadcast_item_events')->where('item_id', $item->id)->exists();

        $draft = $keep
            ? $this->digestComposer->recompose($item, $broadcast, $at)
            // $item третьим аргументом: иначе повторное нажатие выбирает ДРУГУЮ
            // тройку — состав, записанный первым нажатием, вычитается как «канал
            // это уже показывает».
            : $this->digestComposer->compose($broadcast, $at, $item);

        if ($draft === null) {
            return response()->json(['ok' => false, 'error' => $keep
                ? 'Состав рассыпался: события удалили или они уже начались. Нужен полный перевыбор.'
                : 'Ни одна тема не набрала состава — собирать нечего.'], 422);
        }

        $this->rememberManualCaption($item, $keep ? 'подпись пересобрана по тому же составу' : 'состав перевыбран заново');
        $this->broadcasts->applyDigestDraft($item, $draft);

        return response()->json(['data' => $this->itemPayload($item->fresh(), null, null)]);
    }

    /**
     * Заменить одно событие в составе подборки.
     *
     * Тело: out — кого выкинуть, in — кого поставить. Плюс два осознанных
     * согласия, каждое под своим флагом, потому что каждое чем-то платит:
     *   swap — новое событие уже стоит в ленте отдельным постом, и этот пост
     *          будет снят (иначе канал показал бы одно и то же дважды);
     *   cool — выброшенному событию ставится тридцатидневное «не предлагать»,
     *          то же самое, что у кнопки отказа в ленте.
     *
     * Подпись пересобирается ЗДЕСЬ ЖЕ, в одной транзакции с составом, и это не
     * украшение. Альбом доставка собирает по СОСТАВУ в момент опроса, а подпись
     * не трогает, если она непустая: разойдись эти двое — в канал уехал бы
     * текст про одно событие с картинкой от другого, молча и без единой ошибки
     * в логе.
     */
    public function replaceDigestEvent(Request $request, int $itemId): JsonResponse
    {
        $data = $request->validate([
            'out' => ['required', 'integer'],
            'in' => ['required', 'integer', 'different:out'],
            'swap' => ['sometimes', 'boolean'],
            'cool' => ['sometimes', 'boolean'],
        ]);

        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);

        if ($item->kind !== TelegramChatBroadcastItem::KIND_DIGEST) {
            return response()->json(['ok' => false, 'error' => 'Состав есть только у подборки.'], 422);
        }
        if ($item->posted_at !== null) {
            return response()->json(['ok' => false, 'error' => 'Пост уже опубликован.'], 409);
        }
        if ($blocked = $this->digestNotEditable($item)) {
            return $blocked;
        }
        // Ручной текст пересобрать нельзя, не потеряв его. У «собрать заново» и
        // «написать сейчас» на этот случай снимок в историю, а у замены смысла
        // в снимке нет: человек правил ТЕКСТ, а не состав.
        if ($item->caption_source === TelegramChatBroadcastItem::CAPTION_MANUAL) {
            return response()->json([
                'ok' => false,
                'error' => 'Текст поста правлен целиком. Верните шаблонный — тогда состав можно менять.',
            ], 409);
        }

        $roster = DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)->pluck('event_id')->map(fn ($v) => (int) $v)->all();

        if (! in_array((int) $data['out'], $roster, true)) {
            return response()->json(['ok' => false, 'error' => 'Этого события в подборке нет.'], 422);
        }
        if (in_array((int) $data['in'], $roster, true)) {
            return response()->json(['ok' => false, 'error' => 'Это событие уже названо в подборке.'], 422);
        }

        $broadcast = TelegramChatBroadcast::query()->with('chat.city')->findOrFail($item->broadcast_id);
        $publishAt = $item->publish_at ? Carbon::parse($item->publish_at) : Carbon::now();

        // Живо ли ещё событие, выбранное в панели. Панель грузится один раз, а
        // события снимают с публикации постоянно: без этой проверки мёртвый
        // кандидат ронял бы пересборку уже после подмены связи.
        $fresh = Event::query()
            ->whereKey((int) $data['in'])
            ->active()
            ->where(fn ($q) => PostTiming::applyFits($q, $publishAt, 0))
            ->exists();

        if (! $fresh) {
            return response()->json([
                'ok' => false,
                'error' => 'Этого события больше нет в подборе — обновите список кандидатов.',
            ], 422);
        }

        // Дубль канала: событие уже стоит своим постом. Меняем только с прямого
        // согласия и вместе со снятием того поста.
        $taken = $this->eventTakenByAnotherPost($broadcast->id, (int) $data['in'], $item->id);
        if ($taken !== null && ! $request->boolean('swap')) {
            return response()->json([
                'ok' => false,
                'error' => $this->takenMessage($taken),
                'data' => ['needs_swap' => true, 'post_item_id' => $taken->id],
            ], 409);
        }
        // Тем же окном, что и пул: иначе кандидат, показанный полмесяца назад,
        // висел бы в панели и отбивался отказом при каждом нажатии.
        if ($taken !== null && $taken->posted_at !== null
            && Carbon::parse($taken->posted_at)->gt(Carbon::now()->subDays(BroadcastDigestComposer::SHOWN_WINDOW_DAYS))) {
            return response()->json([
                'ok' => false,
                'error' => 'Канал показывал это событие на этой неделе — в подборке оно будет повтором.',
            ], 409);
        }
        if ($taken !== null && $taken->kind !== TelegramChatBroadcastItem::KIND_EVENT) {
            // Событие занято ЧУЖОЙ подборкой или портретом. Снять такой пост
            // целиком ради одной строки — потерять ещё два события заодно.
            return response()->json([
                'ok' => false,
                'error' => 'Это событие занято другой рубрикой — уберите его оттуда заменой, а не отсюда.',
            ], 409);
        }
        if ($taken !== null && $taken->is_pinned) {
            // Закрепление — прямое решение человека, и во всех остальных местах
            // оно жёсткий запрет. Молча снять его одной кнопкой нельзя.
            return response()->json([
                'ok' => false,
                'error' => 'Тот пост закреплён. Снимите закрепление, если правда хотите его убрать.',
            ], 409);
        }
        if ($taken !== null && $taken->claimed_at !== null
            && Carbon::parse($taken->claimed_at)->gt(Carbon::now()->subSeconds(TelegramChatBroadcastService::CLAIM_LEASE_SECONDS))) {
            // Тот пост уже уехал боту задачей: сними его сейчас — и событие
            // выйдет дважды, отдельным постом и строкой подборки.
            return response()->json([
                'ok' => false,
                'error' => 'Тот пост уже взят на отправку — обмен невозможен.',
            ], 409);
        }

        // Провал ВНУТРИ транзакции — только исключением. `return null` из
        // замыкания Laravel считает нормальным завершением и коммитит: состав
        // остался бы подменённым, подпись — про прежнюю тройку, а чужой пост
        // снятым, и всё это под ответом «подборка осталась как была».
        try {
            $out = DB::transaction(function () use ($item, $broadcast, $data, $publishAt, $taken, $request) {
                if ($taken !== null) {
                    // Снимаем, а не отклоняем: это не отказ по качеству, событие
                    // просто переезжает в подборку. skipped подбору не мешает — и
                    // если подборка его потом выкинет, оно вернётся в ленту.
                    $taken->status = TelegramChatBroadcastItem::STATUS_SKIPPED;
                    $taken->error_message = 'переехало в подборку недели';
                    $taken->publish_at = null;
                    $taken->claimed_at = null;
                    $taken->claim_token = null;
                    $taken->save();
                }

                $ok = $this->broadcasts->replaceDigestEvent(
                    $item,
                    $broadcast,
                    (int) $data['out'],
                    (int) $data['in'],
                    $publishAt,
                );

                if (! $ok) {
                    throw new DigestRecomposeFailed;
                }

                $cooled = $request->boolean('cool')
                    && $this->coolDownEvent($broadcast->id, (int) $data['out']);

                // Ручной выбор картинок держится белым списком из состава: после
                // замены прежний набор ему больше не отвечает, и следующая правка
                // формы отбилась бы 422. Снимаем вместе с пометкой о правке —
                // иначе карточка обещает правку, которой больше нет.
                if (is_array($item->photo_urls)) {
                    $item->photo_urls = null;
                    $item->forgetEdit(TelegramChatBroadcastItem::EDIT_PHOTOS);
                    $item->save();
                }

                return ['item' => $item->fresh(), 'cooled' => $cooled];
            });
        } catch (DigestRecomposeFailed) {
            return response()->json([
                'ok' => false,
                'error' => 'Новый состав не собрался — замена отменена, подборка осталась как была.',
            ], 409);
        }

        return response()->json([
            'data' => $this->itemPayload($out['item'], null, null),
            // Остывание могло не встать: причина — в coolDownEvent().
            'cooled' => $out['cooled'],
        ]);
    }

    /**
     * Сколько строк подборка обязана сохранить.
     *
     * Одна строка — это уже не подборка, а пост про событие, и шапка рубрики
     * над ней обещает список, которого нет. Две — минимум, при котором
     * заголовок не врёт.
     */
    private const DIGEST_MIN_ROSTER = 2;

    /**
     * Добавить строку в состав.
     *
     * POST /api/admin/broadcast/items/{id}/digest-events/add
     * Тело: in — событие, swap — согласие снять его собственный пост.
     *
     * Правила автоотбора («одна строка на день», «одна на площадку», «без
     * площадки не называем») на этот путь не действуют: они охраняют сборку от
     * случайного состава, а здесь состав выбирает человек. Зато действуют все
     * проверки замены — живое ли событие, не занято ли оно другим постом.
     */
    public function addDigestEvent(Request $request, int $itemId): JsonResponse
    {
        $data = $request->validate([
            'in' => ['required', 'integer'],
            'swap' => ['sometimes', 'boolean'],
        ]);

        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);
        if ($blocked = $this->digestRosterGuard($item)) {
            return $blocked;
        }

        $roster = $this->rosterIds($item);
        if (in_array((int) $data['in'], $roster, true)) {
            return response()->json(['ok' => false, 'error' => 'Это событие уже названо в подборке.'], 422);
        }

        $broadcast = TelegramChatBroadcast::query()->with('chat.city')->findOrFail($item->broadcast_id);
        $publishAt = $item->publish_at ? Carbon::parse($item->publish_at) : Carbon::now();

        $taken = null;
        if ($blocked = $this->digestIncomingGuard($request, $broadcast, $item, (int) $data['in'], $publishAt, $taken)) {
            return $blocked;
        }

        try {
            $fresh = DB::transaction(function () use ($item, $broadcast, $data, $publishAt, $taken) {
                $this->releaseTakenPost($taken);

                if (! $this->broadcasts->addDigestEvent($item, $broadcast, (int) $data['in'], $publishAt)) {
                    throw new DigestRecomposeFailed;
                }

                $this->dropManualPhotos($item);

                return $item->fresh();
            });
        } catch (DigestRecomposeFailed) {
            return response()->json([
                'ok' => false,
                'error' => 'Состав с этой строкой не собрался — подборка осталась как была.',
            ], 409);
        }

        // Подпись меряем ПОСЛЕ сборки, а не считаем заранее: сборка сама
        // снимает фразы с конца, когда длинно, и до неё настоящей длины нет.
        if (! CaptionLength::fits((string) $fresh->caption)) {
            $this->broadcasts->removeDigestEvent($fresh, $broadcast, (int) $data['in'], $publishAt);

            return response()->json([
                'ok' => false,
                'error' => 'С этой строкой подпись длиннее, чем принимает телеграм. Уберите другую или укоротите текст.',
            ], 422);
        }

        return response()->json(['data' => $this->itemPayload($fresh, null, null)]);
    }

    /**
     * Убрать строку из состава.
     *
     * POST /api/admin/broadcast/items/{id}/digest-events/remove
     * Тело: out — событие, cool — не предлагать его какое-то время.
     */
    public function removeDigestEvent(Request $request, int $itemId): JsonResponse
    {
        $data = $request->validate([
            'out' => ['required', 'integer'],
            'cool' => ['sometimes', 'boolean'],
        ]);

        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);
        if ($blocked = $this->digestRosterGuard($item)) {
            return $blocked;
        }

        $roster = $this->rosterIds($item);
        if (! in_array((int) $data['out'], $roster, true)) {
            return response()->json(['ok' => false, 'error' => 'Этого события в подборке нет.'], 422);
        }
        if (count($roster) <= self::DIGEST_MIN_ROSTER) {
            return response()->json([
                'ok' => false,
                'error' => 'В подборке должно остаться хотя бы две строки — иначе это пост про одно событие.',
            ], 422);
        }

        $broadcast = TelegramChatBroadcast::query()->with('chat.city')->findOrFail($item->broadcast_id);
        $publishAt = $item->publish_at ? Carbon::parse($item->publish_at) : Carbon::now();

        try {
            $out = DB::transaction(function () use ($item, $broadcast, $data, $publishAt, $request) {
                if (! $this->broadcasts->removeDigestEvent($item, $broadcast, (int) $data['out'], $publishAt)) {
                    throw new DigestRecomposeFailed;
                }

                $cooled = $request->boolean('cool')
                    && $this->coolDownEvent($broadcast->id, (int) $data['out']);

                $this->dropManualPhotos($item);

                return ['item' => $item->fresh(), 'cooled' => $cooled];
            });
        } catch (DigestRecomposeFailed) {
            return response()->json([
                'ok' => false,
                'error' => 'Состав без этой строки не собрался — подборка осталась как была.',
            ], 409);
        }

        return response()->json([
            'data' => $this->itemPayload($out['item'], null, null),
            'cooled' => $out['cooled'],
        ]);
    }

    /**
     * Переставить строки состава.
     *
     * POST /api/admin/broadcast/items/{id}/digest-events/reorder
     * Тело: order — ВЕСЬ состав в новом порядке.
     *
     * Целиком, а не «подвинь вверх»: так ответ не зависит от того, совпала ли
     * картинка на экране с базой, и частичного порядка не бывает.
     */
    public function reorderDigestEvents(Request $request, int $itemId): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);
        if ($blocked = $this->digestRosterGuard($item)) {
            return $blocked;
        }

        $order = array_values(array_unique(array_map('intval', $data['order'])));
        $roster = $this->rosterIds($item);

        sort($order);
        $sorted = $roster;
        sort($sorted);
        if ($order !== $sorted) {
            return response()->json([
                'ok' => false,
                'error' => 'Порядок прислан не для этого состава — обновите страницу.',
            ], 409);
        }

        $broadcast = TelegramChatBroadcast::query()->with('chat.city')->findOrFail($item->broadcast_id);
        $publishAt = $item->publish_at ? Carbon::parse($item->publish_at) : Carbon::now();

        try {
            $fresh = DB::transaction(function () use ($item, $broadcast, $data, $publishAt) {
                $ids = array_values(array_unique(array_map('intval', $data['order'])));
                if (! $this->broadcasts->reorderDigestEvents($item, $broadcast, $ids, $publishAt)) {
                    throw new DigestRecomposeFailed;
                }

                return $item->fresh();
            });
        } catch (DigestRecomposeFailed) {
            return response()->json([
                'ok' => false,
                'error' => 'Подборка в этом порядке не собралась — порядок остался прежним.',
            ], 409);
        }

        return response()->json(['data' => $this->itemPayload($fresh, null, null)]);
    }

    /**
     * Общие запреты на правку состава — те же, что у замены строки.
     *
     * Ручной текст сюда попадает отдельным отказом: подпись описывает состав,
     * и поменять состав, не тронув текст, нельзя. У «собрать заново» на этот
     * случай снимок в историю, у правки состава снимка нет.
     */
    private function digestRosterGuard(TelegramChatBroadcastItem $item): ?JsonResponse
    {
        if ($item->kind !== TelegramChatBroadcastItem::KIND_DIGEST) {
            return response()->json(['ok' => false, 'error' => 'Состав есть только у подборки.'], 422);
        }
        if ($item->posted_at !== null) {
            return response()->json(['ok' => false, 'error' => 'Пост уже опубликован.'], 409);
        }
        if ($blocked = $this->digestNotEditable($item)) {
            return $blocked;
        }
        if ($item->caption_source === TelegramChatBroadcastItem::CAPTION_MANUAL) {
            return response()->json([
                'ok' => false,
                'error' => 'Текст поста правлен целиком. Верните шаблонный — тогда состав можно менять.',
            ], 409);
        }

        return null;
    }

    /**
     * Можно ли взять это событие в подборку: живо ли оно и не занято ли постом.
     *
     * Те же проверки, что у замены строки, — вынесены, чтобы «добавить» не
     * оказалось дверью в обход них. $taken заполняется постом, который придётся
     * снять, если человек согласился обменом.
     */
    private function digestIncomingGuard(
        Request $request,
        TelegramChatBroadcast $broadcast,
        TelegramChatBroadcastItem $item,
        int $inEventId,
        Carbon $publishAt,
        ?TelegramChatBroadcastItem &$taken,
    ): ?JsonResponse {
        $fresh = Event::query()
            ->whereKey($inEventId)
            ->active()
            ->where(fn ($q) => PostTiming::applyFits($q, $publishAt, 0))
            ->exists();

        if (! $fresh) {
            return response()->json([
                'ok' => false,
                'error' => 'Этого события больше нет в подборе — обновите список кандидатов.',
            ], 422);
        }

        $taken = $this->eventTakenByAnotherPost($broadcast->id, $inEventId, $item->id);
        if ($taken === null) {
            return null;
        }

        if (! $request->boolean('swap')) {
            return response()->json([
                'ok' => false,
                'error' => $this->takenMessage($taken),
                'data' => ['needs_swap' => true, 'post_item_id' => $taken->id],
            ], 409);
        }
        if ($taken->posted_at !== null
            && Carbon::parse($taken->posted_at)->gt(Carbon::now()->subDays(BroadcastDigestComposer::SHOWN_WINDOW_DAYS))) {
            return response()->json([
                'ok' => false,
                'error' => 'Канал показывал это событие на этой неделе — в подборке оно будет повтором.',
            ], 409);
        }
        if ($taken->kind !== TelegramChatBroadcastItem::KIND_EVENT) {
            return response()->json([
                'ok' => false,
                'error' => 'Это событие занято другой рубрикой — уберите его оттуда заменой, а не отсюда.',
            ], 409);
        }
        if ($taken->is_pinned) {
            return response()->json([
                'ok' => false,
                'error' => 'Тот пост закреплён. Снимите закрепление, если правда хотите его убрать.',
            ], 409);
        }
        if ($taken->claimed_at !== null
            && Carbon::parse($taken->claimed_at)->gt(Carbon::now()->subSeconds(TelegramChatBroadcastService::CLAIM_LEASE_SECONDS))) {
            return response()->json([
                'ok' => false,
                'error' => 'Тот пост уже взят на отправку — обмен невозможен.',
            ], 409);
        }

        return null;
    }

    /** Снять пост, у которого событие забирает подборка. */
    private function releaseTakenPost(?TelegramChatBroadcastItem $taken): void
    {
        if ($taken === null) {
            return;
        }

        // Снимаем, а не отклоняем: это не отказ по качеству, событие просто
        // переезжает в подборку.
        $taken->status = TelegramChatBroadcastItem::STATUS_SKIPPED;
        $taken->error_message = 'переехало в подборку недели';
        $taken->publish_at = null;
        $taken->claimed_at = null;
        $taken->claim_token = null;
        $taken->save();
    }

    /**
     * Снять ручной выбор картинок после правки состава.
     *
     * Белый список картинок держится составом: после правки прежний набор ему
     * больше не отвечает, и следующая правка формы отбилась бы 422.
     */
    private function dropManualPhotos(TelegramChatBroadcastItem $item): void
    {
        if (is_array($item->photo_urls)) {
            $item->photo_urls = null;
            $item->forgetEdit(TelegramChatBroadcastItem::EDIT_PHOTOS);
            $item->save();
        }
    }

    /**
     * Почему подборку сейчас трогать нельзя — или null, если можно.
     *
     * Один гард на все кнопки, которые меняют состав, подпись или digest_meta:
     * замена позиции, «собрать заново», «написать сейчас». Раздельные проверки
     * жили только на замене, и соседние кнопки той же модалки обходили её
     * физику — а физика у всех трёх одна.
     */
    private function digestNotEditable(TelegramChatBroadcastItem $item): ?JsonResponse
    {
        // Клейм ставится в том же проходе, где задача с УЖЕ СОБРАННЫМИ подписью
        // и картинками уходит боту: правка попала бы в базу, но не в канал —
        // админка показывала бы одно, подписчик видел бы другое.
        if ($item->claimed_at !== null
            && Carbon::parse($item->claimed_at)->gt(Carbon::now()->subSeconds(TelegramChatBroadcastService::CLAIM_LEASE_SECONDS))) {
            return response()->json([
                'ok' => false,
                'error' => 'Пост уже взят на отправку — менять поздно.',
            ], 409);
        }

        // Заявка на текст в полёте: парсер перезапишет digest_meta целиком по
        // тому составу, который прочитал в начале генерации.
        if ($item->text_requested_at !== null) {
            return response()->json([
                'ok' => false,
                'error' => 'Идёт заказ текста у ИИ — дождитесь, он занимает меньше минуты.',
            ], 409);
        }

        return null;
    }

    /**
     * Тридцатидневное «не предлагать» — тем же приёмом, что кнопка отказа.
     *
     * Остывание в этом проекте выражено ОДНИМ способом: запись очереди со
     * статусом rejected на паре (канал, событие). Второго механизма заводить
     * нельзя — иначе «не предлагать» значило бы разное в ленте и в подборке.
     * У события, которое никогда не было отдельным постом, такой записи нет
     * вовсе, поэтому её здесь и создаём.
     */
    private function coolDownEvent(int $broadcastId, int $eventId): bool
    {
        $item = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->where('event_id', $eventId)
            ->first();

        if ($item === null) {
            $item = new TelegramChatBroadcastItem;
            $item->broadcast_id = $broadcastId;
            $item->event_id = $eventId;
        } elseif ($item->posted_at !== null) {
            // Отправленное не перекрашиваем: posted — это факт, а не решение.
            // Отвечаем false, чтобы владелец не думал, будто остывание встало.
            return false;
        }

        $item->status = TelegramChatBroadcastItem::STATUS_REJECTED;
        $item->error_message = 'снято из подборки: больше не предлагать';
        $item->publish_at = null;
        $item->claimed_at = null;
        $item->claim_token = null;
        $item->save();

        return true;
    }

    /**
     * Чем можно заменить позицию в подборке. ТОЛЬКО ЧТЕНИЕ.
     *
     * Первый шаг к замене позиции и одновременно ответ на вопрос, стоит ли её
     * вообще городить: если у темы на эту неделю нет ни одного кандидата без
     * споров, менять всё равно не на что, и дешевле это увидеть, чем построить.
     *
     * Кандидаты считает композитор тем же пулом, которым собирает подборку сам,
     * — иначе человек выбирал бы из событий, которые автомат никогда бы не взял.
     */
    public function digestCandidates(Request $request, int $itemId): JsonResponse
    {
        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);

        if ($item->kind !== TelegramChatBroadcastItem::KIND_DIGEST) {
            return response()->json(['ok' => false, 'error' => 'Кандидаты есть только у подборки.'], 422);
        }

        if ($item->digestTheme() === null) {
            return response()->json([
                'ok' => false,
                'error' => 'Состав ещё не собран — сначала «Собрать и править», потом выбирать замену.',
            ], 422);
        }

        $broadcast = TelegramChatBroadcast::query()->with('chat.city')->findOrFail($item->broadcast_id);

        $out = $this->digestComposer->candidatesForItem(
            $item,
            $broadcast,
            $item->publish_at ? Carbon::parse($item->publish_at) : Carbon::now(),
            // Какую строку меняем: она не должна спорить сама с собой.
            $request->integer('out') ?: null,
        );

        if ($out === null) {
            return response()->json(['ok' => false, 'error' => 'Тема записи неизвестна — пересоберите подборку.'], 422);
        }

        return response()->json(['data' => [
            'theme' => $out['theme_slug'],
            // Обмен с лентой — отдельным списком, см. candidatesForItem.
            'in_feed' => $out['in_feed'],
            // Состав отдаём ТОТ ЖЕ, по которому считались пометки. linkedEvents
            // читает связь без фильтров по живости и сроку, composer — с ними:
            // выпади из состава удалённое событие, и «строка 2» в пометке
            // указывала бы не на ту строку, что видна на экране.
            'named' => array_map(fn ($e, $i) => [
                'line' => $i + 1,
                'id' => (int) $e->id,
                'title' => (string) $e->title,
                'venue' => VenueName::label($e->venue_name) ?: null,
                'start_time' => $e->start_time ? Carbon::parse($e->start_time)->toIso8601String() : null,
                'url' => $this->siteUrl().'/events/'.$e->id,
            ], $out['named'], array_keys($out['named'])),
            'candidates' => $out['rows'],
        ]]);
    }

    /** Сколько суток отправленного лента показывает без отдельной просьбы. */
    private const HISTORY_DAYS = 7;

    /**
     * Окно, в котором повтор площадки считается повтором.
     *
     * Неделя: столько подписчик помнит ленту. Два поста одной сети через
     * девять дней — не повтор, а совпадение, и снимать за него пост незачем.
     * Этим же окном считает предупреждение над лентой — иначе кнопка и надпись
     * говорят о разных вещах, и человек жмёт то, что ему не поможет.
     */
    public const DIVERSIFY_WINDOW_DAYS = 7;

    /** Сколько версий поста храним: история нужна для отмены, а не для архива. */
    private const REVISIONS_KEPT = 20;

    /**
     * Сохранить ручной текст, который сейчас будет затёрт машиной.
     *
     * Две кнопки подборки — «собрать заново» и «написать сейчас» — безусловно
     * ставят caption от машины: первая через applyDigestDraft, вторая через
     * holdDigestForText, которая обнуляет подпись вовсе. Обе проверяли только
     * kind и posted_at, и обе молча уничтожали час ручной работы, не оставляя
     * следа НИГДЕ: saveRevision звали ровно два места — правка карточки и
     * откат к версии.
     *
     * Отказывать нельзя: человек, нажавший «собрать заново», именно этого и
     * хочет. Поэтому снимок, а не запрет — текст остаётся в истории, и его
     * видно там же, где остальные версии, кнопкой «вернуть».
     *
     * Только manual и только непустое: шаблонная подпись пересобирается из
     * события в любой момент, хранить её версии — значит забить историю тем,
     * что и так воспроизводится.
     */
    private function rememberManualCaption(TelegramChatBroadcastItem $item, string $reason): void
    {
        if ($item->caption_source !== TelegramChatBroadcastItem::CAPTION_MANUAL) {
            return;
        }
        if (trim((string) $item->caption) === '') {
            return;
        }

        $this->saveRevision($item, [$reason], [
            'caption' => $item->caption,
            'caption_source' => $item->caption_source,
            'photo_urls' => $item->photo_urls,
        ]);
    }

    /**
     * Состав подборки — номера событий, как он есть сейчас.
     *
     * @return list<int>
     */
    private function rosterIds(TelegramChatBroadcastItem $item): array
    {
        return DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)
            ->orderBy('position')
            ->pluck('event_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Запомнить состояние поста ДО правки.
     *
     * @param  list<string>  $changed  что изменила правка
     * @param  array<string, mixed>  $before
     */
    private function saveRevision(TelegramChatBroadcastItem $item, array $changed, array $before): void
    {
        // Состав подборки — вместе с подписью. Без него откат возвращал текст,
        // написанный под другую тройку, и проверить это было нечем.
        $roster = $item->kind === TelegramChatBroadcastItem::KIND_DIGEST
            ? $this->rosterIds($item)
            : null;

        DB::table('telegram.chat_broadcast_item_revisions')->insert([
            'item_id' => $item->id,
            'caption' => $before['caption'],
            'caption_source' => $before['caption_source'],
            'photo_urls' => $before['photo_urls'] === null
                ? null
                : json_encode($before['photo_urls'], JSON_UNESCAPED_UNICODE),
            'roster' => $roster === null ? null : json_encode($roster),
            'changed' => json_encode($changed, JSON_UNESCAPED_UNICODE),
            'user_id' => optional(request()->user())->id,
            'created_at' => now(),
        ]);

        // Подрезаем хвост: без этого у поста, который правят каждый день,
        // история росла бы вечно и ради ничего.
        $keep = DB::table('telegram.chat_broadcast_item_revisions')
            ->where('item_id', $item->id)
            ->orderByDesc('id')
            ->limit(self::REVISIONS_KEPT)
            ->pluck('id');

        DB::table('telegram.chat_broadcast_item_revisions')
            ->where('item_id', $item->id)
            ->whereNotIn('id', $keep)
            ->delete();
    }

    /**
     * История правок поста — что было до каждой из них.
     *
     * GET /api/admin/broadcast/items/{id}/revisions
     */
    public function revisions(int $itemId): JsonResponse
    {
        TelegramChatBroadcastItem::query()->findOrFail($itemId);

        $rows = DB::table('telegram.chat_broadcast_item_revisions as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.item_id', $itemId)
            ->orderByDesc('r.id')
            ->limit(self::REVISIONS_KEPT)
            ->get(['r.id', 'r.caption', 'r.caption_source', 'r.photo_urls', 'r.changed', 'r.created_at', 'u.name as user_name']);

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'caption' => $r->caption,
                'caption_source' => $r->caption_source,
                'photos' => $r->photo_urls ? json_decode($r->photo_urls, true) : null,
                'changed' => $r->changed ? json_decode($r->changed, true) : [],
                'at' => $r->created_at ? Carbon::parse($r->created_at)->toIso8601String() : null,
                'user' => $r->user_name,
            ])->values(),
        ]);
    }

    /**
     * Вернуть пост к сохранённой версии.
     *
     * Возвращаем ТОЛЬКО текст и картинки — то, что человек боится потерять.
     * День публикации не трогаем: он виден в сетке, правится перетаскиванием,
     * а молчаливый возврат старого дня вытеснил бы занявший его пост.
     *
     * Сам возврат тоже пишется в историю: отменить отмену должно быть можно.
     */
    public function restoreRevision(int $itemId, int $revisionId): JsonResponse
    {
        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);

        if ($item->posted_at !== null) {
            return response()->json(['ok' => false, 'error' => 'Пост уже опубликован — править нечего.'], 409);
        }

        $rev = DB::table('telegram.chat_broadcast_item_revisions')
            ->where('item_id', $itemId)
            ->where('id', $revisionId)
            ->first();

        if (! $rev) {
            return response()->json(['ok' => false, 'error' => 'Такой версии у поста нет.'], 404);
        }

        // ТЕКСТ ВСЕГДА ПОД ТЕКУЩИЙ СОСТАВ — и откат не исключение. Подпись из
        // прошлого возвращается со своим `caption_source`, а ручную подпись
        // доставка не пересобирает вовсе: версия, написанная под другую
        // тройку, уехала бы в канал как есть.
        //
        // У версий, записанных до появления поля, состав неизвестен — их
        // возвращаем как раньше: гадать не о чем.
        if ($item->kind === TelegramChatBroadcastItem::KIND_DIGEST && $rev->roster !== null) {
            $was = array_map('intval', (array) json_decode((string) $rev->roster, true));
            $now = $this->rosterIds($item);
            sort($was);
            sort($now);

            if ($was !== $now) {
                return response()->json(['ok' => false, 'error' => 'Состав подборки с тех пор изменился — эта версия написана про другие события. '
                    .'Нажми «Написать заново» или верни прежний состав.',
                ], 409);
            }
        }

        $this->saveRevision($item, ['restore'], [
            'caption' => $item->caption,
            'caption_source' => $item->caption_source,
            'photo_urls' => $item->photo_urls,
        ]);

        $item->caption = $rev->caption;
        $item->caption_source = $rev->caption_source;
        $item->photo_urls = $rev->photo_urls ? json_decode($rev->photo_urls, true) : null;
        $item->markEdited([TelegramChatBroadcastItem::EDIT_CAPTION]);
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
     * Ключи сетей площадок для каждой записи очереди.
     *
     * У обычного поста ключ один, у подборки — по одному на каждую названную
     * площадку, и повторы внутри поста схлопнуты: подборка про пять спектаклей
     * одного театра даёт этому театру одну отметку, а не пять.
     *
     * @param  list<int>  $itemIds
     * @return array<int, list<string>>
     */
    private function chainsByItem(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $rows = DB::table('telegram.chat_broadcast_item_events as l')
            ->join('events as e', 'e.id', '=', 'l.event_id')
            ->leftJoin('venues as v', 'v.id', '=', 'e.venue_id')
            ->whereIn('l.item_id', $itemIds)
            ->distinct()
            ->get(['l.item_id', 'v.name as venue_name']);

        $out = [];
        foreach ($rows as $row) {
            $key = $this->chainKey((string) ($row->venue_name ?? ''));
            if ($key === '') {
                continue;
            }
            $id = (int) $row->item_id;
            $out[$id] ??= [];
            if (! in_array($key, $out[$id], true)) {
                $out[$id][] = $key;
            }
        }

        return $out;
    }

    /**
     * Не занято ли событие ДРУГИМ постом канала.
     *
     * Гард дверей постановки. Раньше его роль играло UNIQUE(broadcast_id,
     * event_id) — то есть строка очереди под то же событие. Но пост-подборка
     * называет несколько событий, своей строки под каждое не заводит, и UNIQUE
     * про них ничего не знает: событие из понедельничной подборки можно было бы
     * поставить отдельным постом на среду, и подписчик увидел бы его дважды.
     *
     * Спрашиваем связь и исключаем саму оживляемую запись: она не «другой пост».
     */
    private function eventTakenByAnotherPost(int $broadcastId, int $eventId, ?int $exceptItemId): ?TelegramChatBroadcastItem
    {
        return TelegramChatBroadcastItem::query()
            ->from('telegram.chat_broadcast_items as i')
            ->select('i.*')
            ->join('telegram.chat_broadcast_item_events as l', 'l.item_id', '=', 'i.id')
            ->where('i.broadcast_id', $broadcastId)
            ->where('l.event_id', $eventId)
            ->when($exceptItemId !== null, fn ($q) => $q->where('i.id', '<>', $exceptItemId))
            ->where(function ($q) {
                $q->whereNotNull('i.posted_at')
                    ->orWhereIn('i.status', $this->openStatuses());
            })
            // Полными именами: created_at есть и у записи, и у строки связи —
            // короткое имя даёт «column reference is ambiguous». Ровно та
            // ловушка, о которой предупреждает докблок Event::broadcastPosts().
            ->orderByRaw('COALESCE(i.posted_at, i.publish_at, i.created_at) DESC')
            ->first();
    }

    /** Отказ обязан называть, ЧТО именно заняло событие и когда. */
    private function takenMessage(TelegramChatBroadcastItem $taken): string
    {
        $what = $taken->kind === TelegramChatBroadcastItem::KIND_EVENT
            ? 'пост'
            : 'подборка';
        $at = $taken->posted_at ?? $taken->publish_at;
        $when = $at
            ? Carbon::parse($at)->setTimezone('Europe/Moscow')->translatedFormat('j F')
            : null;

        if ($taken->posted_at !== null) {
            return $when
                ? "Это событие уже называл {$what}, вышедший {$when}."
                : 'Это событие уже публиковалось в канале.';
        }

        return $when
            ? "Это событие уже называет {$what} на {$when} — в канале оно выйдет дважды."
            : 'Это событие уже стоит в ленте канала.';
    }

    /**
     * Пересобрать ленту.
     *
     * Закреплённые и правленные руками не трогаем — в этом и смысл кнопки
     * «закрепить»: она защищает пост именно от пересборки.
     */
    public function rebuild(Request $request, int $broadcastId): JsonResponse
    {
        $broadcast = TelegramChatBroadcast::query()->findOrFail($broadcastId);

        // «Разбавить» — та же пересборка, но снимает только повторы одной
        // сети, оставляя первый пост каждой. Полная пересборка меняет всю
        // неделю, а человек жалуется на три квеста из семи, а не на неделю.
        if ($request->boolean('diversify')) {
            return $this->diversify($broadcast);
        }

        DB::beginTransaction();

        // Сколько из снимаемого — те, что ждали свободного дня. Их человек
        // положил туда руками (вытеснив предложением), и молча выметать их
        // нельзя: в интерфейсе написано «дождитесь, пока день освободится».
        $waitingDropped = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereIn('status', $this->openStatuses())
            ->where('is_pinned', false)
            ->whereNull('posted_at')
            ->whereNull('publish_at')
            ->whereNull('edited_at')
            // Явный тип, а не «всё, что не портрет площадки». Отрицание
            // молча зачисляет в события ЛЮБУЮ новую рубрику: подборка недели
            // съела бы ячейку событийной ленты, попала под «Разбавить» и была
            // бы снесена кнопкой «Пересобрать». Проверка на NULL тут и вовсе
            // мертва — колонка NOT NULL DEFAULT 'event'.
            ->where('kind', TelegramChatBroadcastItem::KIND_EVENT)
            ->count();

        $dropped = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereIn('status', $this->openStatuses())
            ->where('is_pinned', false)
            ->whereNull('posted_at')
            // Правленные руками пересборка НЕ трогает. Докблок обещал это с
            // самого начала, а в условии не было ни слова: человек правил
            // текст, двигал день — и «Пересобрать неделю» выметало всё это
            // молча. Теперь у правки есть след, и обещание наконец выполнимо.
            ->whereNull('edited_at')
            // Портреты площадок пересборка НЕ трогает: у них свой недельный
            // каденс и свой слот в неделе, они не конкурируют с событиями за
            // место. Снести портрет заодно с лентой значило бы сбросить его
            // ротацию ни за что.
            ->where('kind', TelegramChatBroadcastItem::KIND_EVENT)
            ->update([
                'status' => TelegramChatBroadcastItem::STATUS_SKIPPED,
                'error_message' => 'снято при пересборке ленты',
                // День отдаём вместе со статусом: снятая запись, сохранившая
                // publish_at, продолжала занимать слот в недельной сетке —
                // лента выглядела полной, а живого поста в ней не было ни одного.
                'publish_at' => null,
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

        // Заменить оказалось нечем — возвращаем ленту как была. Иначе кнопка
        // «Пересобрать» работает как «Снять всё»: ровно это и случилось на
        // стенде, где планирование стояло за тем же запретом, что и отправка.
        if ($dropped > 0 && ($filled['filled'] ?? 0) === 0) {
            DB::rollBack();

            return response()->json([
                'ok' => false,
                'error' => $this->emptyFillReason($broadcast, $filled, 'Пересборка отменена'),
            ], 409);
        }

        DB::commit();

        return response()->json(['data' => ['dropped' => $dropped, 'waiting_dropped' => $waitingDropped] + $filled]);
    }

    /**
     * Снять повторы одной сети и заполнить освободившееся другими площадками.
     *
     * Оставляем первый пост каждой сети и все закреплённые: человеку мешает
     * не сама сеть, а то, что она занимает половину недели.
     */
    private function diversify(TelegramChatBroadcast $broadcast): JsonResponse
    {
        DB::beginTransaction();

        // Только записи С ДНЁМ и только БЛИЖАЙШЕЙ НЕДЕЛИ. Про неделю здесь
        // говорилось и раньше, но окна в запросе не было: кнопка гребла весь
        // горизонт (у боевого канала это две недели), считала повторами посты,
        // разнесённые на девять дней, и снимала их. Подписчик такого повтора
        // не замечает — между постами прошла неделя, — а лента теряла пост.
        //
        // Снятие записи из очереди ожидания ни одного дня не освобождает,
        // значит и заполнять потом нечего: кнопка отработала бы вхолостую.
        // ВСЕ записи с днём, включая рубрики: сети, названные подборкой, тоже
        // занимают неделю в глазах подписчика. А снимаем ниже только событийные
        // — у рубрики свой каденс и свой слот, «Разбавить» ей не указ.
        $items = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereIn('status', $this->openStatuses())
            ->whereNull('posted_at')
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', Carbon::now()->addDays(self::DIVERSIFY_WINDOW_DAYS))
            ->orderBy('publish_at')
            ->get();

        // Сети записи — через связь: у подборки событий несколько, значит и
        // площадок несколько. Решение владельца: одна отметка на площадку с
        // поста, сколько бы его событий на ней ни было (distinct по паре).
        $chainsByItem = $this->chainsByItem($items->pluck('id')->all());

        $seen = [];
        $dropIds = [];
        foreach ($items as $item) {
            $keys = $chainsByItem[$item->id] ?? [];
            if ($keys === []) {
                continue;
            }

            $fresh = array_values(array_filter($keys, fn (string $k) => ! isset($seen[$k])));
            foreach ($keys as $k) {
                $seen[$k] = true;
            }

            // Хоть одна новая сеть — пост остаётся: он приносит разнообразие.
            if ($fresh !== []) {
                continue;
            }
            if ($item->is_pinned || $item->kind !== TelegramChatBroadcastItem::KIND_EVENT) {
                continue;
            }
            $dropIds[] = $item->id;
        }

        $dropped = $dropIds === [] ? 0 : TelegramChatBroadcastItem::query()
            ->whereIn('id', $dropIds)
            ->update([
                'status' => TelegramChatBroadcastItem::STATUS_SKIPPED,
                'error_message' => 'снято при разбавлении ленты: площадка уже была на неделе',
                'publish_at' => null,
                'claimed_at' => null,
                'claim_token' => null,
                'updated_at' => now(),
            ]);

        $filled = $this->broadcasts->fillFeedDays($broadcast->fresh('chat'), Carbon::now());

        if ($dropped > 0 && ($filled['filled'] ?? 0) === 0) {
            DB::rollBack();

            return response()->json([
                'ok' => false,
                'error' => $this->emptyFillReason(
                    $broadcast,
                    $filled,
                    'Разбавить нечем: других площадок в пуле не нашлось',
                ),
            ], 409);
        }

        DB::commit();

        return response()->json([
            'data' => ['dropped' => $dropped, 'waiting_dropped' => 0] + $filled,
        ]);
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

        // Пост не может уйти ПОСЛЕ начала события — получится анонс задним
        // числом. Правило общее со всеми остальными дверями ([[PostTiming]]):
        // раньше здесь стояла своя копия, и она пускала пост до КОНЦА события,
        // то есть анонс концерта мог уехать, когда он уже идёт.
        // Состав записи, а не колонка `event_id`: у подборки его нет, и раньше
        // она проезжала эту дверь насквозь вместе со всеми тремя событиями.
        $itemEvents = $this->itemEvents($item);
        if (! PostTiming::fitsAll($itemEvents, $target)) {
            return response()->json([
                'ok' => false,
                'error' => $this->tooLateMessageForEvents($itemEvents),
            ], 422);
        }

        // Место в ленте — это СЛОТ, а не день, когда у канала слотов несколько.
        // Со сравнением по дню перетаскивание в вечерний слот находило «занявшим»
        // утренний пост того же дня и менялось местами с ним: человек двигал
        // пост в свободное место, а получал обмен с чужим и отказ «второй пост
        // уехал бы за своё событие». Ту же гранулярность использует dayOccupant,
        // здесь она была забыта.
        $bySlot = $broadcast->slots !== [];
        $format = $bySlot ? 'Y-m-d H' : 'Y-m-d';
        $targetKey = $target->copy()->setTimezone('Europe/Moscow')->format($format);

        $occupant = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            // Ошибочный пост место занимает — в сетке он виден.
            ->whereIn('status', [...$this->openStatuses(), TelegramChatBroadcastItem::STATUS_ERROR])
            ->whereNull('posted_at')
            ->whereNotNull('publish_at')
            ->where('id', '<>', $item->id)
            ->get()
            ->first(fn (TelegramChatBroadcastItem $x) => Carbon::parse($x->publish_at)
                ->setTimezone('Europe/Moscow')->format($format) === $targetKey);

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
                if (! PostTiming::fits($otherEvent, Carbon::parse($from))) {
                    // Отказ обязан называть, КТО мешает и почему: без этого он
                    // читается как «нельзя, и всё» — человек видит два поста и
                    // не понимает, при чём тут второй.
                    $when = Carbon::parse($from)->setTimezone('Europe/Moscow')->format('j.m');

                    return response()->json([
                        'ok' => false,
                        'error' => 'Обмен невозможен: «'.($otherEvent->title ?? 'второй пост')
                            .'» уехал бы на '.$when.', а событие к тому дню уже пройдёт.',
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($item, $occupant, $target, $from) {
            $item->publish_at = $target;
            // Перетащили мышью — это ручная правка ровно в той же мере, что и
            // правка даты в карточке.
            $item->markEdited([TelegramChatBroadcastItem::EDIT_TIME]);
            $item->save();

            if ($occupant) {
                // Меняемся местами. Если у переносимого дня не было, соседу
                // достаётся пустая дата — он вернётся в общую очередь.
                // Соседа НЕ помечаем: его подвинули, он этого не просил.
                $occupant->publish_at = $from;
                $occupant->save();
            }
        });

        foreach (array_filter([$item, $occupant]) as $changed) {
            $this->regenerateCaption($changed, $broadcast);
        }

        return response()->json(['ok' => true, 'data' => ['swapped' => $occupant !== null]]);
    }

    /**
     * Имя канала кэшируем на запрос: лента зовёт postUrl() для каждой записи,
     * а канал у всех записей один и тот же — без кэша это два запроса на
     * строку на ровном месте.
     *
     * @var array<int, string>
     */
    private array $chatUsernameCache = [];

    private function postUrl(TelegramChatBroadcastItem $item): ?string
    {
        if (! $item->message_id || ! $item->broadcast_id) {
            return null;
        }

        $broadcastId = (int) $item->broadcast_id;

        if (! array_key_exists($broadcastId, $this->chatUsernameCache)) {
            $this->chatUsernameCache[$broadcastId] = trim((string) DB::table('telegram.chat_broadcasts as b')
                ->join('telegram.chats as c', 'c.id', '=', 'b.chat_id')
                ->where('b.id', $broadcastId)
                ->value('c.username'));
        }

        $username = $this->chatUsernameCache[$broadcastId];

        return $username === ''
            ? null
            : 'https://t.me/'.ltrim($username, '@').'/'.(int) $item->message_id;
    }

    /**
     * Первичная тема события для выдачи — id и человеческое имя.
     *
     * Их у события может быть несколько (у 4.8% — замер 2026-09-15): берём
     * наименьший id, тот же, что берут наполнитель ленты и подборка. Важна не
     * «правильная» тема, а одинаковая во всех трёх местах.
     *
     * @return array{id: int, name: string}|null
     */
    private function themePayload(?Event $event): ?array
    {
        $interest = $event?->relationLoaded('primaryInterests')
            ? $event->primaryInterests->first()
            : $event?->primaryInterests()->first();

        return $interest === null
            ? null
            : ['id' => (int) $interest->id, 'name' => (string) $interest->name];
    }

    /**
     * События, за срок которых отвечает запись.
     *
     * У поста события оно одно, у подборки — весь её названный состав, у
     * портрета площадки событий нет. До этого все двери читали только колонку
     * `event_id`, и подборка проходила сквозь них: `PostTiming::fits(null, …)`
     * честно отвечает «событию нечего сказать о сроке».
     *
     * @return list<Event>
     */
    private function itemEvents(TelegramChatBroadcastItem $item): array
    {
        if ($item->event_id) {
            $event = Event::query()->find($item->event_id);

            return $event ? [$event] : [];
        }

        if ($item->kind !== TelegramChatBroadcastItem::KIND_DIGEST) {
            return [];
        }

        $ids = DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)
            ->pluck('event_id')
            ->all();

        return $ids === [] ? [] : Event::query()->whereIn('id', $ids)->get()->all();
    }

    /**
     * Отказ обязан называть срок, а не только запрет.
     *
     * «К этому дню событие уже пройдёт» человек читает как ошибку интерфейса,
     * пока не увидит, к какому именно моменту надо успеть.
     */
    private function tooLateMessage(?Event $event): string
    {
        return $this->tooLateMessageFor(PostTiming::deadline($event));
    }

    /** @param  list<Event>  $events */
    private function tooLateMessageForEvents(array $events): string
    {
        return $this->tooLateMessageFor(PostTiming::earliestDeadline($events));
    }

    private function tooLateMessageFor(?Carbon $deadline): string
    {

        if ($deadline === null) {
            return 'Пост уйдёт после начала события — звать будет уже некуда.';
        }

        return sprintf(
            'Пост уйдёт после начала события — звать будет уже некуда. Успеть надо до %s.',
            $deadline->copy()->setTimezone('Europe/Moscow')->format('j.m в H:i'),
        );
    }

    /** Пересобрать шаблонный текст под новый день публикации. */
    private function regenerateCaption(TelegramChatBroadcastItem $item, TelegramChatBroadcast $broadcast): void
    {
        if ($item->caption_source === TelegramChatBroadcastItem::CAPTION_MANUAL) {
            return;
        }

        $this->buildCaptionFor($item, $broadcast);
    }

    /**
     * Собрать текст записи заново — любого вида.
     *
     * У портрета площадки нет события, а вся сборка текста шла через него:
     * пустой текст у портрета сохранялся как NULL, бот на пустом тексте бросал
     * задачу, ничего не помечая, и «один портрет в полёте» после этого
     * закрывал постановку следующего навсегда. Поэтому правка портрета и была
     * выключена в интерфейсе — она действительно вешала канал.
     */
    private function buildCaptionFor(TelegramChatBroadcastItem $item, TelegramChatBroadcast $broadcast): void
    {
        if ($item->kind === TelegramChatBroadcastItem::KIND_VENUE) {
            $venue = $item->venue_id ? \App\Models\Venue::query()->find($item->venue_id) : null;
            if (! $venue) {
                // Площадку удалили или сняли с публикации — прежний текст
                // оставляем: почему пустой нельзя, сказано в докблоке метода.
                return;
            }

            // «Что здесь скоро» считаем от дня публикации, а не от сейчас.
            $item->caption = $this->venuePortraits->buildVenueCaption(
                $venue,
                $item->publish_at ? Carbon::parse($item->publish_at) : Carbon::now(),
                (int) $item->id,
            );
            $item->caption_source = TelegramChatBroadcastItem::CAPTION_TEMPLATE;
            $item->save();

            return;
        }

        // Подборка: состав уже выбран, меняется момент. Пересобираем подпись
        // по НЕМУ — иначе пост уезжает с чужой неделей в шапке: так ушёл пост
        // 210, собранный под 16 сентября и отправленный 15-го.
        if ($item->kind === TelegramChatBroadcastItem::KIND_DIGEST) {
            $at = $item->publish_at ? Carbon::parse($item->publish_at) : Carbon::now();
            $draft = $this->digestComposer->recompose($item, $broadcast, $at)
                ?? $this->digestComposer->compose($broadcast, $at, $item);

            if ($draft !== null) {
                $this->broadcasts->applyDigestDraft($item, $draft);
            }

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

        // «Сейчас» — это тоже момент публикации, и правило срока на него
        // распространяется: анонс концерта, который уже идёт, звать никуда не
        // может. Эта дверь не проверяла НИЧЕГО — даже события не читала.
        $itemEvents = $this->itemEvents($item);
        if (! PostTiming::fitsAll($itemEvents, Carbon::now())) {
            return response()->json([
                'ok' => false,
                'error' => $this->tooLateMessageForEvents($itemEvents),
            ], 422);
        }

        $broadcast = TelegramChatBroadcast::query()->with('chat')->find($item->broadcast_id);

        // Пост без анонса, отправленный «сейчас», уходит через считаные секунды —
        // написать ему текст физически некогда, и в канал уезжает сырое описание
        // из парсера. Поэтому просим текст и придерживаем пост на время
        // генерации: парсер снимет придержку сам, как только текст готов.
        $waitForText = $this->needsTextBeforeSending($item, $broadcast);

        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->publish_at = Carbon::now();
        // Вне сетки — как и у публикации предложения выше: пост уходит сейчас,
        // а слот своего дня остаётся свободным для запланированного.
        $item->is_off_grid = true;
        $item->planned_at = $waitForText
            ? Carbon::now()->addMinutes($this->textGraceMinutes())
            // Придержку снимаем: она ждала генерации текста, а текст уже есть.
            : null;
        if ($waitForText) {
            $item->text_requested_at = Carbon::now();
        }
        $item->error_message = null;
        $item->claimed_at = null;
        $item->claim_token = null;
        $item->save();

        if ($broadcast) {
            $this->regenerateCaption($item, $broadcast);
        }

        // Подборка: состав выбирается той же пересборкой выше, и только ПОСЛЕ
        // неё видно, про что писать. Поэтому текст просим здесь, а не вместе с
        // событиями, — иначе парсер получил бы заявку на пустой состав.
        if ($this->digestNeedsText($item, $broadcast)) {
            $this->holdDigestForText($item);
            $waitForText = true;
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
            // Пост ждёт не зазора, а собственного текста — это другая причина
            // подождать, и человеку надо сказать именно её.
            'waiting_for_text' => $waitForText,
        ]]);
    }

    /**
     * Вернуть снятый пост в ленту.
     *
     * Снятое копилось и не возвращалось ничем: «Снято автоматически» была
     * витриной без действия, а событие оттуда в пул предложений попадало не
     * всегда — UNIQUE(broadcast_id, event_id) не даёт поставить его второй раз,
     * пока старая запись цела.
     */
    public function restore(int $itemId): JsonResponse
    {
        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);

        if ($item->posted_at !== null) {
            return response()->json(['ok' => false, 'error' => 'Пост уже опубликован.'], 409);
        }

        $broadcast = TelegramChatBroadcast::query()->with('chat')->find($item->broadcast_id);
        if (! $broadcast) {
            return response()->json(['ok' => false, 'error' => 'Канал не найден.'], 404);
        }

        $error = $this->restoreItem($item, $broadcast);
        if ($error !== null) {
            return response()->json(['ok' => false, 'error' => $error], 422);
        }

        return response()->json([
            'data' => $this->itemPayload(
                $item->fresh(),
                $item->event_id ? Event::query()->with('venue:id,name')->find($item->event_id) : null,
                $item->venue_id ? \App\Models\Venue::query()->find($item->venue_id, ['id', 'name']) : null,
            ),
        ]);
    }

    /**
     * Вернуть одну запись в ленту. null — получилось, строка — почему нет.
     *
     * Общий для одиночного и массового возврата: правила «что нельзя вернуть»
     * должны быть одни, иначе кнопка «вернуть все» тихо сделает то, что
     * поштучный возврат запрещает.
     */
    private function restoreItem(TelegramChatBroadcastItem $item, TelegramChatBroadcast $broadcast): ?string
    {
        // Событие могло закончиться, пока запись лежала снятой — возвращать
        // такое значит вернуть анонс прошлого.
        $event = null;
        if ($item->event_id) {
            $event = Event::query()->find($item->event_id);
            if (! $event) {
                return 'События больше нет — вернуть нечего.';
            }
            if (! PostTiming::fits($event, Carbon::now())) {
                return 'Событие уже началось — возвращать его в ленту незачем.';
            }
        }

        // Свободного слота может не быть — это не повод отказывать в возврате:
        // запись встаёт без дня, в «Ждут свободного дня», и займёт ближайший
        // освободившийся. Отказ здесь читался бы как «вернуть нельзя», хотя
        // вернуть как раз можно.
        //
        // У подборки слот СВОЙ — день недели рубрики и вечерний час канала.
        // Событийный планировщик отдал бы первый свободный слот горизонта, то
        // есть утро вторника, и недельный каденс рубрики растворился бы.
        $slot = $item->kind === TelegramChatBroadcastItem::KIND_DIGEST
            ? $this->digestBooking->slotFor($broadcast, Carbon::now())
            // respectLead = false: возврат снятого поста делает человек, и
            // правило «поздний слот решается накануне» на него не
            // распространяется — иначе вернуть пост в пустую неделю нельзя.
            : app(\App\Services\Telegram\BroadcastSlotPlanner::class)->nextFreeSlot($broadcast, Carbon::now(), false);

        // Свободный слот может оказаться ПОЗЖЕ события: планировщик про сроки
        // не знает, он ищет пустую ячейку. Возврат от этого не отменяем —
        // запись просто встаёт без дня и ждёт подходящего слота, как все
        // остальные. Раньше она вставала в этот слот молча, и доставка потом
        // снимала её с причиной «событие уже прошло».
        if ($slot !== null && ! PostTiming::fits($event, $slot->copy()->utc())) {
            $slot = null;
        }

        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->error_message = null;
        $item->claimed_at = null;
        $item->claim_token = null;
        $item->publish_at = $slot?->copy()->utc();

        // Подборку возвращают спустя дни, и её прежний состав к этому моменту
        // наполовину прошёл. Снимаем состав и текст целиком: на новом слоте она
        // соберётся заново, как любая другая. Правленый человеком текст
        // (`caption_source = manual`) — исключение, его оставляет regenerate.
        if ($item->kind === TelegramChatBroadcastItem::KIND_DIGEST
            && $item->caption_source !== TelegramChatBroadcastItem::CAPTION_MANUAL) {
            DB::table('telegram.chat_broadcast_item_events')->where('item_id', $item->id)->delete();
            $item->digest_meta = null;
            $item->caption = null;
            $item->caption_source = null;
            $item->save();

            return null;
        }

        $item->save();

        $this->regenerateCaption($item, $broadcast);

        return null;
    }

    /**
     * Вернуть в ленту сразу несколько снятых.
     *
     * Одной ручкой, а не N запросами подряд: слот каждому подбирается по
     * текущему состоянию ленты, и между отдельными запросами оно менялось бы
     * под ногами.
     */
    public function restoreMany(Request $request, int $broadcastId): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:50'],
            'ids.*' => ['integer'],
        ]);

        $broadcast = TelegramChatBroadcast::query()->with('chat')->findOrFail($broadcastId);

        $items = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereIn('id', $data['ids'])
            ->whereNull('posted_at')
            ->orderBy('id')
            ->get();

        $restored = 0;
        $failed = [];
        foreach ($items as $item) {
            $error = $this->restoreItem($item, $broadcast);
            if ($error === null) {
                $restored++;

                continue;
            }
            $failed[] = ['id' => (int) $item->id, 'error' => $error];
        }

        return response()->json(['data' => ['restored' => $restored, 'failed' => $failed]]);
    }

    /**
     * Снять вышедший пост: сообщения в канале больше нет.
     *
     * Обратная кнопке «убрать из ленты», которая работает только до выхода.
     * После выхода запись держала слот, а единственным способом её освободить
     * была правка базы руками.
     */
    public function withdraw(int $itemId): JsonResponse
    {
        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);

        if ($item->posted_at === null) {
            return response()->json(['ok' => false, 'error' => 'Пост ещё не выходил — снимать нечего.'], 409);
        }

        $this->broadcasts->withdrawPostedItem($item);

        return response()->json([
            'ok' => true,
            'data' => $this->itemPayload(
                $item->fresh(),
                $item->event_id ? Event::query()->with('venue:id,name')->find($item->event_id) : null,
                $item->venue_id ? \App\Models\Venue::query()->find($item->venue_id, ['id', 'name']) : null,
            ),
        ]);
    }

    /** Вернуть пост в очередь после ошибки — попробовать ещё раз. */
    public function retry(int $itemId): JsonResponse
    {
        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);

        if ($item->posted_at !== null) {
            return response()->json(['ok' => false, 'error' => 'Пост уже опубликован.'], 409);
        }

        // Повтор двигает день на «сейчас», а значит подчиняется тому же
        // правилу: запись ошиблась ровно потому, что не ушла вовремя, и
        // выдать ей свежее «сейчас» после начала события значит отправить
        // анонс задним числом штатным путём.
        $itemEvents = $this->itemEvents($item);
        if (! PostTiming::fitsAll($itemEvents, Carbon::now())) {
            return response()->json([
                'ok' => false,
                'error' => $this->tooLateMessageForEvents($itemEvents),
            ], 422);
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

    /**
     * Попросить модель написать анонс этому посту — сейчас, а не перед публикацией.
     *
     * ПОЧЕМУ ЗАЯВКА, А НЕ ВЫЗОВ. Тексты пишет парсер: там живут промпт, гейты
     * качества, учёт расхода и ключи провайдера. kudab-api его команд не зовёт
     * и ключей не держит, поэтому кладём просьбу в ту же таблицу очереди, а
     * parser:tg:describe-due забирает её в ближайшую минуту. Ответ здесь —
     * «принято», а не «готово»: лента дальше сама перечитывает запись.
     *
     * Пожелание (hint) уходит в промпт как есть — это просьба к ЭТОМУ посту.
     */
    public function describe(Request $request, int $itemId): JsonResponse
    {
        $data = $request->validate([
            'hint' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $item = TelegramChatBroadcastItem::query()->findOrFail($itemId);

        if ($item->posted_at !== null) {
            return response()->json(['ok' => false, 'error' => 'Пост уже опубликован.'], 409);
        }

        // Подборке текст пишется так же, как событию, — только сначала должно
        // быть решено, ПРО ЧТО писать. Обычно состав выбирается на отправке;
        // если человек просит текст раньше, выбираем состав сейчас и тем самым
        // замораживаем его — это осознанный обмен: свежесть на возможность
        // прочитать и поправить текст заранее.
        if ($item->kind === TelegramChatBroadcastItem::KIND_DIGEST) {
            return $this->describeDigest($item, trim((string) ($data['hint'] ?? '')));
        }

        if ($item->kind === TelegramChatBroadcastItem::KIND_VENUE) {
            return $this->describeVenue($item, trim((string) ($data['hint'] ?? '')));
        }

        if ($item->kind !== TelegramChatBroadcastItem::KIND_EVENT || ! $item->event_id) {
            return response()->json([
                'ok' => false,
                'error' => 'Текст пишется событиям, подборкам и портретам площадок.',
            ], 422);
        }

        $event = Event::query()->find($item->event_id);
        if (! $event) {
            return response()->json(['ok' => false, 'error' => 'Событие не найдено.'], 404);
        }

        $hint = trim((string) ($data['hint'] ?? ''));

        $item->text_requested_at = Carbon::now();
        $item->text_hint = $hint !== '' ? $hint : null;
        $item->save();

        return response()->json([
            'ok' => true,
            'data' => $this->itemPayload($item->fresh(), $event, null),
        ]);
    }

    /**
     * Заказать портрет площадки заново — той же заявкой, что у событий.
     *
     * Текст портрета живёт не на записи, а на площадке (`venues.tg_portrait`),
     * и второго текста у места нет: переписанный портрет меняет заодно карточку
     * площадки на сайте. Говорим об этом в интерфейсе, а не здесь — отказывать
     * незачем, портрет для того и переписывают, что он устарел.
     *
     * Подпись поста не трогаем. Её пересоберут чтение ленты и отправка, когда
     * текст будет готов, а до тех пор стоит прежняя: пустая подпись у портрета
     * — авария, бот бросает такую задачу, не помечая её ничем.
     */
    private function describeVenue(TelegramChatBroadcastItem $item, string $hint): JsonResponse
    {
        $venue = $item->venue_id ? \App\Models\Venue::query()->find($item->venue_id) : null;
        if (! $venue) {
            return response()->json(['ok' => false, 'error' => 'Площадка не найдена.'], 404);
        }

        $item->text_requested_at = Carbon::now();
        $item->text_hint = $hint !== '' ? $hint : null;
        $item->save();

        return response()->json([
            'ok' => true,
            'data' => $this->itemPayload($item->fresh(), null, $venue),
        ]);
    }

    /**
     * Заказать текст подборке: состав — если его ещё нет, и заявка парсеру.
     *
     * Подпись снимаем: она собрана из фактов и первых фраз описаний, а сейчас
     * её перепишет модель. Пустая подпись — тот же сигнал «собрать заново»,
     * что и на доставке.
     */
    private function describeDigest(TelegramChatBroadcastItem $item, string $hint): JsonResponse
    {
        $broadcast = TelegramChatBroadcast::query()->with('chat.city')->find($item->broadcast_id);
        if (! $broadcast) {
            return response()->json(['ok' => false, 'error' => 'Канал не найден.'], 404);
        }

        if ($blocked = $this->digestNotEditable($item)) {
            return $blocked;
        }

        $hasRoster = DB::table('telegram.chat_broadcast_item_events')->where('item_id', $item->id)->exists();

        if (! $hasRoster || $item->digestTheme() === null) {
            $draft = $this->digestComposer->compose(
                $broadcast,
                $item->publish_at ? Carbon::parse($item->publish_at) : Carbon::now(),
                $item,
            );

            if ($draft === null) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Ни одна тема не набрала состава — писать пока не о чем.',
                ], 422);
            }

            // Снимок ДО applyDigestDraft, а не только перед придержкой ниже:
            // он ставит caption_source='template', и holdDigestForText уже
            // ничего не нашёл бы. Второй вызов там после этого — пустышка,
            // ручной текст к тому моменту уже в истории.
            $this->rememberManualCaption($item, 'заказан текст у ИИ');
            $this->broadcasts->applyDigestDraft($item, $draft);
            $item->refresh();
        }

        $item->text_hint = $hint !== '' ? $hint : null;
        $this->holdDigestForText($item);

        return response()->json([
            'ok' => true,
            'data' => $this->itemPayload($item->fresh(), null, null),
        ]);
    }

    /**
     * Снять подпись и попросить текст подборке.
     *
     * Пустая подпись — единственный сигнал, по которому доставка вернётся к
     * сборке; оставленная шаблонная уехала бы в канал первой же попыткой.
     * Придержка — то же окно, что у события: парсер снимет её сам.
     */
    private function holdDigestForText(TelegramChatBroadcastItem $item): void
    {
        $this->rememberManualCaption($item, 'заказан текст у ИИ');

        $item->caption = null;
        $item->caption_source = null;
        $item->text_requested_at = Carbon::now();
        $item->planned_at = Carbon::now()->addMinutes($this->textGraceMinutes());
        $item->save();
    }

    /**
     * Стоит ли дать модели написать текст подборке перед отправкой.
     *
     * Спрашиваем про ПОКРЫТИЕ состава, а не про наличие текста — тем же
     * правилом, что и доставка (TelegramChatBroadcastService::digestWantsText).
     * Разойдись эти двое, и кнопка «отправить сейчас» уводила бы в канал
     * текст про прежнюю тройку: одной уцелевшей строки хватало, чтобы запись
     * считалась написанной.
     */
    private function digestNeedsText(TelegramChatBroadcastItem $item, ?TelegramChatBroadcast $broadcast): bool
    {
        if ($broadcast === null
            || ! $broadcast->ai_text
            || $item->kind !== TelegramChatBroadcastItem::KIND_DIGEST
            || $item->caption_source === TelegramChatBroadcastItem::CAPTION_MANUAL) {
            return false;
        }

        $roster = DB::table('telegram.chat_broadcast_item_events as l')
            ->join('events as e', 'e.id', '=', 'l.event_id')
            ->where('l.item_id', $item->id)
            ->whereNull('e.deleted_at')
            ->pluck('l.event_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        return ! $item->digestTextCoversRoster($roster);
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

        // Для демо берём событие С ЖИВОЙ ФРАЗОЙ, а не просто ближайшее.
        //
        // Формы поста отличаются прежде всего тем, ГДЕ стоит фраза модели. У
        // ближайшего события её часто нет (квесты и билетные карточки приходят
        // с одним пресс-релизом), а пресс-релиз во всех формах стоит после
        // сведений — и демо выглядело одинаковым, сколько шаблон ни переключай.
        // Ровно так владелец и решил, что предпросмотр сломан.
        $event = isset($data['event_id'])
            ? Event::query()->find((int) $data['event_id'])
            : Event::query()->active()->upcoming()
                ->whereNotNull('tg_description')
                ->where('tg_description', '<>', '')
                ->orderBy('start_time')
                ->first()
                ?? Event::query()->active()->upcoming()->orderBy('start_time')->first();

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
            ['name' => '{kind_emoji}', 'about' => 'значок по теме: 🎭 театр, 🎷 джаз, 🧩 квест. Берётся из интересов события; незнакомая тема остаётся без значка'],
            ['name' => '{lead|slice:0..400|escape_html}', 'about' => 'живая фраза, написанная моделью; пусто, если её нет, — строка исчезает'],
            ['name' => '{about|slice:0..400|escape_html}', 'about' => 'описание из источника; молчит, когда есть {lead}, — иначе текст ушёл бы дважды'],
            ['name' => '{description|slice:0..400|escape_html}', 'about' => 'СТАРЫЙ ключ: и фраза модели, и описание разом. Вместе с {lead} даст один текст дважды'],
            ['name' => '{url}', 'about' => 'адрес события на сайте (только адрес, без тега)'],
            ['name' => '{more_link}', 'about' => 'готовая ссылка «Подробнее на kudab.ru →»'],
            ['name' => '{original_link}', 'about' => 'готовая ссылка «Открыть оригинал →»; у события без источника строка исчезает'],
        ];
    }

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
            // Формы, которые канал чередует по дням. Пустой список — форма одна.
            'template_rotation' => ['sometimes', 'array', 'max:5'],
            'template_rotation.*' => ['string', 'max:32'],
            'feed_limit' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'city_id' => ['sometimes', 'nullable', 'integer'],
            'slots' => ['sometimes', 'array', 'max:'.TelegramChatBroadcast::MAX_SLOTS],
            'slots.*' => ['integer', 'between:0,23'],
            'horizon_days' => ['sometimes', 'integer', 'min:1', 'max:31'],
            // null — «заполнять вместе с первым слотом», то есть как было до
            // появления ручки.
            'fill_lead_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'portrait_every_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'min_gap_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'ai_text' => ['sometimes', 'boolean'],
            'text_lead_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            // null — рубрика выключена; 1..7 — день недели, когда она выходит
            'digest_weekday' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:7'],
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
        if ($request->has('template_rotation')) {
            $settings = $broadcast->settings ?? [];
            $codes = array_values(array_filter(array_map(
                static fn ($c) => trim((string) $c),
                (array) $data['template_rotation'],
            ), static fn ($c) => $c !== ''));

            // Одна форма в списке — это не чередование, а обычный выбор:
            // храним её как основную и список чистим, иначе в настройках
            // остаётся включённым режим, которого не видно в постах.
            if (count($codes) < 2) {
                unset($settings['template_rotation']);
                if ($codes !== []) {
                    $settings['template_code'] = $codes[0];
                }
            } else {
                $settings['template_rotation'] = $codes;
            }

            $broadcast->settings = $settings;
        }
        if ($request->has('feed_limit')) {
            $broadcast->feed_limit = (int) $data['feed_limit'];
        }
        if ($request->has('slots')) {
            $broadcast->slots = $data['slots'];
        }
        if ($request->has('fill_lead_days')) {
            $broadcast->fill_lead_days = $data['fill_lead_days'] === null
                ? null
                : (int) $data['fill_lead_days'];
        }
        if ($request->has('horizon_days')) {
            $broadcast->horizon_days = (int) $data['horizon_days'];
        }
        if ($request->has('portrait_every_days')) {
            $broadcast->portrait_every_days = (int) $data['portrait_every_days'];
        }
        if ($request->has('ai_text')) {
            $broadcast->ai_text = (bool) $data['ai_text'];
        }
        if ($request->has('text_lead_minutes')) {
            $broadcast->text_lead_minutes = (int) $data['text_lead_minutes'];
        }
        $digestWas = $broadcast->digest_weekday;
        if ($request->has('digest_weekday')) {
            $broadcast->digest_weekday = $data['digest_weekday'] === null
                ? null
                : (int) $data['digest_weekday'];
        }
        if ($request->has('min_gap_minutes')) {
            $broadcast->min_gap_minutes = (int) $data['min_gap_minutes'];
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

        // Рубрика включается ЗДЕСЬ И СЕЙЧАС, а не через час.
        //
        // Бронь слота ставит почасовая команда, и после сохранения настроек в
        // ленте до часа не появлялось ничего: человек включил подборку и не
        // видит её — первое, что он подумает, это что настройка не сохранилась.
        // Выключение симметрично убирает неотправленную бронь: оставить её
        // значило бы выпустить рубрику, от которой только что отказались.
        if ($digestWas !== $broadcast->digest_weekday) {
            $this->syncDigestBooking($broadcast, $digestWas);
        }

        return response()->json(['data' => $this->channelPayload($broadcast->fresh('chat'))]);
    }

    /**
     * Привести бронь подборки в соответствие с настройкой канала.
     *
     * День сменили — старую бронь снимаем и ставим новую: двигать её на месте
     * нельзя, у нового дня может быть занят слот, и тогда бронь уедет на
     * неделю вперёд — это решает сам сервис брони.
     */
    private function syncDigestBooking(TelegramChatBroadcast $broadcast, ?int $was): void
    {
        if ($was !== null) {
            TelegramChatBroadcastItem::query()
                ->where('broadcast_id', $broadcast->id)
                ->where('kind', TelegramChatBroadcastItem::KIND_DIGEST)
                ->whereNull('posted_at')
                ->whereIn('status', $this->openStatuses())
                ->update([
                    'status' => TelegramChatBroadcastItem::STATUS_SKIPPED,
                    'error_message' => 'подборка: настройку рубрики изменили',
                    'publish_at' => null,
                    'updated_at' => now(),
                ]);
        }

        if ($broadcast->digest_weekday !== null) {
            $this->digestBooking->bookDue(Carbon::now());
        }
    }

    // ------------------------------------------------------------------

    /**
     * Картинки портрета: ручной состав сильнее, иначе обложка с записи.
     *
     * Ровно то, что уйдёт в канал: выдача задачи боту читает photo_urls, а при
     * их отсутствии собирает набор по площадке.
     *
     * @return list<string>
     */
    private function venuePhotos(TelegramChatBroadcastItem $i): array
    {
        if (is_array($i->photo_urls)) {
            return array_values(array_filter($i->photo_urls, 'is_string'));
        }

        if (! $i->venue_id) {
            return array_values(array_filter([$i->photo_url]));
        }

        // Та же величина и тот же фолбэк, что в доставке: иначе админка
        // показывала три картинки, а в канал уходило четыре.
        $photos = $this->venuePortraits->venuePhotoUrls(
            (int) $i->venue_id,
            \App\Services\Telegram\TelegramVenuePortraitService::ALBUM_LIMIT,
        );

        return $photos !== [] ? $photos : array_values(array_filter([$i->photo_url]));
    }

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
     * Откуда событие пришло — та же ссылка, что стоит в посте «Открыть оригинал».
     *
     * Поля источника разные у разных парсеров, поэтому берём первое непустое —
     * ровно в том же порядке, что и сборщик текста.
     */
    private function originalUrl(Event $event): ?string
    {
        foreach (['canonical_url', 'external_url', 'source_url', 'original_url'] as $key) {
            $value = trim((string) ($event->{$key} ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Причина снятия в машинном виде.
     *
     * manual — убрали руками; rebuild — пересборка или разбавление;
     * stale — событие прошло или исчезло, возвращать такое незачем.
     * Словарь причин принадлежит серверу: он их и пишет, а фронт не должен
     * разбирать русский текст.
     */
    private function skipReason(string $message): string
    {
        $m = mb_strtolower($message);

        return match (true) {
            str_contains($m, 'пересборк'), str_contains($m, 'разбавлен') => 'rebuild',
            str_contains($m, 'прошло'), str_contains($m, 'недоступно') => 'stale',
            str_contains($m, 'снято из ленты') => 'manual',
            default => 'other',
        };
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
            // Исключаем ЗАПИСЬ, а не событие. Раньше был и второй параметр —
            // «кроме этого события», и он требовал NULL-safe сравнения, потому
            // что `event_id <> ?` молча выбрасывает строки портретов. Для
            // подборки такое исключение не работает вовсе: своего события у неё
            // нет, и при переносе на собственный день она получала бы отказ
            // «день занят» сама от себя. Запись знает про себя всегда.
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

    /**
     * Почему наполнитель вернул ноль — пустой пул или собственный кап ленты.
     *
     * Разница видна только по сводке наполнителя, а человеку у кнопки она
     * важнее всего: «пул пуст» и «лента уже полная» лечатся противоположным.
     * Пока кап жил в одном автомате, этой развилки не существовало вовсе.
     */
    private function emptyFillReason(
        TelegramChatBroadcast $broadcast,
        array $filled,
        string $prefix,
    ): string {
        if ((int) ($filled['feed_limit'] ?? 0) > 0) {
            return sprintf(
                '%s: лента уже держит предел канала — %d открытых записей при капе %d. '
                .'Подними «Сколько постов держать» в настройках канала или дождись, пока часть выйдет.',
                $prefix,
                // Тот же счёт, что у наполнителя: открытые СОБЫТИЙНЫЕ записи.
                // Через openStatuses() — список статусов в контроллере уже
                // есть, и заводить ради одной строки ещё одну зависимость
                // означало бы завести и второе место, где он может разойтись.
                TelegramChatBroadcastItem::query()
                    ->where('broadcast_id', $broadcast->id)
                    ->where('kind', TelegramChatBroadcastItem::KIND_EVENT)
                    ->whereIn('status', $this->openStatuses())
                    ->count(),
                $broadcast->feed_limit,
            );
        }

        return $prefix.'. Всё осталось на месте.';
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
            ->where('kind', TelegramChatBroadcastItem::KIND_EVENT);

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
            'template_rotation' => $b->template_rotation,
            'feed_limit' => $b->feed_limit,
            // Часы публикации внутри дня по Москве. Пустой список — один пост
            // в день, час берётся из расписания.
            'slots' => $b->slots,
            'horizon_days' => $b->horizon_days,
            'fill_lead_days' => $b->fill_lead_days,
            // Каденс портретов. Сверять его есть с чем: portrait_pool ниже,
            // потолок — TelegramVenuePortraitService::POOL_PER_WEEKLY_POST.
            'portrait_every_days' => $b->portrait_every_days,
            'min_gap_minutes' => $b->min_gap_minutes,
            'ai_text' => $b->ai_text,
            // за сколько минут до слота появится анонс — чтобы лента показывала
            // человеку время, а не абстрактное «перед публикацией»
            'text_lead_minutes' => $b->text_lead_minutes,
            // Подборка недели: день выхода или null, если рубрика выключена.
            // Час не отдаём — он всегда вечерний слот канала.
            'digest_weekday' => $b->digest_weekday,
            'digest_hour' => $b->digest_hour,
            // Когда канал снова сможет постить. Без этого пост, ждущий
            // зазора, выглядел как «ничего не происходит»: в ленте он стоит
            // со временем в прошлом и молчит.
            'next_post_allowed_at' => optional(
                $this->broadcasts->nextPostAllowedAt((int) $b->id, Carbon::now())
            )?->toIso8601String(),
            'portrait_pool' => $b->chat?->city_id
                ? \App\Models\Venue::query()
                    ->where('city_id', $b->chat->city_id)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->whereNotNull('tg_portrait')
                    ->where('tg_portrait', '<>', '')
                    ->count()
                : 0,
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
            // всегда да; на стенде — только каналы из BroadcastSafety::ALLOW_KEY.
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
            // Включены ли в канале реакции. Прибор реакций без них не получает
            // ни одного обновления, и пустота против каждого поста читается как
            // «реакций не было», хотя мерить нечем. Значение приносит бот
            // заодно с суточным замером подписчиков; null — ещё не спрашивали.
            'reactions_enabled' => array_key_exists('reactions_enabled', (array) ($b->settings ?? []))
                ? (bool) ($b->settings['reactions_enabled'])
                : null,
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

        // Анонс пишется перед самой публикацией, и если модель не справилась,
        // пост уходит с сырым описанием из парсера. Молча: в ленте это видно
        // только по тому, что пометка «написан ИИ» так и не появилась. Один
        // такой пост — случайность, несколько подряд — модель не успевает или
        // падает, и об этом надо сказать.
        if ($b->ai_text) {
            $raw = $this->postedWithoutAiText($b);
            if ($raw >= 2) {
                $out[] = [
                    'level' => 'warning',
                    'text' => "За неделю {$raw} поста ушли с описанием из парсера, без анонса ИИ. "
                        .'Проверьте, работает ли генерация: обычно это молчащий парсер или кончившийся ключ.',
                ];
            }
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

        // Реакции — второй и последний прибор отклика канала: просмотры Bot API
        // не отдаёт вовсе, а переходов за 90 дней набралось 2 на 7 постов.
        // Работает он, только если реакции включены в настройках самого канала,
        // и включить их можно ТОЛЬКО руками в телеграме — ручки в Bot API нет.
        // Пока выключены, в ленте против каждого поста пусто, и эта пустота
        // читается как «реакций не было». Говорим прямо.
        if (($b->settings['reactions_enabled'] ?? null) === false) {
            $out[] = [
                'level' => 'info',
                'text' => 'В канале выключены реакции — второй прибор отклика молчит. '
                    .'Включаются только в самом телеграме: настройки канала → Реакции. '
                    .'После этого числа появятся в ленте сами, опрашивать ничего не нужно.',
            ];
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
    private function itemPayload(
        TelegramChatBroadcastItem $i,
        ?Event $event,
        ?\App\Models\Venue $venue = null,
        ?int $repeats = null,
    ): array {
        // Состав подборки нужен дважды: показать его человеку и решить, про
        // ЭТОТ ли состав написан лежащий текст. Читаем один раз.
        $linked = $i->kind === TelegramChatBroadcastItem::KIND_DIGEST ? $this->linkedEvents($i) : [];

        return [
            'id' => (int) $i->id,
            'kind' => $i->kind ?? 'event',
            'status' => $i->status,
            'event_id' => $i->event_id ? (int) $i->event_id : null,
            'title' => $event?->title ?? match ($i->kind) {
                TelegramChatBroadcastItem::KIND_VENUE => $venue ? 'Портрет: '.$venue->name : null,
                TelegramChatBroadcastItem::KIND_DIGEST => 'Подборка недели',
                default => null,
            },
            // Имя чистим тем же правилом, что печатает пост, — [[VenueName]].
            // Иначе в одной строке админки стоит «Театр Кот | Воронеж», а в
            // посте под ней «Театр Кот», и человек ищет, где он опечатался.
            // Ключ сети считается от ЧИСТОГО имени по той же причине: с
            // хвостом «| Воронеж» филиал не сходился с головной площадкой.
            'venue' => VenueName::label($event?->venue?->name ?? $venue?->name) ?: null,
            // Сеть площадок. Без неё лента не отличала «Матрёшку» от
            // «Матрёшки на Кольцовской»: предупреждение об однообразии считало
            // по venue_id и трёх филиалов одной сети не видело.
            'chain' => $this->chainKey(VenueName::label($event?->venue?->name ?? $venue?->name)) ?: null,
            'original_url' => $event ? $this->originalUrl($event) : null,
            'event_start_time' => optional($event?->start_time)?->toIso8601String(),
            'event_end_time' => optional($event?->end_time)?->toIso8601String(),
            // Тема поста: по ней интерфейс показывает, чем занята неделя, и
            // по ней же наполнитель не ставит два одинаковых рядом.
            'theme' => $this->themePayload($event),
            // Отклик: сколько человек пришло на сайт с этого поста. NULL —
            // «ещё не мерили», ноль — «мерили, переходов не было», и это
            // разные вещи. Просмотров тут нет и быть не может: Bot API их не
            // отдаёт, см. CollectClicksCommand.
            'clicks' => $i->clicks !== null ? (int) $i->clicks : null,
            // Второй прибор отклика — реакции. Переходы меряют исход («ушёл на
            // сайт»), реакции — сам отклик, и они не требуют от читателя
            // уходить из телеграма. NULL значит «обновлений не приходило» —
            // чаще всего потому, что реакции в канале не включены; ноль —
            // «ставили и сняли». Разбивка нужна отдельно от суммы: «🔥 4» и
            // «👎 4» дают одно число и говорят противоположное.
            'reactions' => $i->reactions !== null ? (int) $i->reactions : null,
            'reactions_meta' => $i->reactions_meta ?: null,
            // Ссылка на сам пост в канале — по ней проверяют, как он выглядит
            // у подписчика. Без номера сообщения её собрать не из чего: у
            // постов, ушедших до появления прибора, его нет.
            'post_url' => $this->postUrl($i),
            // Пост стоит ПОЗЖЕ своего события. Такие записи в ленте уже есть —
            // их наставили двери, у каждой из которых было своё правило, — и
            // без пометки они выглядят обычными, пока доставка молча не снимет
            // их с причиной «событие уже прошло», спалив слот.
            'late_for_event' => $event !== null
                && $i->posted_at === null
                && $i->publish_at !== null
                && ! PostTiming::fits($event, Carbon::parse($i->publish_at)),
            // Пост ушёл кнопкой «сейчас», а не по расписанию. Сетке это нужно,
            // чтобы НЕ класть его в клетку дня: у него нет слота, у него есть
            // момент. Без признака такой пост занимал первую свободную клетку
            // и выглядел запланированным.
            'is_off_grid' => (bool) $i->is_off_grid,
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
            // Текст анонса пишет модель — но только тому, что вот-вот уйдёт в
            // канал. Поэтому у поста, стоящего на неделю вперёд, в подписи пока
            // сырое описание из парсера, и это надо показать, а не скрывать.
            // У подборки своего события нет, а текст модели есть: он лежит на
            // самой записи. Без этой ветки плашка в админке вечно обещала бы
            // «напишет ИИ», даже когда текст уже написан и стоит в посте.
            'has_ai_text' => match (true) {
                // Не «есть ли текст», а «написан ли он про ЭТОТ состав».
                // Строки лежат по номеру события, и от прежнего состава
                // остаются сироты: по ним плашка горела «написано ИИ» у
                // подборки, где текста про её события нет вовсе.
                $i->kind === TelegramChatBroadcastItem::KIND_DIGEST => $i->digestTextCoversRoster(array_column($linked, 'id')),
                $i->kind === TelegramChatBroadcastItem::KIND_VENUE => $venue !== null
                    ? trim((string) $venue->tg_portrait) !== ''
                    : null,
                $event !== null => trim((string) $event->tg_description) !== '',
                default => null,
            },
            'text_pending' => $i->text_requested_at !== null,
            // Сколько будущих повторов события делят один анонс. null и 1 —
            // событие одиночное, говорить не о чем.
            'text_repeats' => $repeats !== null && $repeats > 1 ? $repeats : null,
            // След ручной правки: что именно трогали и когда. Без него лента
            // выглядела одинаково независимо от того, собрал её сервис или
            // переставил человек.
            'edited_at' => optional($i->edited_at)?->toIso8601String(),
            'edited_fields' => $i->edited_fields ?: [],
            'text_hint' => $i->text_hint,
            'is_pinned' => (bool) $i->is_pinned,
            'publish_at' => optional($i->publish_at)?->toIso8601String(),
            'posted_at' => optional($i->posted_at)?->toIso8601String(),
            // Причина — и для ошибки, и для автоматического снятия: markSkipped
            // пишет её в то же поле, а человеку нужно понимать, почему поста
            // больше нет в ленте.
            'skip_reason' => match ($i->status) {
                TelegramChatBroadcastItem::STATUS_SKIPPED => $this->skipReason((string) $i->error_message),
                TelegramChatBroadcastItem::STATUS_REJECTED => 'rejected',
                TelegramChatBroadcastItem::STATUS_WITHDRAWN => 'withdrawn',
                default => null,
            },
            'error_message' => in_array($i->status, [
                TelegramChatBroadcastItem::STATUS_ERROR,
                TelegramChatBroadcastItem::STATUS_SKIPPED,
                TelegramChatBroadcastItem::STATUS_REJECTED,
                TelegramChatBroadcastItem::STATUS_WITHDRAWN,
            ], true) ? $i->error_message : null,
            // Ровно те картинки и в том порядке, что уйдут в канал: у события
            // — через тот же eventPhotos, которым собирается задача боту;
            // у портрета площадки картинка лежит на самой записи.
            'photos' => match ($i->kind) {
                TelegramChatBroadcastItem::KIND_VENUE => $this->venuePhotos($i),
                // У подборки своего события нет: картинки — это обложки тех
                // событий, которые она назвала. Ручной выбор, как везде,
                // сильнее автоподбора.
                TelegramChatBroadcastItem::KIND_DIGEST => is_array($i->photo_urls)
                    ? array_values(array_filter($i->photo_urls, 'is_string'))
                    : $this->broadcasts->digestPhotoUrls((int) $i->id, self::PHOTO_CANDIDATES),
                default => $this->effectivePhotos($i, $event),
            },
            // Всё, из чего можно собрать альбом. У портрета это картинки
            // площадки: раньше здесь был пустой список, и любой выбор состава
            // упирался в 422 — белый список был пуст по определению.
            'photo_candidates' => match (true) {
                $i->kind === TelegramChatBroadcastItem::KIND_VENUE && $i->venue_id !== null => $this->venuePortraits->venuePhotoUrls((int) $i->venue_id, self::PHOTO_CANDIDATES),
                $i->kind === TelegramChatBroadcastItem::KIND_DIGEST => $this->broadcasts->digestPhotoUrls((int) $i->id, self::PHOTO_CANDIDATES),
                $i->event_id !== null => $this->candidatePhotos($i, $event),
                default => [],
            },
            // Состав подборки: что именно она называет. Без этого в карточке
            // виден текст, но не видно, какие события он закрыл для ленты.
            'linked_events' => $linked,
            // Состав выбран руками — пересборка ленты его не тронет.
            'photos_manual' => is_array($i->photo_urls),
        ];
    }

    /**
     * События, названные записью: id, заголовок, день — чтобы человек видел
     * состав, а не только текст.
     *
     * @return list<array<string, mixed>>
     */
    private function linkedEvents(TelegramChatBroadcastItem $item): array
    {
        $rows = DB::table('telegram.chat_broadcast_item_events as l')
            ->join('events as e', 'e.id', '=', 'l.event_id')
            ->leftJoin('venues as v', 'v.id', '=', 'e.venue_id')
            ->where('l.item_id', $item->id)
            ->orderBy('l.position')
            ->get(['e.id', 'e.title', 'e.start_time', 'v.name as venue_name', 'l.position']);

        return $rows->values()->map(fn ($r, $i) => [
            // Номер СТРОКИ, а не колонка position: она перенумеровывается при
            // каждой пересборке (syncDigestEvents сносит состав и пишет заново
            // по дате), и показывать её как «позицию 2» значило бы показывать
            // число, которое завтра означает другое. Человеку нужен номер того,
            // что он видит в посте.
            'line' => $i + 1,
            'position' => (int) $r->position,
            'id' => (int) $r->id,
            'title' => (string) $r->title,
            // Есть ли у этой строки фраза модели. Без неё подпись берёт
            // описание с сайта-источника, и в посте рядом с двумя живыми
            // строками встаёт пресс-релиз. Глазами это видно только в
            // превью и только если знать, что искать.
            'has_hook' => $item->digestHook((int) $r->id) !== null,
            'venue' => VenueName::label($r->venue_name) ?: null,
            'start_time' => $r->start_time ? Carbon::parse($r->start_time)->toIso8601String() : null,
            'url' => $this->siteUrl().'/events/'.$r->id,
        ])->values()->all();
    }

    /**
     * Почему это событие стоит взять. Без цифр: человеку нужен повод,
     * а не балл, который у всех одинаковый.
     *
     * @param  list<int>  $feedVenueIds  площадки, уже занятые лентой
     * @param  list<int>  $feedThemeIds  темы, уже занятые лентой
     * @return list<string>
     */
    private function reasons(Event $e, array $feedVenueIds, array $feedThemeIds = []): array
    {
        $out = [];

        // Тема — первой: она отвечает на вопрос «чем эта неделя будет
        // отличаться», а площадка — только на «не повторяемся ли мы».
        $theme = $this->themePayload($e);
        if ($theme !== null && ! in_array($theme['id'], $feedThemeIds, true)) {
            $out[] = 'такой темы ещё не было на неделе';
        }
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

    /**
     * Сколько постов за неделю ушло без анонса ИИ.
     *
     * Считаем по самому событию: анонс живёт на нём, и его отсутствие сейчас
     * означает, что и в момент отправки его не было. Портреты площадок не в
     * счёт — у них свой текст и модель им не нужна.
     */
    private function postedWithoutAiText(TelegramChatBroadcast $b): int
    {
        return (int) TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $b->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_EVENT)
            ->where('status', TelegramChatBroadcastItem::STATUS_POSTED)
            ->where('posted_at', '>=', Carbon::now()->subDays(7))
            ->whereIn('event_id', Event::query()
                ->whereRaw("coalesce(btrim(tg_description), '') = ''")
                ->select('id'))
            ->count();
    }

    /**
     * Надо ли дать парсеру время написать анонс, прежде чем пост уйдёт.
     *
     * Только там, где текст действительно напишут: событие (у портрета площадки
     * свой текст), канал не отказался от анонсов ИИ, и анонса ещё нет.
     */
    private function needsTextBeforeSending(TelegramChatBroadcastItem $item, ?TelegramChatBroadcast $broadcast): bool
    {
        if (! $broadcast || ! $broadcast->ai_text) {
            return false;
        }
        if ($item->kind !== TelegramChatBroadcastItem::KIND_EVENT || ! $item->event_id) {
            return false;
        }
        if ($item->caption_source === TelegramChatBroadcastItem::CAPTION_MANUAL) {
            return false; // текст писал человек — модели тут делать нечего
        }

        $event = Event::query()->find($item->event_id);

        return $event !== null && trim((string) $event->tg_description) === '';
    }

    /** Сколько минут держим пост, пока парсер пишет ему анонс. */
    private function textGraceMinutes(): int
    {
        return max(1, (int) config('services.bot.broadcast_text_grace_minutes', 6));
    }

    private function fillCaption(TelegramChatBroadcastItem $item, TelegramChatBroadcast $broadcast, Event $event): void
    {
        try {
            $fresh = $this->captions->build(
                $event,
                (string) $broadcast->template_code,
                // «Сегодня»/«завтра» — от дня публикации, а не от дня сборки.
                $item->publish_at
                    ? \Carbon\CarbonImmutable::parse($item->publish_at)->setTimezone('Europe/Moscow')
                    : null,
                (int) $item->id,
            );

            // Ничего не изменилось — не трогаем строку: лента читается часто,
            // и лишний UPDATE на каждый показ не нужен никому.
            if ($fresh === (string) $item->caption
                && $item->caption_source === TelegramChatBroadcastItem::CAPTION_TEMPLATE) {
                return;
            }

            $item->caption = $fresh;
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
