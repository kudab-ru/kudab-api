<?php

namespace App\Services\Telegram;

use App\Contracts\Telegram\BotRoleServiceInterface;
use App\Contracts\Telegram\TelegramChatBroadcastItemRepositoryInterface;
use App\Contracts\Telegram\TelegramChatBroadcastRepositoryInterface;
use App\Contracts\Telegram\TelegramChatRepositoryInterface;
use App\Contracts\Telegram\TelegramUserRepositoryInterface;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\Venue;
use App\Services\Telegram\Scoring\EventBroadcastScorer;
use App\Support\BroadcastSafety;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramChatBroadcastService
{
    /**
     * Кап кандидатного пула под скоринг (на канал — события одного города).
     *
     * Было 100, и этого хватало, пока за раз выбиралось ОДНО событие. Для
     * ленты на неделю мало: замер по Воронежу — сотое ближайшее событие
     * стартует через четыре дня, то есть вторая половина недели в поле зрения
     * не попадала вовсе, и все семь постов набирались бы из первых дней.
     * Всего впереди 338 событий, так что 400 покрывает город целиком и
     * остаётся страховкой от «слишком плотного» города.
     */
    private const SCORING_CANDIDATE_LIMIT = 400;

    /**
     * Насколько вперёд смотрим, набирая кандидатов, в днях.
     *
     * Кап по количеству сам по себе не спасает: в плотном городе 400
     * ближайших снова уложатся в пару дней. Поэтому смотрим на окно времени,
     * а кап оставляем предохранителем.
     */
    private const CANDIDATE_HORIZON_DAYS = 14;

    /** Запас автомата до начала события — см. [[PostTiming]]. */
    private const MIN_LEAD_HOURS = PostTiming::MIN_LEAD_HOURS;

    /**
     * Сколько дней не предлагать снова отклонённое или снятое.
     *
     * Раньше отказ действовал вечно, и пул тихо истощался. Тридцать дней —
     * достаточно, чтобы отказ не выглядел проигнорированным, и мало, чтобы
     * событие не пропало навсегда.
     */
    /** Публичная: тот же срок показывает отказы в админке (AdminBroadcastController). */
    public const REJECTED_COOLDOWN_DAYS = 30;

    /** Окно cross-time анти-дубля: не повторять тот же заголовок в канале N дней. */
    private const CROSS_TIME_WINDOW_DAYS = 14;

    /**
     * Минимальный зазор между двумя постами канала, в минутах.
     *
     * Считается по факту последней отправки ЛЮБОГО вида — событие, портрет
     * площадки, «отправить сейчас». Обоснование и замеры — docs/broadcast-admin/CADENCE.md:
     * портрет уходил через минуту после дневного события, а минимальный
     * интервал в истории канала — 78 секунд между двумя событийными постами.
     */
    private const MIN_GAP_MINUTES = 90;

    // Значение по умолчанию; канал может задать своё в settings.min_gap_minutes.

    /**
     * Насколько просроченный пост ещё отправляем, в часах.
     *
     * Без отсечки зазор превращает пачку в капель: пять просроченных записей
     * растянулись бы на шесть часов, и от вечернего слота последний пост уехал
     * бы в час ночи. Пост, чей день прошёл давно, снимается: устаревший анонс
     * хуже, чем его отсутствие.
     */
    private const OVERDUE_CUTOFF_HOURS = 2;

    /**
     * Пояс, в котором задано расписание канала.
     *
     * Час в period («daily_10») — это 10:00 по Москве: так он подписан в
     * админке и так его понимает владелец. Приложение живёт в UTC
     * (config/app.php), поэтому окно выпуска обязано приводиться к этому
     * поясу явно — иначе daily_10 открывается в 13:00 МСК. Лента день
     * назначает уже по Москве (fillFeedDays), так что до этой правки план и
     * выпуск считались в разных поясах.
     */
    private const SCHEDULE_TZ = 'Europe/Moscow';

    /**
     * Lease claim'а на публикацию (сек). ≫ времени поста (тик поллера 60с) —
     * за это окно зависший claim реклеймится, но активный пост не перехватят.
     */
    public const CLAIM_LEASE_SECONDS = 300;

    public function __construct(
        private readonly TelegramUserRepositoryInterface $telegramUserRepository,
        private readonly TelegramChatRepositoryInterface $chatRepository,
        private readonly TelegramChatBroadcastRepositoryInterface $broadcastRepository,
        private readonly TelegramChatBroadcastItemRepositoryInterface $broadcastItemRepository,
        private readonly BotRoleServiceInterface $botRoleService,
        private readonly EventBroadcastScorer $scorer,
        private readonly TelegramVenuePortraitService $venuePortraitService,
        private readonly EventCaptionBuilder $captionBuilder,
        private readonly \App\Repositories\EventRepository $eventRepository,
        private readonly BroadcastSlotPlanner $slotPlanner,
        private readonly BroadcastDigestComposer $digestComposer,
    ) {}

    // ---------------------------------------------------------------------
    // Публичные методы, которые дергает API
    // ---------------------------------------------------------------------

    /**
     * Получить (или создать) настройки рассылки по telegram_id и telegram_chat_id.
     *
     * Проверяем:
     *  - что TelegramUser существует,
     *  - что чат существует,
     *  - что у пользователя есть права управлять чатами,
     *  - что этот пользователь действительно владелец чата (или хотя бы админ).
     */
    public function getSettingsByTelegram(
        int $telegramId,
        int $telegramChatId,
    ): TelegramChatBroadcast {
        [$telegramChat] = $this->resolveManagedChat($telegramId, $telegramChatId);

        return $this->broadcastRepository->getOrCreateByChatId($telegramChat->id);
    }

    /**
     * Обновить настройки рассылки (enabled/period/template) по telegram_id и telegram_chat_id.
     *
     * period / templateCode можно передавать частично:
     *  - если null — поле не меняем.
     */
    public function updateSettingsByTelegram(
        int $telegramId,
        int $telegramChatId,
        bool $enabled,
        ?string $period = null,
        ?string $templateCode = null,
    ): TelegramChatBroadcast {
        [$telegramChat] = $this->resolveManagedChat($telegramId, $telegramChatId);

        return $this->broadcastRepository->updateSettingsByChatId(
            $telegramChat->id,
            $enabled,
            $period,
            $templateCode,
        );
    }

    /**
     * Отметить, что по этому чату только что была реальная отправка рассылки.
     * Предполагается использование из планировщика.
     */
    public function markRunExecutedForChatId(
        int $chatId,
        ?DateTimeInterface $moment = null,
    ): void {
        $this->broadcastRepository->touchLastRunAt($chatId, $moment);
    }

    /**
     * Отметить, что по этому чату только что был предпросмотр (например, в личку).
     */
    public function markPreviewExecutedForChatId(
        int $chatId,
        ?DateTimeInterface $moment = null,
    ): void {
        $this->broadcastRepository->touchLastPreviewAt($chatId, $moment);
    }

    /**
     * Поставить одно событие в очередь для данного Telegram-чата.
     *
     * Работает через те же проверки прав, что и getSettingsByTelegram().
     */
    public function enqueueSingleEventForChat(
        int $telegramId,
        int $telegramChatId,
        int $eventId,
        ?DateTimeInterface $plannedAt = null,
    ): TelegramChatBroadcastItem {
        [$chat] = $this->resolveManagedChat($telegramId, $telegramChatId);

        $broadcast = $this->broadcastRepository->getOrCreateByChatId($chat->id);

        $event = Event::query()
            ->with('community')
            ->find($eventId);

        if (! $event) {
            throw new RuntimeException('Событие не найдено.');
        }

        $eventCityId = $event->community?->city_id;
        $chatCityId = $chat->city_id;

        if ($chatCityId && $eventCityId && $chatCityId !== $eventCityId) {
            throw new RuntimeException('Это событие относится к другому городу.');
        }

        // Третья дверь постановки, и до сих пор у неё не было гарда вовсе:
        // дубль не проходил только потому, что упирался в UNIQUE, то есть по
        // случайности. Для события, названного подборкой, строки очереди нет —
        // enqueue() создал бы новую запись, и пост вышел бы вторым.
        // Исключением, а не кодом ответа: это путь из бота, и текст отказа
        // человек увидит только как сообщение.
        $taken = $this->eventTakenByAnotherPost((int) $broadcast->id, $eventId);
        if ($taken) {
            throw new RuntimeException($taken->posted_at !== null
                ? 'Это событие уже публиковалось в канале.'
                : 'Это событие уже стоит в ленте канала — в другом посте.');
        }

        $item = $this->broadcastItemRepository->enqueue(
            $broadcast->id,
            $eventId,
            $plannedAt,
        );

        $this->ensureEventCaption($item, $broadcast);

        return $item;
    }

    /**
     * Пометить событие как успешно опубликованное в этом чате.
     *
     * Если элемента очереди для (broadcast_id, event_id) ещё нет —
     * создаём его на лету и сразу помечаем как опубликованный.
     */
    public function markSingleEventSentForChat(
        int $telegramId,
        int $telegramChatId,
        int $eventId,
        ?DateTimeInterface $moment = null,
        ?string $claimToken = null,
    ): void {
        [$chat] = $this->resolveManagedChat($telegramId, $telegramChatId);

        $broadcast = $this->broadcastRepository->getOrCreateByChatId($chat->id);

        $item = $this->broadcastItemRepository
            ->findByBroadcastAndEvent($broadcast->id, $eventId);

        if (! $item) {
            $item = $this->broadcastItemRepository->enqueue(
                $broadcast->id,
                $eventId,
                $moment,
            );
        }

        if ($claimToken !== null) {
            // claim-guarded путь: помечаем posted ТОЛЬКО при совпадении токена.
            // Не совпал (lease истёк, айтем реклеймил другой поллер) → не двигаем
            // last_run за чужой пост.
            $ok = $this->broadcastItemRepository->markPostedIfClaimed($item->id, $claimToken, $moment);
            if (! $ok) {
                return;
            }
        } else {
            // backward-compat (старый бот без токена) — прежнее поведение.
            $this->broadcastItemRepository->markPosted($item, $moment);
        }

        $this->broadcastRepository->touchLastRunAt($chat->id, $moment);
    }

    /**
     * Пометить айтем очереди (портрет площадки) как опубликованный — claim-guarded,
     * по item_id (у venue-поста нет event_id). В отличие от markSingleEventSentForChat
     * НЕ двигает last_run_at: у портрета свой недельный каденс (по posted_at
     * venue-айтемов), а не событийное расписание канала.
     */
    public function markItemSentForChat(int $itemId, string $claimToken, ?DateTimeInterface $moment = null): bool
    {
        $ok = $this->broadcastItemRepository->markPostedIfClaimed($itemId, $claimToken, $moment);

        if ($ok) {
            $this->bookNextDigestAfter($itemId);
        }

        return $ok;
    }

    /**
     * Ушла подборка — ставим бронь на следующую сразу.
     *
     * Бронь ставит почасовая команда, и без этого рубрика пропадала из плана
     * недели до её ближайшего прогона: пост ушёл, слот освободился, а в ленте
     * нет ни подборки, ни следа того, что она будет. Человек видит пустоту и
     * решает, что рубрика сломалась.
     *
     * Молча и без исключений: доставка уже состоялась, и падать из-за брони на
     * следующую неделю нельзя — её всё равно поставит почасовая команда.
     */
    private function bookNextDigestAfter(int $itemId): void
    {
        try {
            $item = $this->broadcastItemRepository->findById($itemId);
            if (! $item || $item->kind !== TelegramChatBroadcastItem::KIND_DIGEST) {
                return;
            }

            app(BroadcastDigestBooking::class)->bookDue(Carbon::now());
        } catch (\Throwable $e) {
            Log::warning('broadcast.digest.rebook_failed', [
                'item_id' => $itemId,
                'err' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
    }

    /**
     * Площадки города канала с готовым портретом (для пикера «Запостить площадку»
     * в боте). Проверка прав — как у остальных bot-действий (resolveManagedChat).
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function listVenuePortraitsForChat(int $telegramId, int $telegramChatId): array
    {
        [$chat] = $this->resolveManagedChat($telegramId, $telegramChatId);
        if (! $chat->city_id) {
            return [];
        }

        return Venue::query()
            ->active()
            ->where('city_id', $chat->city_id)
            ->whereNotNull('tg_portrait')
            ->where('tg_portrait', '<>', '')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name'])
            ->map(fn (Venue $v) => ['id' => (int) $v->id, 'name' => (string) $v->name])
            ->values()
            ->all();
    }

    /**
     * Ручная постановка портрета площадки из бота (кнопка). Проверка прав +
     * защита от двойного поста (one-in-flight) внутри venuePortraitService.
     */
    public function enqueueVenuePortraitForChat(int $telegramId, int $telegramChatId, int $venueId, bool $force = false): TelegramChatBroadcastItem
    {
        [$chat] = $this->resolveManagedChat($telegramId, $telegramChatId);
        $broadcast = $this->broadcastRepository->getOrCreateByChatId($chat->id);

        $reviewGate = (bool) config('services.bot.broadcast_review_gate');
        $reviewer = $chat->owner?->telegram_id;

        return $this->venuePortraitService->enqueueVenueManually(
            (int) $broadcast->id,
            $venueId,
            now(),
            $force,
            $reviewGate,
            $reviewer ? (int) $reviewer : null,
        );
    }

    /**
     * Выбрать одно событие для предпросмотра/рассылки для заданного чата.
     *
     * Логика v1:
     *  - только активные события (scopeActive),
     *  - только будущие (scopeUpcoming),
     *  - самое ближайшее по start_time,
     *  - если у чата есть city_id — берём события, где events.city совпадает по имени,
     *  - можно дополнительно исключить конкретные event_id (excludeEventIds).
     *
     * @param  int  $telegramId  Telegram ID пользователя (из лички)
     * @param  int  $telegramChatId  telegram_chat_id канала/чата
     * @param  string  $mode  'preview' | 'run' и т.п. (на будущее, пока не используется)
     * @param  array  $excludeEventIds  Список event_id, которые нельзя предлагать
     */
    public function pickSingleEventId(
        int $telegramId,
        int $telegramChatId,
        string $mode = 'preview',
        array $excludeEventIds = [],
    ): ?int {
        $chat = $this->getChatByTelegram($telegramId, $telegramChatId);

        // Берём/создаём broadcast для этого чата
        $broadcast = $this->broadcastRepository->getOrCreateByChatId($chat->id);

        // Город канала (через belongsTo City)
        $cityName = optional($chat->city)->name;

        // Нормализуем список исключаемых id
        $excludeEventIds = array_values(array_unique(array_map('intval', $excludeEventIds)));

        // Какие статусы считаем "уже использованными" для этого канала
        $usedStatuses = [
            TelegramChatBroadcastItem::STATUS_PENDING,
            TelegramChatBroadcastItem::STATUS_PLANNED,
            TelegramChatBroadcastItem::STATUS_POSTED,
            TelegramChatBroadcastItem::STATUS_SKIPPED,
        ];

        $query = Event::query()
            ->active()
            ->upcoming()
            // не брать события, по которым уже есть элемент очереди/отправки
            ->whereDoesntHave('broadcastPosts', function ($q) use ($broadcast, $usedStatuses) {
                $q->where('broadcast_id', $broadcast->id)
                    ->whereIn('status', $usedStatuses);
            });

        // Исключить конкретные id (для кнопки "следующее")
        if (! empty($excludeEventIds)) {
            $query->whereNotIn('id', $excludeEventIds);
        }

        if ($cityName) {
            $query->whereRaw('LOWER(city) = LOWER(?)', [$cityName]);
            // либо попроще:
            // $query->where('city', $cityName);
        }

        $event = $query
            ->orderBy('start_time')
            ->first();

        if ($event && $mode === 'preview') {
            $this->markPreviewExecutedForChatId($chat->id, now());
        }

        return $event?->id;
    }

    /**
     * Список элементов очереди для заданного Telegram-чата.
     *
     * По умолчанию берём только pending/planned и ограничиваем limit.
     *
     * Возвращает массив:
     * [
     *   'items' => Collection<TelegramChatBroadcastItem>,
     *   'total' => int,
     * ]
     */
    public function listQueueForChat(
        int $telegramId,
        int $telegramChatId,
        int $limit = 5,
        array $statuses = [
            TelegramChatBroadcastItem::STATUS_PENDING,
            TelegramChatBroadcastItem::STATUS_PLANNED,
        ],
    ): array {
        [$chat] = $this->resolveManagedChat($telegramId, $telegramChatId);

        $broadcast = $this->broadcastRepository->getOrCreateByChatId($chat->id);

        // Защита от странных лимитов
        $limit = max(1, min($limit, 50));

        $items = $this->broadcastItemRepository->listForBroadcast(
            $broadcast->id,
            $statuses,
            $limit,
        );

        $total = $this->broadcastItemRepository->countForBroadcast(
            $broadcast->id,
            $statuses,
        );

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Пометить событие как убранное из очереди (status = skipped)
     * для заданного Telegram-чата.
     */
    public function skipSingleEventForChat(
        int $telegramId,
        int $telegramChatId,
        int $eventId,
        ?string $reason = null,
    ): void {
        [$chat] = $this->resolveManagedChat($telegramId, $telegramChatId);

        $broadcast = $this->broadcastRepository->getOrCreateByChatId($chat->id);

        $item = $this->broadcastItemRepository->findByBroadcastAndEvent(
            $broadcast->id,
            $eventId,
        );

        if (! $item) {
            // Тихо выходим — ничего в очереди не было
            return;
        }

        $this->broadcastItemRepository->markSkipped(
            $item,
            $reason ?: 'cancelled_by_user',
        );
    }

    /**
     * Собрать пачку задач одиночной рассылки на текущий момент (pull для bot-cron).
     *
     * Каждая задача помечена 'type':
     *  - 'publish' — pending/planned/approved/auto_approved → постить в канал.
     *    [type, item_id, telegram_id(owner), telegram_chat_id, event_id, template_code]
     *  - 'review'  — pending_review без review_message_id → превью ревьюеру в ЛС.
     *    [type, item_id, reviewer_telegram_id, telegram_chat_id, event_id, template_code, review_deadline_at]
     *  pending_review с уже отправленным превью пропускается (ждём решения/таймаута).
     */
    public function collectDueSingleRuns(
        Carbon $now,
        int $limit = 50,
    ): array {
        // Важно: listEnabledWithSchedule() должен подгружать chat и owner:
        // with(['chat.owner'])
        $broadcasts = $this->broadcastRepository
            ->listEnabledWithSchedule();

        $tasks = [];

        foreach ($broadcasts as $broadcast) {
            $chat = $broadcast->chat;
            if (! $chat instanceof TelegramChat || ! $chat->telegram_chat_id) {
                continue;
            }

            // Стенду боевые каналы не отдаём: в локальной базе (дампе прода) лежат
            // настоящие telegram_chat_id. Подробности и как разрешить свой канал —
            // в App\Support\BroadcastSafety.
            if (! BroadcastSafety::postingAllowed((int) $chat->telegram_chat_id)) {
                Log::warning('broadcast.poll.blocked_non_production', [
                    'broadcast_id' => $broadcast->id,
                    'telegram_chat_id' => $chat->telegram_chat_id,
                    'app_env' => config('app.env'),
                    'hint' => BroadcastSafety::HINT_LINES[1].' '.BroadcastSafety::ALLOW_KEY,
                ]);

                continue;
            }

            // Канал молчит дольше положенного — говорим об этом владельцу, пока
            // он не увидел это сам через месяц. Проверка стоит ДО поиска активного
            // айтема: молчание чаще всего означает, что активного как раз нет
            // (или он застрял), и ниже по коду мы бы просто вышли по `continue`.
            $idleNotice = $this->buildIdleNoticeTask($broadcast, $chat, $now);
            if ($idleNotice !== null) {
                $tasks[] = $idleNotice;
            }

            // Зазор между постами. Стоит ЗДЕСЬ, в отборе записи на канал, а не
            // в гейтах по типу: иначе каждая новая рубрика приносила бы ту же
            // проблему заново. Портрет площадки гейт расписания не проходит
            // вовсе, поэтому уходил вплотную за дневным событием.
            $lastPostedAt = $this->lastPostedAt((int) $broadcast->id);
            $channelFreeAt = $lastPostedAt?->copy()->addMinutes($broadcast->min_gap_minutes);

            // Активный (в полёте) элемент канала — pending/planned/pending_review/approved/auto_approved.
            $item = $this->broadcastItemRepository->findActiveForBroadcast($broadcast->id, $now);
            if (! $item) {
                continue;
            }

            // Зазор — про посты в канал. Превью на одобрение уходит в ЛС
            // рецензенту, к ленте отношения не имеет, и придерживать его
            // нельзя: у одобрения свой дедлайн, после которого пост уходит
            // как auto_approved.
            $awaitsReviewPreview = $item->status === TelegramChatBroadcastItem::STATUS_PENDING_REVIEW;
            if (! $awaitsReviewPreview && $channelFreeAt && $channelFreeAt->gt($now)) {
                continue;
            }

            // Просрочка. День поста прошёл давно — отправлять его уже стыдно:
            // подписчик увидит анонс вчерашнего дня. Снимаем с причиной; за
            // следующую запись канал возьмётся следующим тиком (поллер читает
            // одну запись на канал за тик).
            // Просрочка отнимает у поста ДЕНЬ, а не сам пост.
            //
            // Смысл отсечки — не дать пачке просроченных уехать подряд после
            // паузы канала. Снимать их нельзя: на живой очереди три записи из
            // пяти просроченных оказались многодневками, которые ещё идут (до
            // 20 сентября, 25 сентября и 27 декабря), а подобрать их заново
            // нечем — подбор смотрит на дату НАЧАЛА, и она в прошлом.
            //
            // Без дня запись возвращается в «ждут дня»: уходить она будет по
            // одной за окно расписания, а не пачкой, текст пересоберётся под
            // день отправки, и владелец увидит её в ленте, а не недосчитается.
            // Событие, которое уже кончилось, снимет проверка выше.
            //
            // Опоздание считаем не от назначенного момента, а от того, когда
            // канал реально освободился: между зазором (90 мин) и отсечкой
            // (2 ч) всего полчаса, и без этого зазор сам загонял бы пост под
            // отсечку — задержали мы, а наказана запись.
            $dueAt = $item->publish_at
                ? ($channelFreeAt && $channelFreeAt->gt($item->publish_at) ? $channelFreeAt : $item->publish_at)
                : null;

            if ($dueAt && $dueAt->lt($now->copy()->subHours(self::OVERDUE_CUTOFF_HOURS))) {
                Log::warning('broadcast.poll.overdue_day_dropped', [
                    'broadcast_id' => $broadcast->id,
                    'item_id' => $item->id,
                    'kind' => $item->kind,
                    'publish_at' => $item->publish_at->toIso8601String(),
                    'due_at' => $dueAt->toIso8601String(),
                ]);

                // Подборка недели просрочку не переживает: её ценность в
                // моменте — вечер понедельника, когда неделя ещё вся впереди.
                // Опоздавшая на день, она рассказывает про позавчера, а состав
                // ей собирают на отправке, так что «донести старую» нечего.
                // Снимаем честно, следующую поставит бронь слота.
                if ($item->kind === TelegramChatBroadcastItem::KIND_DIGEST) {
                    $this->broadcastItemRepository->markSkipped(
                        $item,
                        'подборка не вышла в свой слот — соберём следующую',
                    );

                    continue;
                }

                // Событие без дня ждёт суточного окна — это и есть «потерять
                // день». Портрету день назначаем заново: без момента он попал
                // бы под то же окно, что события, и его недельный каденс
                // растворился бы в событийном расписании.
                $item->publish_at = $item->kind === TelegramChatBroadcastItem::KIND_VENUE
                    ? $this->slotPlanner->nextFreeSlot($broadcast, $now)?->utc()
                    : null;
                $item->save();

                continue;
            }

            // Придержка на время генерации текста: pending/planned репозиторий и так не
            // отдаёт, но ревью-статусы он отдаёт мимо planned_at — закрываем здесь.
            if ($item->planned_at !== null && $item->planned_at->isFuture()) {
                continue;
            }

            // «Текст готов» вместо «это портрет»: подборка недели устроена так
            // же — свой caption, своё событие не одно. Сравнение с одним типом
            // зачисляло бы её в события, и первый же тик снял бы её с причиной
            // «событие недоступно (удалено)», соврав человеку про удаление.
            $hasReadyCaption = $item->hasReadyCaption();
            $isVenue = $item->kind === TelegramChatBroadcastItem::KIND_VENUE;
            $inReviewFlow = in_array($item->status, [
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ], true);

            // Событийный pending/planned постится строго в СВОЁ окно расписания
            // (isSingleRunDue). Портрет площадки (свой недельный каденс) и уже-в-полёте
            // ревью-статусы доставляем независимо от событийного расписания.
            // Запись с назначенным моментом идёт ПО НЕМУ: репозиторий уже
            // отфильтровал её по publish_at <= now. Суточное окно к ней не
            // применяется — иначе второй слот дня не открылся бы никогда:
            // окно закрывается первым же постом.
            //
            // Запись БЕЗ момента сохраняет прежнее правило: событийная — под
            // суточным окном, портрет площадки мимо него. Формулировать надо
            // именно так: портрет как раз лежит без дня, и правило «окно
            // только для publish_at = NULL» загнало бы его под окно, которого
            // у него никогда не было.
            $hasOwnMoment = $item->publish_at !== null;
            // Портрет площадки раньше шёл мимо гейта целиком: у него не было
            // момента, и ждать ему было нечего. Теперь момент есть всегда, а
            // исключение по типу превращало любую запись без дня — после
            // просрочки или вытеснения — в «уехать первым тиком в любой час».
            if (! $inReviewFlow && ! $hasOwnMoment && ! $this->isSingleRunDue($broadcast, $now)) {
                continue;
            }

            $ownerTelegramId = $chat->owner?->telegram_id ?? null;

            $base = [
                'item_id' => (int) $item->id,
                'telegram_chat_id' => (int) $chat->telegram_chat_id,
            ];
            if ($hasReadyCaption) {
                // Подборка собирается ЗДЕСЬ, перед самой отправкой. Собранная
                // при постановке, она показала бы пятую часть недели: на момент
                // брони событий следующей недели вчетверо меньше, чем будет.
                if ($item->kind === TelegramChatBroadcastItem::KIND_DIGEST
                    && trim((string) $item->caption) === '') {
                    if (! $this->prepareDigest($item, $broadcast, $now)) {
                        continue;
                    }
                }

                // Готовый текст + НЕСКОЛЬКО обложек-прокси (альбом).
                // Портрет площадки: картинки берутся у площадки.
                // Ручной выбор сильнее автоподбора — как у событий. Без этой
                // ветки состав альбома, собранный в админке, до канала не
                // доезжал: выдача каждый раз пересобирала набор по площадке.
                // NULL — «собрать автоматически», пустой массив — осознанное
                // «без картинок».
                $manualPhotos = is_array($item->photo_urls);
                if ($manualPhotos) {
                    $photoUrls = array_values(array_filter($item->photo_urls, 'is_string'));
                } else {
                    // У подборки своей площадки нет — её картинки это обложки
                    // названных событий. Без этой ветки первая живая подборка
                    // ушла в канал голым текстом: в админке обложки были
                    // видны, а сюда не доезжали.
                    $photoUrls = match (true) {
                        $item->kind === TelegramChatBroadcastItem::KIND_DIGEST
                            => $this->digestPhotoUrls((int) $item->id, TelegramVenuePortraitService::ALBUM_LIMIT),
                        $item->venue_id !== null
                            => $this->venuePortraitService->venuePhotoUrls(
                                (int) $item->venue_id,
                                TelegramVenuePortraitService::ALBUM_LIMIT,
                            ),
                        default => [],
                    };
                    if ($photoUrls === [] && $item->photo_url) {
                        $photoUrls = [(string) $item->photo_url];
                    }
                }
                $base += [
                    // Тип отдаём как есть: бот разбирает задачу по нему, и
                    // подборка обязана прийти к нему подборкой.
                    'kind' => (string) $item->kind,
                    'caption' => (string) $item->caption,
                    // При ручном наборе фолбэка на обложку быть не должно:
                    // пустой массив — это осознанное «без картинок», и обложка
                    // сводила бы выбор на нет.
                    'photo_url' => $manualPhotos ? ($photoUrls[0] ?? null) : ($photoUrls[0] ?? $item->photo_url),
                    'photo_urls' => $photoUrls,
                ];
            } else {
                // Событие могло исчезнуть ПОСЛЕ постановки в очередь: софт-удаление
                // не трогает строку очереди (каскада по FK не происходит), и бот
                // потом бесконечно ходит за ним в GET /api/bot/events/{id}, получает
                // 404 и не помечает айтем никак — ни отправленным, ни ошибочным.
                // Айтем остаётся в полёте, а защита «одно событие в полёте»
                // (см. findActiveForBroadcast ниже по коду) держит из-за него ВЕСЬ
                // канал. Так рассылка Воронежа простояла 33 дня на одной записи.
                // Постоянный отказ закрываем здесь, где о нём вообще можно узнать:
                // у бота штатного способа закрыть провал нет, есть только mark-sent.
                $event = $item->event_id ? Event::query()->find($item->event_id) : null;
                if (! $event) {
                    $this->broadcastItemRepository->markSkipped(
                        $item,
                        'событие '.($item->event_id ?? '?').' недоступно (удалено) — снято из очереди',
                    );

                    continue;
                }

                // Событие могло закончиться, пока пост лежал в очереди. Между
                // постановкой и отправкой проверки времени не было вообще: при
                // паузе канала или просроченном дне первым уходил анонс уже
                // прошедшего. Правило то же, что в админке при постановке и
                // переносе: конец события, а если его нет — начало. Именно
                // конец, иначе снялись бы живые многодневки.
                $endsAt = $event->end_time ?: $event->start_time;
                if ($endsAt && Carbon::parse($endsAt)->lt($now)) {
                    $this->broadcastItemRepository->markSkipped(
                        $item,
                        'событие '.$event->id.' уже прошло к моменту отправки — снято из очереди',
                    );
                    Log::warning('broadcast.poll.skipped_event_finished', [
                        'broadcast_id' => $broadcast->id,
                        'item_id' => $item->id,
                        'event_id' => $event->id,
                        'ends_at' => Carbon::parse($endsAt)->toIso8601String(),
                    ]);

                    continue;
                }

                // Текст собирается при постановке и больше не пересобирается.
                // Пост, пролежавший лишний день, уходил дословно — со словом
                // «сегодня» про позавчера. Здесь, на отправке, день известен
                // точно, поэтому шаблонный текст пересобираем под него.
                $this->ensureEventCaption($item, $broadcast, $event, \Carbon\CarbonImmutable::parse($now));
                // Ручной выбор сильнее автоподбора. NULL — «как раньше»,
                // пустой массив — осознанное «без картинок».
                $eventPhotos = is_array($item->photo_urls)
                    ? array_values(array_filter($item->photo_urls, 'is_string'))
                    : $this->eventPhotos((int) $item->event_id);

                $base += [
                    'kind' => 'event',
                    'event_id' => (int) $item->event_id,
                    'template_code' => (string) $broadcast->template_code,
                    // Готовый текст. Бот предпочитает его, а template_code
                    // оставлен на переходный период: пока не выкачены обе
                    // стороны, старый бот должен продолжать работать.
                    'caption' => (string) ($item->caption ?? ''),
                    // Картинки той же формой, что у портретов площадок. Без
                    // них бот ходил бы за событием только ради обложки.
                    'photo_url' => $eventPhotos[0] ?? null,
                    'photo_urls' => $eventPhotos,
                ];
            }

            if ($item->status === TelegramChatBroadcastItem::STATUS_PENDING_REVIEW) {
                // Превью ещё не отправлено → review-задача; уже отправлено → ждём решения/таймаута.
                if ($item->review_message_id) {
                    continue;
                }
                $reviewerTelegramId = (int) ($item->review_reviewer_telegram_id ?? $ownerTelegramId ?? 0);
                if (! $reviewerTelegramId) {
                    continue;
                }
                $tasks[] = $base + [
                    'type' => 'review',
                    'reviewer_telegram_id' => $reviewerTelegramId,
                    'review_deadline_at' => optional($item->review_deadline_at)?->toIso8601String(),
                ];
            } else {
                // pending/planned/approved/auto_approved → публикуем в канал.
                if (! $ownerTelegramId) {
                    // Канал без владельца не публикует НИЧЕГО, и раньше молчал
                    // об этом: ни поста, ни лога, ни ЛС-сигнала, ни признака в
                    // админке. Включённый канал выглядел рабочим и не отдавал
                    // ни одной ошибки. Теперь отказ хотя бы виден в логе, а в
                    // админке его показывает channelProblems.
                    Log::warning('broadcast.poll.skipped_no_owner', [
                        'broadcast_id' => $broadcast->id,
                        'telegram_chat_id' => $chat->telegram_chat_id,
                        'item_id' => $item->id,
                        'hint' => 'у чата не задан telegram_user_id — владелец канала',
                    ]);

                    continue;
                }
                // claim-before-post: атомарно клеймим айтем, чтобы параллельный
                // поллер / повторный poll после краша не запостил его дважды.
                // Не заклеймили (уже в полёте у другого) — пропускаем.
                $claimToken = $this->broadcastItemRepository->claimForPublish(
                    (int) $item->id,
                    $now,
                    self::CLAIM_LEASE_SECONDS,
                );
                if ($claimToken === null) {
                    continue;
                }
                $tasks[] = $base + [
                    'type' => 'publish',
                    'telegram_id' => (int) $ownerTelegramId,
                    'claim_token' => $claimToken,
                ];
            }

            if (\count($tasks) >= $limit) {
                break;
            }
        }

        return $tasks;
    }

    /**
     * P0 автопостинг, фаза 1 — автонаполнение очереди.
     *
     * Для каждого enabled+due city-канала (расписание в settings.period), у которого
     * очередь пуста, подбирает одно событие города и кладёт в очередь (status=pending).
     * Сам постинг — существующий bot-cron (collectDueSingleRuns → poll → send → mark-sent).
     *
     * Идёт из Laravel scheduler (broadcast:enqueue-due, withoutOverlapping). last_run_at
     * НЕ трогаем здесь — его двигает фактический пост; пока он не сдвинулся, isSingleRunDue
     * остаётся true, поэтому защищаемся «одно событие в полёте» (queue_busy).
     *
     * @return array{checked:int,due:int,enqueued:int,skipped_no_city:int,skipped_queue_busy:int,no_candidate:int,skipped_no_reviewer:int}
     */
    public function enqueueDueForAllChannels(Carbon $now, bool $dryRun = false): array
    {
        $summary = [
            'checked' => 0,
            'due' => 0,
            'enqueued' => 0,
            'skipped_no_city' => 0,
            'skipped_queue_busy' => 0,
            'no_candidate' => 0,
            'skipped_no_reviewer' => 0,
            'skipped_not_allowed' => 0,
        ];

        $broadcasts = $this->broadcastRepository->listEnabledWithSchedule();

        foreach ($broadcasts as $broadcast) {
            $summary['checked']++;

            if (! $this->isSingleRunDue($broadcast, $now)) {
                continue;
            }
            $summary['due']++;

            $chat = $broadcast->chat;
            if (! $chat instanceof TelegramChat || ! $chat->city_id || ! $chat->telegram_chat_id) {
                $summary['skipped_no_city']++;
                Log::warning('broadcast.enqueue.skipped_no_city', [
                    'broadcast_id' => $broadcast->id,
                    'telegram_chat_id' => $chat?->telegram_chat_id,
                    'hint' => 'у канала не задан город — задай telegram:chat:set-city',
                ]);

                continue;
            }

            // На стенде очередь боевого канала даже не наполняем: иначе она копит
            // посты, которые никогда не уйдут, и «одно событие в полёте» блокирует
            // канал так же, как это сделала удалённая запись. См. BroadcastSafety.
            if (! BroadcastSafety::postingAllowed((int) $chat->telegram_chat_id)) {
                $summary['skipped_not_allowed']++;

                continue;
            }

            // Лента канала: держим в очереди до feed_limit СОБЫТИЙНЫХ записей.
            // Раньше здесь стояло жёсткое «одно в полёте» (open > 0), из-за
            // которого одна отравленная запись остановила канал на 33 дня и
            // из-за которого нельзя было собрать план на неделю.
            //
            // Портреты площадок в этот счёт НЕ входят: у них свой недельный
            // каденс, и общий счётчик заблокировал бы их навсегда, стоит ленте
            // заполниться. Их гейт живёт в TelegramVenuePortraitService.
            $openEvents = $this->broadcastItemRepository->countOpenForBroadcast($broadcast->id, 'event');
            if ($openEvents >= $broadcast->feed_limit) {
                $summary['skipped_queue_busy']++;

                continue;
            }

            $eventId = $this->pickBestEventIdForChat(
                $chat,
                $broadcast->id,
                repeatCap: $this->venueRepeatCap($broadcast),
            );
            if (! $eventId) {
                $summary['no_candidate']++;
                // Канал «созрел», но нет подходящего события — голодание (мониторим).
                Log::warning('broadcast.enqueue.no_candidate', [
                    'broadcast_id' => $broadcast->id,
                    'telegram_chat_id' => $chat->telegram_chat_id,
                    'city_id' => $chat->city_id,
                    'hint' => 'нет active+upcoming события города (нужного качества / не дубль / не sold_out)',
                ]);

                continue;
            }

            // Ревью-гейт (P0.5, флаг services.bot.broadcast_review_gate): кладём
            // pending_review + адресат превью (owner) + дедлайн авто-постинга.
            if ((bool) config('services.bot.broadcast_review_gate')) {
                $reviewerTelegramId = $chat->owner?->telegram_id;
                if (! $reviewerTelegramId) {
                    $summary['skipped_no_reviewer']++;
                    Log::warning('broadcast.enqueue.no_reviewer', [
                        'broadcast_id' => $broadcast->id,
                        'telegram_chat_id' => $chat->telegram_chat_id,
                        'hint' => 'ревью-гейт включён, но у канала нет owner для превью',
                    ]);

                    continue;
                }
                if (! $dryRun) {
                    $deadline = $now->copy()->addMinutes(
                        (int) config('services.bot.broadcast_review_timeout_minutes', 120),
                    );
                    $this->broadcastItemRepository->enqueueForReview(
                        $broadcast->id,
                        $eventId,
                        (int) $reviewerTelegramId,
                        $deadline,
                        $now->copy()->addMinutes($this->textGraceMinutes()),
                    );
                }
            } elseif (! $dryRun) {
                // придержка на время генерации ТГ-текста; снимает её парсер, а если
                // не успел — она истекает сама и пост уходит со старым description.
                // Канал, отказавшийся от анонсов ИИ, ждать незачем: писать текст
                // всё равно никто не будет, а пост простоял бы эти минуты зря.
                $item = $this->broadcastItemRepository->enqueue(
                    $broadcast->id,
                    $eventId,
                    $broadcast->ai_text ? $now->copy()->addMinutes($this->textGraceMinutes()) : null,
                );

                // Текст собираем СРАЗУ, а не перед отправкой: пост появляется
                // в плане канала уже с текстом, иначе в админке нечего
                // показывать и нечего править. Выдача боту оставлена
                // страховкой на случай записи, созданной в обход.
                $this->ensureEventCaption($item, $broadcast);
            }

            $summary['enqueued']++;
        }

        return $summary;
    }

    /**
     * Занято ли событие другим постом канала — тем же вопросом, что в дверях
     * админки. Спрашиваем связь: пост-подборка называет несколько событий и
     * своей строки под каждое не заводит.
     */
    private function eventTakenByAnotherPost(int $broadcastId, int $eventId): ?TelegramChatBroadcastItem
    {
        return TelegramChatBroadcastItem::query()
            ->from('telegram.chat_broadcast_items as i')
            ->select('i.*')
            ->join('telegram.chat_broadcast_item_events as l', 'l.item_id', '=', 'i.id')
            ->where('i.broadcast_id', $broadcastId)
            ->where('l.event_id', $eventId)
            ->where(function ($q) {
                $q->whereNotNull('i.posted_at')
                    ->orWhereIn('i.status', [
                        TelegramChatBroadcastItem::STATUS_PENDING,
                        TelegramChatBroadcastItem::STATUS_PLANNED,
                        TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                        TelegramChatBroadcastItem::STATUS_APPROVED,
                        TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
                    ]);
            })
            ->first();
    }

    /**
     * Наполнить бронь подборки: тема, состав, текст.
     *
     * Возвращает false, если наполнять нечем — тогда запись снята, и цикл
     * доставки должен идти дальше. Пустую подборку отправлять нельзя: бот
     * положит задачу без текста в bad_task и не пометит её никак, а защита
     * «одна запись в полёте» задержит из-за неё весь канал.
     */
    /**
     * Довести подборку до отправляемого вида — в момент слота.
     *
     * ДВА ШАГА, И МЕЖДУ НИМИ ПРИДЕРЖКА. Так же, как у обычного поста: сначала
     * известно, про ЧТО пишем, потом модель пишет, и только потом пост уходит.
     *
     *  1. Состава ещё нет — выбираем его (это и есть «про что») и, если канал
     *     пишет тексты, придерживаем пост на несколько минут и ставим заявку.
     *     Подпись при этом снимаем: пустая подпись — сигнал «собрать заново».
     *  2. Состав уже выбран — собираем подпись ПО НЕМУ, с текстом модели, если
     *     он успел появиться. Переизбирать состав на этом шаге нельзя: заново
     *     выбранная тройка была бы другой (см. recompose), и оплаченный текст
     *     достался бы не тем событиям.
     *
     * Парсер не успел или упал — второй шаг просто соберёт подпись из фактов и
     * первых фраз описаний: деградация пассивная, ровно как у событий.
     *
     * @return bool false — на этом тике отправлять нечего: запись снята или ждёт текста
     */
    private function prepareDigest(
        TelegramChatBroadcastItem $item,
        TelegramChatBroadcast $broadcast,
        Carbon $now,
    ): bool {
        if ($this->digestRosterSize($item) === 0 || $item->digestTheme() === null) {
            if (! $this->composeDigest($item, $broadcast, $now)) {
                return false;
            }

            if ($this->digestWantsText($item, $broadcast)) {
                $this->requestDigestText($item, $now);

                return false;
            }

            return true;
        }

        $draft = $this->digestComposer->recompose($item, $broadcast, $now);

        if ($draft === null) {
            // Состав рассыпался (события удалили или они уже начались) — это
            // не повод молчать: собираем заново, как в первый раз.
            return $this->composeDigest($item, $broadcast, $now);
        }

        $this->applyDigestDraft($item, $draft);

        Log::info('broadcast.digest.recomposed', [
            'item_id' => $item->id,
            'theme' => $draft['theme_slug'],
            'named' => count($draft['event_ids']),
            'with_text' => $item->hasDigestText(),
        ]);

        return true;
    }

    /** Сколько событий уже названо в связи «пост → события». */
    private function digestRosterSize(TelegramChatBroadcastItem $item): int
    {
        return DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)
            ->count();
    }

    /**
     * Стоит ли дать модели написать текст подборки.
     *
     * Тот же выключатель, что у событий (`settings.ai_text`): канал, который
     * отказался от текстов ИИ, получает подборку из фактов и первых фраз
     * описаний — и не платит за модель.
     */
    private function digestWantsText(TelegramChatBroadcastItem $item, TelegramChatBroadcast $broadcast): bool
    {
        return $broadcast->ai_text
            && $item->caption_source !== TelegramChatBroadcastItem::CAPTION_MANUAL
            && ! $item->hasDigestText();
    }

    /**
     * Придержать подборку и попросить текст.
     *
     * Подпись снимаем намеренно: пустая подпись — единственный сигнал, по
     * которому доставка вернётся к сборке. Шаблонная подпись, оставленная на
     * это время, уехала бы в канал первой же попыткой отправки.
     */
    private function requestDigestText(TelegramChatBroadcastItem $item, Carbon $now): void
    {
        $grace = max(1, (int) config('services.bot.broadcast_text_grace_minutes', 6));

        $item->caption = null;
        $item->caption_source = null;
        $item->text_requested_at = $now;
        $item->planned_at = $now->copy()->addMinutes($grace);
        $item->save();

        Log::info('broadcast.digest.text_requested', [
            'item_id' => $item->id,
            'theme' => $item->digestTheme(),
            'hold_minutes' => $grace,
        ]);
    }

    private function composeDigest(
        TelegramChatBroadcastItem $item,
        TelegramChatBroadcast $broadcast,
        Carbon $now,
    ): bool {
        $draft = $this->digestComposer->compose($broadcast, $now, $item);

        if ($draft === null) {
            // Не набралось темы — честно снимаем и освобождаем слот.
            // Следующую бронь поставит broadcast:enqueue-digests.
            $this->broadcastItemRepository->markSkipped(
                $item,
                'подборка: на этой неделе не набралось темы с достаточным составом',
            );

            return false;
        }

        $this->applyDigestDraft($item, $draft);

        Log::info('broadcast.digest.composed', [
            'item_id' => $item->id,
            'theme' => $draft['theme']['slug'] ?? null,
            'named' => count($draft['event_ids']),
            'total' => $draft['total'],
        ]);

        return true;
    }

    /**
     * Записать в запись то, что собрал композитор: текст и состав.
     *
     * Публичный, потому что собрать подборку можно двумя путями: сама перед
     * отправкой и руками из админки, когда человек хочет увидеть и поправить
     * текст заранее. Второй путь — осознанный выбор: собранный заранее состав
     * к выходу устареет, зато его можно править.
     *
     * @param  array{caption: string, event_ids: list<int>}  $draft
     */
    public function applyDigestDraft(TelegramChatBroadcastItem $item, array $draft): void
    {
        $theme = (string) ($draft['theme_slug'] ?? ($draft['theme']['slug'] ?? ''));
        $meta = (array) ($item->digest_meta ?? []);

        // Подводка написана про КОНКРЕТНУЮ тройку и тему: «в субботу», «эта же
        // сцена» — всё это про соседей по посту. Сменились тема или состав —
        // подводка врёт, и её надо снять. Строки про события смену переживают:
        // они привязаны к id и уезжают вместе со своим событием.
        $roster = array_values(array_map('intval', (array) ($draft['event_ids'] ?? [])));
        $writtenFor = array_values(array_map('intval', (array) ($meta['roster'] ?? [])));
        sort($roster);
        sort($writtenFor);

        if (($theme !== '' && ($meta['theme'] ?? null) !== $theme) || $writtenFor !== $roster) {
            unset($meta['intro']);
        }
        if ($theme !== '') {
            $meta['theme'] = $theme;
            // Человеческое имя темы — для того, кто пишет текст: реестр тем
            // живёт здесь, и гонять парсер за ним в чужой конфиг незачем.
            $meta['theme_title'] = (string) ($draft['theme']['title'] ?? $theme);
        }

        $item->caption = $draft['caption'];
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_TEMPLATE;
        $item->digest_meta = $meta === [] ? null : $meta;
        $item->save();

        $this->syncDigestEvents($item, $draft['event_ids']);
    }

    /**
     * Записать состав подборки в связь «пост → события».
     *
     * Позиции с ЕДИНИЦЫ: ноль занят ведущим событием обычного поста, и на него
     * стоит частичный уникальный индекс. Именно эти строки закрывают названные
     * события для собственных постов — ради них связь и заводилась.
     *
     * @param  list<int>  $eventIds
     */
    private function syncDigestEvents(TelegramChatBroadcastItem $item, array $eventIds): void
    {
        DB::table('telegram.chat_broadcast_item_events')->where('item_id', $item->id)->delete();

        $rows = [];
        foreach (array_values(array_unique($eventIds)) as $i => $eventId) {
            $rows[] = [
                'item_id' => $item->id,
                'event_id' => $eventId,
                'position' => $i + 1,
                'created_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('telegram.chat_broadcast_item_events')->insertOrIgnore($rows);
        }
    }

    /**
     * Обложки событий, названных подборкой: по одной на событие, в порядке
     * появления в тексте.
     *
     * ЗДЕСЬ, а не только в админке. Первая живая подборка ушла без картинок
     * ровно поэтому: выдача ленты собирала обложки для показа человеку, а путь
     * доставки о них не знал и слал пустой список. Один источник на оба пути.
     *
     * @return list<string>
     */
    public function digestPhotoUrls(int $itemId, int $limit = 4): array
    {
        $ids = DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $itemId)
            ->orderBy('position')
            ->pluck('event_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $out = [];
        foreach ($ids as $eventId) {
            $cover = $this->eventPhotos($eventId, 1);
            if ($cover !== [] && ! in_array($cover[0], $out, true)) {
                $out[] = $cover[0];
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * События, о которых рассказывает запись очереди.
     *
     * У обычного поста одно, у подборки — все названные. Нужен там, где мы
     * помним «это уже разложено в этом прогоне»: иначе одна пересборка
     * разложила бы события уже собранной подборки по отдельным дням.
     *
     * @return list<int>
     */
    private function linkedEventIds(int $itemId): array
    {
        return DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $itemId)
            ->pluck('event_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Сколько минут держим айтем, пока парсер пишет ТГ-текст. */
    private function textGraceMinutes(): int
    {
        return max(0, (int) config('services.bot.broadcast_text_grace_minutes', 6));
    }

    /**
     * P0.5: отметить, что превью ревью отправлено в ЛС (персист message_id, чтобы
     * не слать повторно на следующем poll-тике).
     */
    public function markReviewPreviewSent(int $itemId, int $messageId): void
    {
        $item = $this->broadcastItemRepository->findById($itemId);
        if (! $item) {
            throw new RuntimeException('Элемент очереди не найден.');
        }

        $this->broadcastItemRepository->setReviewMessageId($item, $messageId);
    }

    /**
     * P0.5: решение ревьюера по pending_review (approve/reject). Идемпотентно: если
     * решение уже принято (статус ≠ pending_review) — no-op. Решать может только
     * адресат превью (snapshot review_reviewer_telegram_id).
     */
    public function decideReview(int $telegramId, int $itemId, bool $approve): void
    {
        $item = $this->broadcastItemRepository->findById($itemId);
        if (! $item) {
            throw new RuntimeException('Элемент очереди не найден.');
        }

        if ($item->status !== TelegramChatBroadcastItem::STATUS_PENDING_REVIEW) {
            return; // уже решено/уехало дальше — идемпотентно ok
        }

        // fail-closed: без снапшота reviewer (===0) решать нельзя.
        $reviewer = (int) ($item->review_reviewer_telegram_id ?? 0);
        if ($reviewer === 0 || $reviewer !== $telegramId) {
            throw new RuntimeException('Это превью адресовано другому пользователю.');
        }

        $this->broadcastItemRepository->applyReviewDecision(
            $item,
            $approve ? TelegramChatBroadcastItem::STATUS_APPROVED : TelegramChatBroadcastItem::STATUS_REJECTED,
            $approve ? 'approve' : 'reject',
            now(),
        );
    }

    /**
     * P0.5: авто-одобрить просроченные pending_review. Возвращает число затронутых.
     */
    public function autoApproveExpiredReviews(Carbon $now): int
    {
        return $this->broadcastItemRepository->autoApproveExpiredReviews($now);
    }

    // ---------------------------------------------------------------------
    // Внутренние помощники
    // ---------------------------------------------------------------------

    /**
     * Проверка прав + поиск чата, которым можно управлять.
     *
     * Возвращает кортеж [TelegramChat, роль].
     */
    private function resolveManagedChat(
        int $telegramId,
        int $telegramChatId,
    ): array {
        $role = $this->botRoleService->getRoleByTelegramId($telegramId);

        // Кто вообще может управлять чатами
        if (! in_array($role, ['user', 'moderator', 'admin', 'superadmin'], true)) {
            throw new RuntimeException('Недостаточно прав для управления связанными чатами');
        }

        $telegramUser = $this->telegramUserRepository->findByTelegramId($telegramId);
        if (! $telegramUser) {
            throw new RuntimeException('Telegram-пользователь не найден в БД');
        }

        $telegramChat = $this->chatRepository->findByTelegramChatId($telegramChatId);
        if (! $telegramChat) {
            throw new RuntimeException('Чат не найден в БД: '.$telegramChatId);
        }

        // Базовое ограничение: чат должен принадлежать этому пользователю.
        if ($telegramChat->telegram_user_id !== $telegramUser->id) {
            // Разрешаем superadmin/admin управлять любыми чатами (опционально).
            if (! in_array($role, ['admin', 'superadmin'], true)) {
                throw new RuntimeException('Этот чат не привязан к текущему пользователю');
            }
        }

        return [$telegramChat, $role];
    }

    /**
     * Вытянуть TelegramChat с проверкой прав.
     *
     * Тонкая обёртка над resolveManagedChat, чтобы не дублировать проверки.
     */
    private function getChatByTelegram(
        int $telegramId,
        int $telegramChatId,
    ): TelegramChat {
        [$chat] = $this->resolveManagedChat($telegramId, $telegramChatId);

        return $chat;
    }

    /**
     * Автономный подбор события для канала (без проверки прав и markPreview).
     *
     * Отличие от pickSingleEventId (ручной флоу): фильтр города через
     * community.city_id == chat.city_id (надёжнее строкового LOWER(city)=name) и
     * без user-контекста. Phase 1: top-1 по start_time; контент-скоринг — P0.3.
     *
     * @param  int[]  $excludeEventIds
     */
    private function pickBestEventIdForChat(
        TelegramChat $chat,
        int $broadcastId,
        array $excludeEventIds = [],
        ?Carbon $notBefore = null,
        ?int $repeatCap = null,
        ?int $avoidInterestId = null,
    ): ?int {
        // Навсегда исключаем только то, что уже прозвучало или стоит в ленте.
        // (error — НЕ включаем: отправку можно ретраить.)
        $usedStatuses = [
            TelegramChatBroadcastItem::STATUS_PENDING,
            TelegramChatBroadcastItem::STATUS_PLANNED,
            TelegramChatBroadcastItem::STATUS_POSTED,
            TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
            TelegramChatBroadcastItem::STATUS_APPROVED,
            TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
        ];

        // А ОТКЛОНЁННОЕ — на срок. Раньше отклонённое и снятое лежали в общем
        // списке без всякой давности: событие, один раз отклонённое, выпадало
        // из пула НАВСЕГДА. Пока лента собиралась сама, это было почти
        // незаметно; как только её начнут править руками из админки, каждый
        // отказ будет отъедать пул безвозвратно.
        $rejectedSince = Carbon::now()->subDays(self::REJECTED_COOLDOWN_DAYS);

        $query = Event::query()
            ->active()
            ->upcoming()
            ->whereDoesntHave('broadcastPosts', function ($q) use ($broadcastId, $usedStatuses) {
                $q->where('broadcast_id', $broadcastId)
                    ->whereIn('status', $usedStatuses);
            })
            ->whereDoesntHave('broadcastPosts', function ($q) use ($broadcastId, $rejectedSince) {
                $q->where('broadcast_id', $broadcastId)
                    // ТОЛЬКО rejected. skipped — это «снято из очереди», а не
                    // «не предлагать»: так помечается и снятое руками, и
                    // вытесненное пересборкой, и запись под удалённым событием.
                    // Держать их в остывании значило бы прятать событие на
                    // месяц каждый раз, когда его просто убрали из ленты.
                    ->where('status', TelegramChatBroadcastItem::STATUS_REJECTED)
                    // Полным именем: подзапрос джойнит две таблицы, и короткое
                    // имя разрешается однозначно только пока в таблице связи
                    // нет такой колонки (её там нет намеренно).
                    ->where('telegram.chat_broadcast_items.updated_at', '>=', $rejectedSince);
            })
            ->whereHas('community', function ($q) use ($chat) {
                $q->where('city_id', $chat->city_id);
            })
            // Жёсткие фильтры (NULL-safe): не распроданное, не официоз/религия.
            ->where(function ($q) {
                $q->whereNull('tickets_status')
                    ->orWhere('tickets_status', '!=', 'sold_out');
            })
            ->where(function ($q) {
                $q->whereNull('content_kind')
                    ->orWhereNotIn('content_kind', ['official', 'religious']);
            })
            // Анти-дубль по группе (Layer 2): не предлагать событие, чья event_group
            // уже занята в этом канале (другой источник того же события).
            ->where(function ($q) use ($broadcastId, $usedStatuses) {
                $q->whereNull('events.event_group_id')
                    ->orWhereNotExists(function ($sub) use ($broadcastId, $usedStatuses) {
                        $sub->selectRaw('1')
                            ->from('telegram.chat_broadcast_items as i')
                            // Через связь: пост-подборка называет несколько
                            // событий, и по колонке записи ни одна их группа
                            // не считалась бы занятой.
                            ->join('telegram.chat_broadcast_item_events as l', 'l.item_id', '=', 'i.id')
                            ->join('events as e2', 'e2.id', '=', 'l.event_id')
                            ->where('i.broadcast_id', $broadcastId)
                            ->whereIn('i.status', $usedStatuses)
                            ->whereColumn('e2.event_group_id', 'events.event_group_id');
                    });
            });

        if (! empty($excludeEventIds)) {
            $query->whereNotIn('id', array_values(array_unique(array_map('intval', $excludeEventIds))));
        }

        // Тема соседнего слота. Сравниваем по ПЕРВИЧНОМУ интересу (rank = 0):
        // вторичные теги стоят у события пачками, и по ним «та же тема»
        // совпало бы почти у всего.
        if ($avoidInterestId !== null) {
            $query->whereNotExists(function ($q) use ($avoidInterestId) {
                $q->selectRaw('1')
                    ->from('event_interest as ei_theme')
                    ->whereColumn('ei_theme.event_id', 'events.id')
                    ->where('ei_theme.rank', 0)
                    ->where('ei_theme.interest_id', $avoidInterestId);
            });
        }

        // Горизонт — от ДНЯ СЛОТА, а не от сегодня. Считая верхнюю границу от
        // «сейчас», а нижнюю от слота, окно кандидатов сужалось с каждым днём
        // вперёд и в конце недели схлопывалось: замер 2026-09-15 по каналу
        // Воронежа — на слот 28.09 кандидатов с форой в сутки было НОЛЬ против
        // 59 при окне от слота, на 27.09 — 4 против 58. Ближние дни правило не
        // трогает вовсе (16.09: 185 и 185) — там окно и так не упиралось.
        $query->where(
            'start_time',
            '<=',
            ($notBefore ?? Carbon::now())->copy()->addDays(self::CANDIDATE_HORIZON_DAYS),
        );

        // Нижняя граница — момент публикации плюс минимальная фора. upcoming()
        // отсекает по «сейчас», а пост может уйти через неделю, и к тому дню
        // событие уже пройдёт.
        //
        // ФОРА обязательна: без неё в ленте оказывались посты про событие,
        // которое начнётся через час, и даже про уже начавшееся — прежнее
        // условие пускало однодневку по `end_time >= момент публикации`. Живой
        // пример: мастер-класс 13:00–15:00 стоял в слоте 15:00, то есть пост
        // уходил в минуту его окончания. Из 27 записей ленты 5 были про уже
        // начавшееся, ещё 8 давали меньше шести часов (замер 2026-09-15).
        //
        // Многодневки (выставки, прокат спектакля) по-прежнему годятся, пока
        // идут: у них «фора» — это время до закрытия, а не до начала.
        if ($notBefore !== null) {
            $from = $notBefore->copy()->addHours(self::MIN_LEAD_HOURS);

            $query->where(function ($w) use ($from) {
                $w->where('start_time', '>=', $from)
                    ->orWhere(function ($x) use ($from) {
                        $x->whereNotNull('end_time')
                            ->whereRaw("end_time > start_time + interval '24 hours'")
                            ->where('end_time', '>=', $from);
                    });
            });
        }

        // Кандидатный пул — ближайшие, кап; качество выбираем скорингом в PHP.
        $candidates = $query
            ->with(['sources:id,event_id,images,published_at', 'venue:id,name'])
            ->withCount('interests')
            ->orderBy('start_time')
            ->limit(self::SCORING_CANDIDATE_LIMIT)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Анти-дубль cross-time (Layer 3): предпочитаем события, чей заголовок не
        // постился в канале за окно (ловит повторяющиеся: один title, разные даты =
        // разные группы). Soft — если свежих по заголовку нет, постим из общего пула.
        $recentTitles = $this->recentlyPostedTitleNorms(
            $broadcastId,
            now()->subDays(self::CROSS_TIME_WINDOW_DAYS),
        );

        $pool = $candidates;
        if (! empty($recentTitles)) {
            $fresh = $candidates->reject(
                fn (Event $e) => in_array($this->normalizeTitle($e->title), $recentTitles, true),
            );
            if ($fresh->isNotEmpty()) {
                $pool = $fresh;
            }
        }

        // Анти-однообразие (Layer 4). Заголовки в ленте уже не повторяются, но
        // этого мало: на живой сборке недели вышло семь разных заголовков и
        // всего четыре площадки — «Матрёшка» три раза из семи. По заголовкам
        // концентрация площадки не ловится в принципе.
        //
        // Soft, как и слой выше: если после фильтра не осталось ничего, лучше
        // повторить площадку, чем не запостить вовсе.
        [$venueUse, $chainUse] = $this->venueUsageInFeed($broadcastId);
        if ($venueUse !== [] || $chainUse !== []) {
            $cap = $repeatCap ?? 1;
            $diverse = $pool->reject(function (Event $e) use ($venueUse, $chainUse, $cap) {
                if ($e->venue_id !== null && ($venueUse[(int) $e->venue_id] ?? 0) >= $cap) {
                    return true;
                }
                $chain = $this->venueChainKey((string) ($e->venue?->name ?? ''));

                return $chain !== '' && ($chainUse[$chain] ?? 0) >= $cap;
            });
            if ($diverse->isNotEmpty()) {
                $pool = $diverse;
            }
        }

        return $this->scorer->pickBest($pool)?->id;
    }

    /**
     * Сколько раз площадка и сеть уже заняты в ленте канала.
     *
     * Считаем по незакрытым записям и по недавно опубликованным: в пределах
     * одной недели повтор площадки виден так же, как повтор заголовка.
     *
     * Раньше это было множество «была/не была», и при одном посте в день так
     * и надо: доля площадки в неделе — одна седьмая. Но при двух слотах постов
     * четырнадцать, площадок в пуле около тридцати, и запрет на повтор вырезал
     * бы почти весь пул — а слой мягкий и молча берёт неотфильтрованное.
     * Поэтому теперь счётчик, и порог растёт вместе с плотностью.
     *
     * @return array{0: array<int, int>, 1: array<string, int>}
     */
    private function venueUsageInFeed(int $broadcastId): array
    {
        $rows = TelegramChatBroadcastItem::query()
            ->from('telegram.chat_broadcast_items as i')
            // Через связь — иначе площадки, названные подборкой, не считаются
            // занятыми вовсе. И СРАЗУ distinct по паре «пост + площадка»:
            // решение владельца 2026-09-15 — подборка даёт площадке ОДНУ
            // отметку, сколько бы её событий ни назвала. Иначе подборка из
            // пяти событий одного театра съедала бы недельную квоту этого
            // театра целиком, а обычный пост про него на неделе — один.
            ->distinct()
            ->join('telegram.chat_broadcast_item_events as l', 'l.item_id', '=', 'i.id')
            ->join('events as e', 'e.id', '=', 'l.event_id')
            ->leftJoin('venues as v', 'v.id', '=', 'e.venue_id')
            ->where('i.broadcast_id', $broadcastId)
            ->where(function ($q) {
                $q->whereIn('i.status', [
                    TelegramChatBroadcastItem::STATUS_PENDING,
                    TelegramChatBroadcastItem::STATUS_PLANNED,
                    TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                    TelegramChatBroadcastItem::STATUS_APPROVED,
                    TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
                ])->orWhere(function ($w) {
                    $w->where('i.status', TelegramChatBroadcastItem::STATUS_POSTED)
                        ->where('i.posted_at', '>=', now()->subDays(7));
                });
            })
            // i.id в выборке обязателен: без него distinct схлопнул бы две
            // РАЗНЫЕ записи с одной площадкой в одну строку, и квота перестала
            // бы считаться вовсе.
            ->get(['i.id as item_id', 'e.venue_id as venue_id', 'v.name as venue_name']);

        $ids = [];
        $chains = [];
        foreach ($rows as $row) {
            if ($row->venue_id !== null) {
                $id = (int) $row->venue_id;
                $ids[$id] = ($ids[$id] ?? 0) + 1;
            }
            $chain = $this->venueChainKey((string) ($row->venue_name ?? ''));
            if ($chain !== '') {
                $chains[$chain] = ($chains[$chain] ?? 0) + 1;
            }
        }

        return [$ids, $chains];
    }

    /**
     * Сколько раз одна площадка может попасть в неделю ленты.
     *
     * Держим прежнюю долю: не больше одного поста площадки на каждые семь
     * постов недели. Без слотов это единица — ровно нынешнее правило.
     */
    public function venueRepeatCap(TelegramChatBroadcast $broadcast): int
    {
        $postsPerWeek = min($broadcast->horizon_days, 7)
            * max(1, count($this->effectiveSlots($broadcast)));

        return max(1, intdiv($postsPerWeek, 7));
    }

    /**
     * Ключ сети площадок из названия.
     *
     * Отдельного поля владельца или сети в данных нет: у филиалов Quest
     * Brothers сообщество одно и то же — «Яндекс.Афиша», то есть источник, а
     * не хозяин. Единственная связь — имя: «Quest Brothers на Республиканской»,
     * «Quest brothers на Невского», «Quest Brothers на Московском».
     *
     * Отрезаем хвост по « на », но ТОЛЬКО если в остатке хотя бы два слова:
     * иначе «Театр на Таганке» схлопнулся бы в «театр» и утащил за собой все
     * театры города. Замер по базе: приём «X на Y» встречается у четырёх
     * площадок, у всех префикс из двух слов, и все четыре сходятся в одну
     * группу «quest brothers». Ложных склеек нет.
     */
    private function venueChainKey(string $name): string
    {
        $name = trim(mb_strtolower($name));
        if ($name === '') {
            return '';
        }

        $head = trim((string) preg_split('/\s+на\s+/u', $name, 2)[0]);
        $words = preg_split('/\s+/u', $head) ?: [];

        // Один-два символа или одно слово в остатке — не сеть, а просто имя.
        return count($words) >= 2 ? preg_replace('/\s+/u', ' ', $head) : $name;
    }

    /**
     * Нормализованные заголовки, которые в этом канале уже прозвучали или
     * вот-вот прозвучат: постнутые за окно $since..now ПЛЮС всё, что стоит
     * в ленте незакрытым.
     *
     * Незакрытые добавлены вместе с недельной лентой. Пока в очереди висела
     * одна запись, хватало и одних постнутых. Теперь неделя кладётся семью
     * записями разом, и друг для друга они были бы невидимы: два одинаковых
     * заголовка в одной неделе не остановил бы никто.
     *
     * @return string[]
     */
    private function recentlyPostedTitleNorms(int $broadcastId, Carbon $since): array
    {
        $titles = TelegramChatBroadcastItem::query()
            ->from('telegram.chat_broadcast_items as i')
            // Через связь — см. слой 2. На выходе уникальные нормализованные
            // заголовки, поэтому рост числа строк здесь безвреден.
            ->join('telegram.chat_broadcast_item_events as l', 'l.item_id', '=', 'i.id')
            ->join('events as e', 'e.id', '=', 'l.event_id')
            ->where('i.broadcast_id', $broadcastId)
            ->where(function ($q) use ($since) {
                $q->where(function ($w) use ($since) {
                    $w->where('i.status', TelegramChatBroadcastItem::STATUS_POSTED)
                        ->where('i.posted_at', '>=', $since);
                })->orWhereIn('i.status', [
                    TelegramChatBroadcastItem::STATUS_PENDING,
                    TelegramChatBroadcastItem::STATUS_PLANNED,
                    TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                    TelegramChatBroadcastItem::STATUS_APPROVED,
                    TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
                ]);
            })
            ->pluck('e.title');

        return $titles
            ->map(fn ($t) => $this->normalizeTitle($t))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Лёгкая нормализация заголовка для сравнения «тот же title» (lowercase, без
     * хэштегов/пунктуации, схлопнутые пробелы). НЕ обязана совпадать с парсерным
     * EventGroupKey — нужна лишь чтобы ловить повтор заголовка в одном канале.
     */
    private function normalizeTitle(?string $title): string
    {
        $s = mb_strtolower(trim((string) $title));
        $s = preg_replace('/#\S+/u', ' ', $s);
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', (string) $s);
        $s = preg_replace('/\s+/u', ' ', (string) $s);

        return trim((string) $s);
    }

    /**
     * Логика “пора ли запускать рассылку” для одного канала.
     *
     * Основывается на:
     *  - enabled
     *  - period (daily_10 / weekly_fri_12 / …)
     *  - last_run_at
     */
    /**
     * Картинки события — те же и в том же порядке, что видел бот.
     *
     * Грузим через тот же findWithDetails, которым отвечает бот-эндпоинт: он
     * зовёт hydrateImages, а тот подбирает обложку эвристикой CoverPicker и
     * кладёт её первой. Бот делал unique([poster] + images), а poster там
     * всегда images[0] — значит после дедупликации получался ровно images.
     * Берём первые три: столько же брал бот.
     *
     * Публичный, потому что тем же списком пользуется админка —
     * превью поста обязано показывать ровно то, что уйдёт в канал.
     *
     * @return list<string>
     */
    /**
     * Картинки события для поста.
     *
     * $limit = 3 — столько уходит в канал по умолчанию. Админке нужен полный
     * список кандидатов, чтобы человек мог не только выбросить дубль, но и
     * поставить вместо него четвёртую картинку.
     */
    public function eventPhotos(int $eventId, int $limit = 3): array
    {
        try {
            $event = $this->eventRepository->findWithDetails($eventId);
        } catch (\Throwable) {
            return [];
        }

        return self::pickPhotos($event->getAttribute('images'), $limit);
    }

    /**
     * Отбор картинок из уже загруженного списка события.
     *
     * Вынесено отдельно, чтобы админка могла загрузить картинки всей ленты
     * ОДНИМ запросом (hydrateImagesFor) и всё равно получить ровно тот набор,
     * который уйдёт в канал. Своя копия этой логики рано или поздно
     * разъехалась бы с оригиналом, и превью перестало бы совпадать с постом.
     */
    public static function pickPhotos(mixed $images, int $limit = 3): array
    {
        if (! is_array($images)) {
            return [];
        }

        $out = [];
        foreach ($images as $url) {
            $url = is_string($url) ? trim($url) : '';
            if ($url !== '' && ! in_array($url, $out, true)) {
                $out[] = $url;
            }
            if (count($out) === $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Досоздать текст поста, если его ещё нет.
     *
     * Раньше текста не существовало вовсе: боту уходил event_id и код шаблона,
     * а подпись собиралась уже в боте. Теперь она строится здесь и хранится,
     * иначе её нельзя ни отредактировать, ни показать в админке.
     *
     * Ручную правку не трогаем: caption_source = manual означает, что текст
     * писал человек, и пересобирать его из шаблона нельзя.
     */
    /**
     * @param  Event|null  $event  уже загруженное событие — чтобы не ходить в базу второй раз
     * @param  \Carbon\CarbonImmutable|null  $sendingAt  момент отправки: задан только на пути доставки
     */
    private function ensureEventCaption(
        TelegramChatBroadcastItem $item,
        TelegramChatBroadcast $broadcast,
        ?Event $event = null,
        ?\Carbon\CarbonImmutable $sendingAt = null,
    ): void {
        // Свой текст писал человек — ни собирать, ни пересобирать.
        if ($item->caption_source === TelegramChatBroadcastItem::CAPTION_MANUAL) {
            return;
        }

        // День, от которого считаются «сегодня» и «завтра». На постановке это
        // назначенный день поста, на отправке — день, когда пост реально
        // уходит: они расходятся, если запись пролежала в очереди.
        $showDay = $sendingAt
            ? $sendingAt->setTimezone(self::SCHEDULE_TZ)
            : $this->itemShowDay($item);

        $hasCaption = trim((string) $item->caption) !== '';

        // На пути доставки пересобираем ВСЕГДА. Между постановкой и отправкой
        // проходят дни, и за это время меняются обе половины текста: день, от
        // которого считаются «сегодня»/«завтра», и сам анонс — парсер пишет его
        // перед самой публикацией (parser:tg:describe-due). Раньше здесь стояла
        // проверка только на смену дня, и свежий анонс до поста не доезжал:
        // ровно поэтому в боте текст собирался в момент отправки.
        //
        // Пересборка бесплатная — подстановка в шаблон, без обращения к модели.
        // Вне пути доставки (постановка, пересборка недели) поведение прежнее:
        // готовый текст не трогаем, иначе админка не сможет показать превью.
        $stale = $sendingAt !== null && $hasCaption;

        if ($hasCaption && ! $stale) {
            return;
        }

        $event ??= Event::query()->find($item->event_id);
        if (! $event) {
            return;
        }

        try {
            $item->caption = $this->captionBuilder->build(
                $event,
                (string) $broadcast->template_code,
                $showDay,
            );
            $item->caption_source = TelegramChatBroadcastItem::CAPTION_TEMPLATE;
            $item->save();
        } catch (\Throwable $e) {
            // Не роняем выдачу задач: без текста бот соберёт его сам по
            // template_code, как делал раньше. Но знать об этом надо.
            Log::warning('caption.build_failed', [
                'item_id' => $item->id,
                'event_id' => $item->event_id,
                'template_code' => $broadcast->template_code,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Пометить айтем ошибкой при постоянном отказе отправки.
     *
     * Статус error существовал в модели с самого начала, но не ставился
     * НИКОГДА: за всё время ноль таких записей. Пост, который нельзя
     * отправить, молча повторялся каждые пять минут.
     */
    public function markItemFailed(int $itemId, string $reason): bool
    {
        $item = $this->broadcastItemRepository->findById($itemId);
        if (! $item || $item->posted_at !== null) {
            return false;
        }

        $this->broadcastItemRepository->markError($item, $reason);

        Log::error('broadcast.item_failed', [
            'item_id' => $itemId,
            'broadcast_id' => $item->broadcast_id,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Заполнить ленту канала по дням недели — для кнопки «Пересобрать неделю».
     *
     * Отличается от enqueueDueForAllChannels принципиально. Тот подчиняется
     * расписанию: добавляет пост, только если окно «пора», и за один вызов
     * ровно один. Для автопостинга это верно, а для кнопки — нет: человек
     * нажал её сейчас и ждёт, что неделя заполнится. Раньше кнопка дёргала
     * планировщик в цикле и часто добавляла ноль.
     *
     * Каждому дню сразу проставляем publish_at: от него считаются «сегодня» и
     * «завтра» в тексте, иначе пост про пятничный концерт, поставленный на
     * понедельник, скажет «завтра» про вторник.
     *
     * @return array{filled: int, days: int, no_candidate: int}
     */
    public function fillFeedDays(TelegramChatBroadcast $broadcast, Carbon $now): array
    {
        $chat = $broadcast->chat;
        $summary = ['filled' => 0, 'days' => 0, 'no_candidate' => 0];

        if (! $chat instanceof TelegramChat || ! $chat->city_id) {
            return $summary;
        }

        // ПЛАНИРОВАНИЕ — не публикация. Здесь стоял тот же запрет, что на
        // отправке, и на стенде «Пересобрать неделю» снимала всю ленту, а
        // заполнить не могла ничего: fillFeedDays выходила первой же строкой.
        // Запрет остаётся там, где он и нужен, — в выдаче задач боту
        // (collectDueSingleRuns) и в АВТОМАТИЧЕСКОМ наполнении, которое на
        // стенде копило записи молча. Нажатое человеком должно работать.

        $slots = $this->effectiveSlots($broadcast);
        $weekday = $this->periodWeekday($broadcast);

        // Занятые СЛОТЫ, а не дни: при двух слотах на один день встают два
        // поста, и считать занятость по дате больше нельзя.
        // Карта «слот → событие», а не просто список занятых ключей: сосед по
        // ленте нужен не только чтобы не занять его место, но и чтобы знать его
        // тему — два концерта подряд читаются как одна и та же новость.
        $taken = [];
        foreach (TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ])
            ->whereNotNull('publish_at')
            ->get(['publish_at', 'event_id']) as $row) {
            $taken[$this->slotKey(Carbon::parse($row->publish_at))] = $row->event_id !== null
                ? (int) $row->event_id
                : null;
        }

        // Тема последнего поста ПЕРЕД горизонтом: первый слот недели тоже чей-то
        // сосед, и без этого правило начинало действовать только со второго.
        $prevTheme = $this->primaryInterestId((int) (TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_EVENT)
            ->whereNotNull('event_id')
            ->where(function ($w) use ($now) {
                $w->where('posted_at', '<=', $now)
                    ->orWhere(function ($x) use ($now) {
                        $x->whereNotNull('publish_at')->where('publish_at', '<=', $now);
                    });
            })
            ->orderByRaw('COALESCE(posted_at, publish_at) DESC')
            ->value('event_id') ?? 0));

        // Записи, ждущие свободного дня, — ПЕРВЫМИ в освободившиеся слоты.
        // Иначе очередь ожидания не разбирается никогда: наполнитель каждый
        // раз берёт новое событие из пула, а в интерфейсе при этом написано
        // «дождитесь, пока день освободится».
        $waiting = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
            ])
            ->whereNull('publish_at')
            ->whereNull('posted_at')
            ->get()
            // Сначала события, по близости; портреты площадок — в хвост: у них
            // нет срока, и уступить слот событию для них не потеря.
            // В хвост — ВСЁ, у чего нет своего события: у такой записи нет и
            // срока, уступить слот событию для неё не потеря. Сравнение с одним
            // типом ставило подборку ПЕРВОЙ: Event::find(null) даёт null, а он
            // приводится к пустой строке — меньше любой даты.
            ->sortBy(fn (TelegramChatBroadcastItem $i) => $i->hasReadyCaption()
                ? '9999'
                : (string) optional(Event::query()->find($i->event_id))?->start_time)
            ->values()
            ->all();

        $exclude = [];
        for ($i = 0; $i < $broadcast->horizon_days; $i++) {
            $day = $now->copy()->setTimezone(self::SCHEDULE_TZ)->addDays($i)->startOfDay();

            if ($weekday !== null && $day->dayOfWeek !== $weekday) {
                continue;
            }
            $summary['days']++;

            foreach ($slots as $slotIndex => $hour) {
                $publishAt = $day->copy()->setTime($hour, 0, 0);

                // Слот, который уже прошёл, не заполняем: пост встал бы
                // просроченным и тут же потерял бы день.
                if ($publishAt->lt($now)) {
                    continue;
                }

                // Поздний слот дня решается не за две недели, а накануне —
                // если канал так настроен. Смысл в том, что половина афиши
                // объявляется поздно (медиана форы анонса — трое суток), и
                // слот, занятый заранее, закрыт для всего, что появится после.
                // Первый слот дня остаётся плановым: неделю надо видеть.
                $lead = $broadcast->fill_lead_days;
                if ($slotIndex > 0 && $lead !== null
                    && $publishAt->gt($now->copy()->addDays($lead))) {
                    continue;
                }
                $slotKey = $this->slotKey($publishAt);
                if (array_key_exists($slotKey, $taken)) {
                    // Сосед следующего слота — тот, кто стоит здесь.
                    $prevTheme = $this->primaryInterestId($taken[$slotKey]);

                    continue;
                }

                // Сначала пробуем закрыть слот тем, что уже ждёт дня.
                $fromWaiting = null;
                $sameDayKey = null;
                foreach ($waiting as $k => $candidate) {
                    // Портрет площадки сроком не связан: занимает слот как есть.
                    // Запись с готовым текстом занимает слот как есть — у неё
                    // нет события, по которому можно было бы проверять сроки.
                    // Сравнение с одним типом выбрасывало подборку из
                    // кандидатов, и publish_at у неё оставался пустым НАВСЕГДА:
                    // в базе запись есть, для планировщика её нет.
                    if ($candidate->hasReadyCaption()) {
                        $fromWaiting = $candidate;
                        $sameDayKey = null;
                        unset($waiting[$k]);
                        break;
                    }

                    $event = Event::query()->find($candidate->event_id);
                    if (! $event) {
                        unset($waiting[$k]);

                        continue;
                    }
                    // То же правило, что у подбора, и теперь буквально то же:
                    // общий [[PostTiming]]. Здесь оно раньше было своим и
                    // пускало пост до КОНЦА события — то есть анонс концерта
                    // мог уйти, когда он уже идёт.
                    if (! PostTiming::fits($event, $publishAt, self::MIN_LEAD_HOURS)) {
                        continue;
                    }

                    // Анонс в день события — крайний случай, а не норма: если
                    // событие ещё впереди, лучше поставить на этот слот то, до
                    // чего есть время, а «сегодняшнее» отдать более раннему.
                    $startsAt = $event->start_time ? Carbon::parse($event->start_time) : null;
                    $sameDay = $startsAt
                        && $startsAt->copy()->setTimezone(self::SCHEDULE_TZ)->toDateString()
                            === $publishAt->copy()->setTimezone(self::SCHEDULE_TZ)->toDateString();

                    if ($sameDay && $fromWaiting === null) {
                        $fromWaiting = $candidate;
                        $sameDayKey = $k;

                        continue;
                    }

                    $fromWaiting = $candidate;
                    $sameDayKey = null;
                    unset($waiting[$k]);
                    break;
                }

                // Никого, кроме «день в день», не нашлось — берём его.
                if ($fromWaiting !== null && isset($sameDayKey) && $sameDayKey !== null) {
                    unset($waiting[$sameDayKey]);
                }
                $sameDayKey = null;

                if ($fromWaiting !== null) {
                    $fromWaiting->publish_at = $publishAt->copy()->utc();
                    if ($fromWaiting->caption_source !== TelegramChatBroadcastItem::CAPTION_MANUAL) {
                        $fromWaiting->caption = null;
                        $fromWaiting->caption_source = null;
                    }
                    $fromWaiting->save();

                    if ($fromWaiting->hasReadyCaption()) {
                        // Текст портрета собирается своим сборщиком: события,
                        // от которого считается «сегодня», у него нет.
                        $venue = $fromWaiting->venue_id ? Venue::query()->find($fromWaiting->venue_id) : null;
                        if ($venue && $fromWaiting->caption_source !== TelegramChatBroadcastItem::CAPTION_MANUAL) {
                            $fromWaiting->caption = $this->venuePortraitService->buildVenueCaption($venue, $publishAt);
                            $fromWaiting->caption_source = TelegramChatBroadcastItem::CAPTION_TEMPLATE;
                            $fromWaiting->save();
                        }
                    } else {
                        $this->ensureEventCaption($fromWaiting, $broadcast);
                        // Через связь: у рубрики событий несколько, и литеральный
                        // ноль от пустой колонки в списке исключений бесполезен.
                        $exclude = array_merge($exclude, $this->linkedEventIds((int) $fromWaiting->id));
                    }

                    $prevTheme = $this->primaryInterestId($fromWaiting->event_id);
                    $summary['filled']++;

                    continue;
                }

                // Событие не должно начаться раньше публикации: день в день
                // можно, но не «пост в 10:00 про концерт в 08:00».
                //
                // И не та же тема, что у соседнего слота: два концерта подряд
                // читаются как одна новость, даже если события разные. Замер
                // 2026-09-16 по ленте боевого канала: шесть пар соседей с
                // одной темой из двадцати семи записей.
                $eventId = $this->pickBestEventIdForChat(
                    $chat,
                    $broadcast->id,
                    $exclude,
                    $publishAt->copy()->utc(),
                    $this->venueRepeatCap($broadcast),
                    $prevTheme,
                );

                // Правило мягкое: если из-за него слот остаётся пустым, лучше
                // повтор темы, чем дыра. Но молчать об этом нельзя — мягкий
                // слой, который при исчерпании тихо возвращает неотфильтрованный
                // пул, невозможно ни заметить, ни измерить.
                if (! $eventId && $prevTheme !== null) {
                    $eventId = $this->pickBestEventIdForChat(
                        $chat,
                        $broadcast->id,
                        $exclude,
                        $publishAt->copy()->utc(),
                        $this->venueRepeatCap($broadcast),
                    );

                    if ($eventId) {
                        Log::info('broadcast.feed.theme_repeat', [
                            'broadcast_id' => $broadcast->id,
                            'slot' => $publishAt->toIso8601String(),
                            'interest_id' => $prevTheme,
                            'why' => 'другой темы на этот слот не нашлось',
                        ]);
                    }
                }

                if (! $eventId) {
                    $summary['no_candidate']++;

                    continue;
                }
                $exclude[] = $eventId;
                $prevTheme = $this->primaryInterestId($eventId);

                // enqueue() возвращает СУЩЕСТВУЮЩУЮ запись, если событие когда-то
                // уже ставили: на (broadcast_id, event_id) стоит UNIQUE. Такая
                // запись приходит со старым статусом — обычно skipped после
                // прошлой пересборки. Если её не оживить, пост осядет невидимым,
                // а счётчик «заполнено» соврёт: ровно это и случилось на проверке.
                $item = $this->broadcastItemRepository->enqueue($broadcast->id, $eventId, null);
                $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
                $item->error_message = null;
                $item->claimed_at = null;
                $item->claim_token = null;
                $item->publish_at = $publishAt->copy()->utc();
                // Текст пересобираем: он зависит от дня публикации. Свой не трогаем.
                if ($item->caption_source !== TelegramChatBroadcastItem::CAPTION_MANUAL) {
                    $item->caption = null;
                    $item->caption_source = null;
                }
                $item->save();

                $this->ensureEventCaption($item, $broadcast);
                $summary['filled']++;
            }
        }

        return $summary;
    }

    /**
     * Первичная тема события — та же, по которой собирается подборка недели.
     *
     * Кэш на время прогона: наполнитель спрашивает тему у каждого соседа и у
     * каждого кандидата, а горизонт — две недели.
     *
     * У 4.8% событий первичных интересов несколько (замер 2026-09-15). Берём
     * наименьший id: нужна не «правильная» тема, а устойчивое сравнение
     * соседей между собой.
     *
     * @var array<int, int|null>
     */
    private array $primaryInterestCache = [];

    private function primaryInterestId(?int $eventId): ?int
    {
        if (! $eventId) {
            return null;
        }

        if (array_key_exists($eventId, $this->primaryInterestCache)) {
            return $this->primaryInterestCache[$eventId];
        }

        $id = DB::table('event_interest')
            ->where('event_id', $eventId)
            ->where('rank', 0)
            ->orderBy('interest_id')
            ->value('interest_id');

        return $this->primaryInterestCache[$eventId] = $id !== null ? (int) $id : null;
    }

    /**
     * Ключ слота: день и час по Москве.
     *
     * Один на все сравнения «занято ли это место в ленте». Раньше занятость
     * считалась по дате (->toDateString()) минимум в четырёх местах, и при
     * двух слотах в дне такое сравнение схлопнуло бы их в один.
     */
    private function slotKey(Carbon|\Carbon\CarbonInterface $at): string
    {
        return $this->slotPlanner->key($at);
    }

    /** Час публикации из расписания канала. */
    private function periodHour(TelegramChatBroadcast $broadcast): int
    {
        $period = trim((string) $broadcast->period);
        if (preg_match('/_(\d{1,2})$/', $period, $m)) {
            return max(0, min(23, (int) $m[1]));
        }

        return 10;
    }

    /** День недели у weekly-расписания; null у daily. */
    private function periodWeekday(TelegramChatBroadcast $broadcast): ?int
    {
        $map = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
        if (preg_match('/^weekly_([a-z]{3})_/', trim((string) $broadcast->period), $m)) {
            return $map[$m[1]] ?? null;
        }

        return null;
    }

    /**
     * День, когда пост увидят: из publish_at, если он задан, иначе сегодня.
     *
     * Нужен сборке текста: «сегодня» и «завтра» в посте, поставленном на
     * следующую пятницу, обязаны считаться от пятницы, а не от дня сборки.
     */
    private function itemShowDay(TelegramChatBroadcastItem $item): \Carbon\CarbonImmutable
    {
        $at = $item->publish_at ?? $item->planned_at;

        return $at
            ? \Carbon\CarbonImmutable::parse($at)->setTimezone('Europe/Moscow')
            : \Carbon\CarbonImmutable::now('Europe/Moscow');
    }

    /**
     * Сколько длится одно окно расписания канала, в часах.
     * null — период выключен или незнаком, простой считать не от чего.
     */
    public function periodWindowHours(TelegramChatBroadcast $broadcast): ?int
    {
        $period = trim((string) $broadcast->period);

        // Слоты делят окно: при двух постах в день нормальное молчание вдвое
        // короче, и порог «два пропущенных окна» на сутках начал бы молчать о
        // настоящей поломке — канал стоял бы полтора дня без единого сигнала.
        $slots = max(1, count($this->effectiveSlots($broadcast)));

        if (str_starts_with($period, 'daily_')) {
            return max(1, intdiv(24, $slots));
        }

        if (str_starts_with($period, 'weekly_')) {
            return max(1, intdiv(24 * 7, $slots));
        }

        return null;
    }

    /**
     * Задача «канал молчит» — или null, если молчания нет либо о нём уже писали.
     *
     * Порог — два пропущенных окна подряд: для дневного канала это 48 часов
     * тишины, и это уже однозначно поломка, а не выходной. Именно столько
     * не хватило в июле: рассылка встала на одной отравленной записи очереди,
     * в логи 1892 раза написалось event_load_failed, и никто их не читал —
     * простой заметили через 33 дня.
     *
     * Напоминаем раз в сутки: поллер тикает раз в минуту, и без этого владелец
     * получил бы 1440 сообщений в день вместо одного.
     */
    private function buildIdleNoticeTask(
        TelegramChatBroadcast $broadcast,
        TelegramChat $chat,
        Carbon $now,
    ): ?array {
        if (! $broadcast->enabled) {
            return null;
        }

        $windowHours = $this->periodWindowHours($broadcast);
        if ($windowHours === null) {
            return null;
        }

        $ownerTelegramId = $chat->owner?->telegram_id ?? null;
        if (! $ownerTelegramId) {
            return null;
        }

        /** @var Carbon|null $lastRun */
        $lastRun = $broadcast->last_run_at instanceof Carbon
            ? $broadcast->last_run_at
            : null;

        // Ни одного поста за всё время — считаем простой от создания канала,
        // иначе только что заведённый канал молчал бы «бесконечно долго».
        $since = $lastRun ?? ($broadcast->created_at instanceof Carbon ? $broadcast->created_at : null);
        if ($since === null) {
            return null;
        }

        // diffInHours отдаёт float — приводим явно, иначе PHP 8.4 ругается
        // на потерю точности при неявном приведении.
        $silentHours = (int) $since->diffInHours($now);
        if ($silentHours < $windowHours * 2) {
            return null;
        }

        /** @var Carbon|null $notifiedAt */
        $notifiedAt = $broadcast->idle_notified_at instanceof Carbon
            ? $broadcast->idle_notified_at
            : null;
        if ($notifiedAt !== null && (int) $notifiedAt->diffInHours($now) < 24) {
            return null;
        }

        $broadcast->idle_notified_at = $now;
        $broadcast->save();

        $where = $chat->username ? '@'.$chat->username : (string) $chat->telegram_chat_id;
        $days = intdiv($silentHours, 24);

        Log::warning('broadcast.idle_detected', [
            'broadcast_id' => $broadcast->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'silent_hours' => $silentHours,
            'period' => $broadcast->period,
        ]);

        return [
            'type' => 'notice',
            'kind' => 'idle',
            'broadcast_id' => (int) $broadcast->id,
            'telegram_chat_id' => (int) $chat->telegram_chat_id,
            'notify_telegram_id' => (int) $ownerTelegramId,
            'text' => sprintf(
                "⚠️ Канал %s молчит %s\n\nПоследний пост: %s. Расписание: %s. "
                .'Обычно это значит, что очередь встала — посмотри незакрытые записи '
                .'канала и логи bot-cron.',
                $where,
                $days > 0 ? $days.' дн.' : $silentHours.' ч.',
                $lastRun ? $lastRun->format('d.m.Y H:i') : 'ни одного',
                (string) $broadcast->period,
            ),
        ];
    }

    /**
     * Когда каналу снова можно постить, если зазор ещё не вышел.
     *
     * null — можно прямо сейчас. Считаем по max(posted_at) записей канала, а
     * НЕ по last_run_at: его двигает только событийная отправка, портрет
     * площадки намеренно не двигает, и расхождение на живых данных доходило
     * до четырёх часов.
     */
    public function nextPostAllowedAt(int $broadcastId, Carbon $now): ?Carbon
    {
        $gap = TelegramChatBroadcast::query()->find($broadcastId)?->min_gap_minutes ?? self::MIN_GAP_MINUTES;
        $allowedAt = $this->lastPostedAt($broadcastId)?->addMinutes($gap);

        return $allowedAt && $allowedAt->gt($now) ? $allowedAt : null;
    }

    /**
     * Часы публикации канала в порядке возрастания, по Москве.
     *
     * Слоты заданы — берём их. Не заданы — один слот из period: так канал без
     * слотов ведёт себя ровно как раньше.
     *
     * @return list<int>
     */
    public function effectiveSlots(TelegramChatBroadcast $broadcast): array
    {
        return $this->slotPlanner->slots($broadcast);
    }

    /** Когда канал постил в последний раз — любым видом записи. */
    private function lastPostedAt(int $broadcastId): ?Carbon
    {
        $lastPosted = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->whereNotNull('posted_at')
            ->max('posted_at');

        return $lastPosted ? Carbon::parse($lastPosted) : null;
    }

    private function isSingleRunDue(
        TelegramChatBroadcast $broadcast,
        Carbon $now,
    ): bool {
        if (! $broadcast->enabled) {
            return false;
        }

        $period = trim((string) $broadcast->period);
        if ($period === '' || $period === 'off') {
            return false;
        }

        /** @var Carbon|null $lastRun */
        $lastRun = $broadcast->last_run_at instanceof Carbon
            ? $broadcast->last_run_at
            : null;

        // daily_HH
        if (str_starts_with($period, 'daily_')) {
            $hour = (int) substr($period, 6) ?: 10;

            // Час расписания — московский, см. SCHEDULE_TZ. Сравнение Carbon
            // идёт по абсолютному моменту, поэтому разные пояса у $now и
            // $candidate корректны.
            $candidate = $now->copy()->setTimezone(self::SCHEDULE_TZ)->setTime($hour, 0, 0);

            // если сейчас ещё не HH:00 — берём вчерашнее окно
            if ($now->lt($candidate)) {
                $candidate->subDay();
            }

            // нужно отработать, если мы ещё ни разу не запускались после этого окна
            return ! $lastRun || $lastRun->lt($candidate);
        }

        // weekly_<dow>_<HH>  (пример: weekly_fri_12)
        if (str_starts_with($period, 'weekly_')) {
            $parts = explode('_', $period); // [weekly, fri, 12]
            $dowCode = $parts[1] ?? 'fri';
            $hour = isset($parts[2]) ? (int) $parts[2] : 12;

            $dowMap = [
                'mon' => Carbon::MONDAY,
                'tue' => Carbon::TUESDAY,
                'wed' => Carbon::WEDNESDAY,
                'thu' => Carbon::THURSDAY,
                'fri' => Carbon::FRIDAY,
                'sat' => Carbon::SATURDAY,
                'sun' => Carbon::SUNDAY,
            ];
            $targetDow = $dowMap[$dowCode] ?? Carbon::FRIDAY;

            // последнее «окно» не позже now; день недели и час — московские
            $candidate = $now->copy()->setTimezone(self::SCHEDULE_TZ)->setTime($hour, 0, 0);

            // отматываем назад до нужного дня недели и не позже now
            while ($candidate->dayOfWeek !== $targetDow || $candidate->gt($now)) {
                $candidate->subDay();
            }

            return ! $lastRun || $lastRun->lt($candidate);
        }

        // неизвестный period — игнорируем
        return false;
    }
}
