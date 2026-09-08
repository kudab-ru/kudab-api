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

    /**
     * Сколько дней не предлагать снова отклонённое или снятое.
     *
     * Раньше отказ действовал вечно, и пул тихо истощался. Тридцать дней —
     * достаточно, чтобы отказ не выглядел проигнорированным, и мало, чтобы
     * событие не пропало навсегда.
     */
    private const REJECTED_COOLDOWN_DAYS = 30;

    /** Окно cross-time анти-дубля: не повторять тот же заголовок в канале N дней. */
    private const CROSS_TIME_WINDOW_DAYS = 14;

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
        return $this->broadcastItemRepository->markPostedIfClaimed($itemId, $claimToken, $moment);
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
            ->whereDoesntHave('broadcastItems', function ($q) use ($broadcast, $usedStatuses) {
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

            // Активный (в полёте) элемент канала — pending/planned/pending_review/approved/auto_approved.
            $item = $this->broadcastItemRepository->findActiveForBroadcast($broadcast->id, $now);
            if (! $item) {
                continue;
            }

            // Придержка на время генерации текста: pending/planned репозиторий и так не
            // отдаёт, но ревью-статусы он отдаёт мимо planned_at — закрываем здесь.
            if ($item->planned_at !== null && $item->planned_at->isFuture()) {
                continue;
            }

            $isVenue = $item->kind === TelegramChatBroadcastItem::KIND_VENUE;
            $inReviewFlow = in_array($item->status, [
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ], true);

            // Событийный pending/planned постится строго в СВОЁ окно расписания
            // (isSingleRunDue). Портрет площадки (свой недельный каденс) и уже-в-полёте
            // ревью-статусы доставляем независимо от событийного расписания.
            if (! $isVenue && ! $inReviewFlow && ! $this->isSingleRunDue($broadcast, $now)) {
                continue;
            }

            $ownerTelegramId = $chat->owner?->telegram_id ?? null;

            $base = [
                'item_id' => (int) $item->id,
                'telegram_chat_id' => (int) $chat->telegram_chat_id,
            ];
            if ($isVenue) {
                // Портрет площадки: готовый текст + НЕСКОЛЬКО обложек-прокси (альбом).
                $photoUrls = $item->venue_id
                    ? $this->venuePortraitService->venuePhotoUrls((int) $item->venue_id, 4)
                    : [];
                if ($photoUrls === [] && $item->photo_url) {
                    $photoUrls = [(string) $item->photo_url];
                }
                $base += [
                    'kind' => 'venue',
                    'caption' => (string) $item->caption,
                    'photo_url' => $photoUrls[0] ?? $item->photo_url,
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
                if (! $item->event_id || ! Event::query()->whereKey($item->event_id)->exists()) {
                    $this->broadcastItemRepository->markSkipped(
                        $item,
                        'событие '.($item->event_id ?? '?').' недоступно (удалено) — снято из очереди',
                    );

                    continue;
                }

                $this->ensureEventCaption($item, $broadcast);
                $eventPhotos = $this->eventPhotos((int) $item->event_id);

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

            $eventId = $this->pickBestEventIdForChat($chat, $broadcast->id);
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
                // не успел — она истекает сама и пост уходит со старым description
                $item = $this->broadcastItemRepository->enqueue(
                    $broadcast->id,
                    $eventId,
                    $now->copy()->addMinutes($this->textGraceMinutes()),
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
            ->whereDoesntHave('broadcastItems', function ($q) use ($broadcastId, $usedStatuses) {
                $q->where('broadcast_id', $broadcastId)
                    ->whereIn('status', $usedStatuses);
            })
            ->whereDoesntHave('broadcastItems', function ($q) use ($broadcastId, $rejectedSince) {
                $q->where('broadcast_id', $broadcastId)
                    // ТОЛЬКО rejected. skipped — это «снято из очереди», а не
                    // «не предлагать»: так помечается и снятое руками, и
                    // вытесненное пересборкой, и запись под удалённым событием.
                    // Держать их в остывании значило бы прятать событие на
                    // месяц каждый раз, когда его просто убрали из ленты.
                    ->where('status', TelegramChatBroadcastItem::STATUS_REJECTED)
                    ->where('updated_at', '>=', $rejectedSince);
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
                            ->join('events as e2', 'e2.id', '=', 'i.event_id')
                            ->where('i.broadcast_id', $broadcastId)
                            ->whereIn('i.status', $usedStatuses)
                            ->whereColumn('e2.event_group_id', 'events.event_group_id');
                    });
            });

        if (! empty($excludeEventIds)) {
            $query->whereNotIn('id', array_values(array_unique(array_map('intval', $excludeEventIds))));
        }

        // Горизонт: не тащим всё будущее, но и не упираемся в первые дни.
        $query->where('start_time', '<=', Carbon::now()->addDays(self::CANDIDATE_HORIZON_DAYS));

        // Нижняя граница — момент публикации, если он известен. upcoming()
        // отсекает по «сейчас», а пост может уйти через неделю, и к тому дню
        // событие уже пройдёт.
        if ($notBefore !== null) {
            $query->where(function ($w) use ($notBefore) {
                $w->where('start_time', '>=', $notBefore)
                    ->orWhere(function ($x) use ($notBefore) {
                        $x->whereNotNull('end_time')->where('end_time', '>=', $notBefore);
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
        [$usedVenueIds, $usedChains] = $this->venuesAlreadyInFeed($broadcastId);
        if ($usedVenueIds !== [] || $usedChains !== []) {
            $diverse = $pool->reject(function (Event $e) use ($usedVenueIds, $usedChains) {
                if ($e->venue_id !== null && in_array((int) $e->venue_id, $usedVenueIds, true)) {
                    return true;
                }
                $chain = $this->venueChainKey((string) ($e->venue?->name ?? ''));

                return $chain !== '' && in_array($chain, $usedChains, true);
            });
            if ($diverse->isNotEmpty()) {
                $pool = $diverse;
            }
        }

        return $this->scorer->pickBest($pool)?->id;
    }

    /**
     * Площадки и сети, уже занятые в ленте канала.
     *
     * Считаем по незакрытым записям и по недавно опубликованным: в пределах
     * одной недели повтор площадки виден так же, как повтор заголовка.
     *
     * @return array{0: list<int>, 1: list<string>}
     */
    private function venuesAlreadyInFeed(int $broadcastId): array
    {
        $rows = TelegramChatBroadcastItem::query()
            ->from('telegram.chat_broadcast_items as i')
            ->join('events as e', 'e.id', '=', 'i.event_id')
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
            ->get(['e.venue_id as venue_id', 'v.name as venue_name']);

        $ids = [];
        $chains = [];
        foreach ($rows as $row) {
            if ($row->venue_id !== null) {
                $ids[] = (int) $row->venue_id;
            }
            $chain = $this->venueChainKey((string) ($row->venue_name ?? ''));
            if ($chain !== '') {
                $chains[] = $chain;
            }
        }

        return [array_values(array_unique($ids)), array_values(array_unique($chains))];
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
            ->join('events as e', 'e.id', '=', 'i.event_id')
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
    public function eventPhotos(int $eventId): array
    {
        try {
            $event = $this->eventRepository->findWithDetails($eventId);
        } catch (\Throwable) {
            return [];
        }

        $images = $event->getAttribute('images');
        if (! is_array($images)) {
            return [];
        }

        $out = [];
        foreach ($images as $url) {
            $url = is_string($url) ? trim($url) : '';
            if ($url !== '' && ! in_array($url, $out, true)) {
                $out[] = $url;
            }
            if (count($out) === 3) {
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
    private function ensureEventCaption(
        TelegramChatBroadcastItem $item,
        TelegramChatBroadcast $broadcast,
    ): void {
        if ($item->caption_source === TelegramChatBroadcastItem::CAPTION_MANUAL) {
            return;
        }
        if (trim((string) $item->caption) !== '') {
            return;
        }

        $event = Event::query()->find($item->event_id);
        if (! $event) {
            return;
        }

        try {
            $item->caption = $this->captionBuilder->build(
                $event,
                (string) $broadcast->template_code,
                // «Сегодня» и «завтра» считаем от дня, когда пост увидят.
                $this->itemShowDay($item),
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
        if (! BroadcastSafety::postingAllowed((int) $chat->telegram_chat_id)) {
            return $summary;
        }

        $hour = $this->periodHour($broadcast);
        $weekday = $this->periodWeekday($broadcast);

        // Дни, уже занятые в ленте: второй пост на тот же день не ставим.
        $taken = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ])
            ->whereNotNull('publish_at')
            ->pluck('publish_at')
            ->map(fn ($d) => Carbon::parse($d)->setTimezone('Europe/Moscow')->toDateString())
            ->all();

        $exclude = [];
        for ($i = 0; $i < $broadcast->feed_limit; $i++) {
            $day = $now->copy()->setTimezone('Europe/Moscow')->addDays($i)->startOfDay();

            if ($weekday !== null && $day->dayOfWeek !== $weekday) {
                continue;
            }
            $summary['days']++;
            if (in_array($day->toDateString(), $taken, true)) {
                continue;
            }

            // Событие не должно начаться раньше публикации: день в день
            // можно, но не «пост в 10:00 про концерт в 08:00».
            $eventId = $this->pickBestEventIdForChat(
                $chat,
                $broadcast->id,
                $exclude,
                $day->copy()->setTime($hour, 0)->utc(),
            );
            if (! $eventId) {
                $summary['no_candidate']++;

                continue;
            }
            $exclude[] = $eventId;

            $publishAt = $day->copy()->setTime($hour, 0, 0);

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

        return $summary;
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
    private function periodWindowHours(TelegramChatBroadcast $broadcast): ?int
    {
        $period = trim((string) $broadcast->period);

        if (str_starts_with($period, 'daily_')) {
            return 24;
        }

        if (str_starts_with($period, 'weekly_')) {
            return 24 * 7;
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

            $candidate = $now->copy()->setTime($hour, 0, 0);

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

            // последнее «окно» не позже now
            $candidate = $now->copy()->setTime($hour, 0, 0);

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
