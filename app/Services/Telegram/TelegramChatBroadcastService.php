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
     * Предохранитель поверх CANDIDATE_HORIZON_DAYS. Меньше числа событий города
     * на горизонт не ставить: конец недели выпадет из пула.
     */
    private const SCORING_CANDIDATE_LIMIT = 400;

    private const CANDIDATE_HORIZON_DAYS = 14;

    private const MIN_LEAD_HOURS = PostTiming::MIN_LEAD_HOURS;

    /**
     * Сколько дней не предлагать снова отклонённое (skipped не входит, см. pickBestEventIdForChat).
     * Тот же срок читают AdminBroadcastController и BroadcastDigestComposer.
     */
    public const REJECTED_COOLDOWN_DAYS = 30;

    /** Окно cross-time анти-дубля: не повторять тот же заголовок в канале N дней. */
    private const CROSS_TIME_WINDOW_DAYS = 14;

    /** Зазор между постами в минутах, если канал не найден; у канала свой min_gap_minutes. */
    private const MIN_GAP_MINUTES = 90;

    /**
     * Насколько просроченный пост ещё отправляем, в часах. Позже пост теряет день, а подборка
     * снимается (см. collectDueSingleRuns): иначе после паузы просроченные уходили бы подряд до ночи.
     */
    private const OVERDUE_CUTOFF_HOURS = 2;

    /**
     * Пояс расписания канала: daily_10 значит 10:00 по Москве. Приложение в UTC,
     * без явного приведения daily_10 открылся бы в 13:00 МСК.
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

    /**
     * Получить (или создать) настройки рассылки по telegram_id и telegram_chat_id.
     * Проверки прав — resolveManagedChat().
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

        // у события из подборки своей строки очереди нет, и enqueue() завёл бы второй пост
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
        ?int $messageId = null,
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
            // токен не совпал (lease истёк, запись забрал другой поллер) — last_run за чужой пост не двигаем
            $ok = $this->broadcastItemRepository->markPostedIfClaimed($item->id, $claimToken, $moment);
            if (! $ok) {
                return;
            }
        } else {
            // backward-compat (старый бот без токена) — прежнее поведение.
            $this->broadcastItemRepository->markPosted($item, $moment);
        }

        $this->rememberMessageId($item->id, $messageId);
        $this->broadcastRepository->touchLastRunAt($chat->id, $moment);
    }

    /**
     * Отметка по item_id, claim-guarded: портрет, подборка и восстановление в боте.
     * last_run_at не двигает, в отличие от markSingleEventSentForChat: по нему считается событийное окно.
     */
    public function markItemSentForChat(
        int $itemId,
        string $claimToken,
        ?DateTimeInterface $moment = null,
        ?int $messageId = null,
    ): bool {
        $ok = $this->broadcastItemRepository->markPostedIfClaimed($itemId, $claimToken, $moment);

        if ($ok) {
            $this->rememberMessageId($itemId, $messageId);
            $this->bookNextDigestAfter($itemId);
        }

        return $ok;
    }

    /**
     * Отдельным UPDATE, а не в отметке по claim-токену: номера может не быть,
     * и пост без него всё равно должен остаться отмеченным.
     */
    private function rememberMessageId(int $itemId, ?int $messageId): void
    {
        if ($messageId === null || $messageId <= 0) {
            return;
        }

        TelegramChatBroadcastItem::query()
            ->whereKey($itemId)
            ->update(['message_id' => $messageId]);
    }

    /**
     * Ушла подборка — сразу бронь на следующую, иначе до прогона почасовой команды
     * рубрика пропадает из плана недели. Ошибки только в лог: доставка уже состоялась.
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
     * @param  int  $telegramId  Telegram ID пользователя (из лички)
     * @param  int  $telegramChatId  telegram_chat_id канала/чата
     * @param  string  $mode  'preview' — дополнительно отметить предпросмотр у канала
     * @param  array  $excludeEventIds  Список event_id, которые нельзя предлагать
     */
    public function pickSingleEventId(
        int $telegramId,
        int $telegramChatId,
        string $mode = 'preview',
        array $excludeEventIds = [],
    ): ?int {
        $chat = $this->getChatByTelegram($telegramId, $telegramChatId);

        $broadcast = $this->broadcastRepository->getOrCreateByChatId($chat->id);

        $cityName = optional($chat->city)->name;

        $excludeEventIds = array_values(array_unique(array_map('intval', $excludeEventIds)));

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
     * Возвращает ['items' => Collection<TelegramChatBroadcastItem>, 'total' => int].
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
            return;
        }

        $this->broadcastItemRepository->markSkipped(
            $item,
            $reason ?: 'cancelled_by_user',
        );
    }

    /**
     * Задачи боту на этот тик (pull для bot-cron): publish, review, notice, subscribers.
     * pending_review с уже отправленным превью пропускается до решения или таймаута.
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

            // в базе стенда (дамп прода) настоящие telegram_chat_id; как разрешить свой канал — BroadcastSafety
            if (! BroadcastSafety::postingAllowed((int) $chat->telegram_chat_id)) {
                Log::warning('broadcast.poll.blocked_non_production', [
                    'broadcast_id' => $broadcast->id,
                    'telegram_chat_id' => $chat->telegram_chat_id,
                    'app_env' => config('app.env'),
                    'hint' => BroadcastSafety::HINT_LINES[1].' '.BroadcastSafety::ALLOW_KEY,
                ]);

                continue;
            }

            // до поиска активной записи: при простое её чаще всего нет, и ниже будет continue
            $idleNotice = $this->buildIdleNoticeTask($broadcast, $chat, $now);
            if ($idleNotice !== null) {
                $tasks[] = $idleNotice;
            }

            // тоже до поиска активной записи: подписчиков считаем и у канала без неё
            $countTask = $this->buildSubscriberCountTask($broadcast, $chat, $now);
            if ($countTask !== null) {
                $tasks[] = $countTask;
            }

            // зазор на весь канал, а не в гейтах по типу: иначе каждая новая рубрика его обходила бы
            $lastPostedAt = $this->lastPostedAt((int) $broadcast->id);
            $channelFreeAt = $lastPostedAt?->copy()->addMinutes($broadcast->min_gap_minutes);

            $item = $this->broadcastItemRepository->findActiveForBroadcast($broadcast->id, $now);
            if (! $item) {
                continue;
            }

            // превью на одобрение уходит в ЛС, зазор его не держит: у одобрения свой дедлайн
            $awaitsReviewPreview = $item->status === TelegramChatBroadcastItem::STATUS_PENDING_REVIEW;
            if (! $awaitsReviewPreview && $channelFreeAt && $channelFreeAt->gt($now)) {
                continue;
            }

            // просроченный теряет день, а не снимается: идущую многодневку подбор заново не возьмёт.
            // Опоздание от момента, когда канал освободился, иначе зазор сам загонял бы пост под отсечку
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

                // опоздавшая подборка устарела целиком: снимаем, следующую поставит бронь
                if ($item->kind === TelegramChatBroadcastItem::KIND_DIGEST) {
                    $this->broadcastItemRepository->markSkipped(
                        $item,
                        'подборка не вышла в свой слот — соберём следующую',
                    );

                    continue;
                }

                // событие без дня ждёт суточного окна расписания; портрету день назначаем заново,
                // иначе он уйдёт в это же окно и собьёт свой недельный каденс
                $item->publish_at = null;

                if ($item->kind === TelegramChatBroadcastItem::KIND_VENUE) {
                    $slot = $this->slotPlanner->nextFreeSlot($broadcast, $now);
                    $item->publish_at = $slot?->utc();

                    // свободного слота может не быть (лента занята, поздние закрыты fill_lead_days): держим час
                    // до наполнителя, иначе портрет без дня займёт суточное окно событийного поста
                    if ($slot === null) {
                        $item->planned_at = $now->copy()->addHour();

                        Log::info('broadcast.venue.no_free_slot_on_reschedule', [
                            'broadcast_id' => $broadcast->id,
                            'item_id' => $item->id,
                            'held_until' => $item->planned_at->toIso8601String(),
                            'why' => 'свободных слотов нет — портрет придержан до наполнителя',
                        ]);
                    }
                }

                $item->save();

                continue;
            }

            // Придержка на время генерации текста: pending/planned репозиторий и так не
            // отдаёт, но ревью-статусы он отдаёт мимо planned_at — закрываем здесь.
            if ($item->planned_at !== null && $item->planned_at->isFuture()) {
                continue;
            }

            // hasReadyCaption, а не kind === venue: подборку иначе сняли бы как «событие недоступно»
            $hasReadyCaption = $item->hasReadyCaption();
            $isVenue = $item->kind === TelegramChatBroadcastItem::KIND_VENUE;
            $inReviewFlow = in_array($item->status, [
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ], true);

            // запись с publish_at репозиторий отдаёт только при publish_at <= now, суточное окно к ней
            // не применяем: оно закрывается первым постом дня, и второй слот не открылся бы
            $hasOwnMoment = $item->publish_at !== null;
            // портрет по типу мимо гейта не пускать: без дня он уехал бы первым тиком в любой час
            if (! $inReviewFlow && ! $hasOwnMoment && ! $this->isSingleRunDue($broadcast, $now)) {
                continue;
            }

            $ownerTelegramId = $chat->owner?->telegram_id ?? null;

            $base = [
                'item_id' => (int) $item->id,
                'telegram_chat_id' => (int) $chat->telegram_chat_id,
            ];
            if ($hasReadyCaption) {
                // подпись подборки пересобираем даже готовую: состав заморожен заранее,
                // а шапка с датами, цены и время должны быть свежими
                if ($item->kind === TelegramChatBroadcastItem::KIND_DIGEST
                    && $item->caption_source !== TelegramChatBroadcastItem::CAPTION_MANUAL) {
                    if (! $this->prepareDigest($item, $broadcast, $now)) {
                        continue;
                    }
                }

                // портрет тоже: «ближайшее тут» за неделю в очереди устаревает, а переписанный
                // в админке текст площадки без пересборки не доедет
                if ($item->kind === TelegramChatBroadcastItem::KIND_VENUE) {
                    $this->ensureVenueCaption($item, $now);
                }

                // photo_urls: null — собрать автоматически, [] — выбрано «без картинок»
                $manualPhotos = is_array($item->photo_urls);
                if ($manualPhotos) {
                    $photoUrls = array_values(array_filter($item->photo_urls, 'is_string'));
                } else {
                    $photoUrls = match (true) {
                        $item->kind === TelegramChatBroadcastItem::KIND_DIGEST => $this->digestPhotoUrls((int) $item->id, TelegramVenuePortraitService::ALBUM_LIMIT),
                        $item->venue_id !== null => $this->venuePortraitService->venuePhotoUrls(
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
                    // бот выбирает ветку по kind (READY_CAPTION_KINDS), тип отдаём как есть
                    'kind' => (string) $item->kind,
                    'caption' => (string) $item->caption,
                    // при ручном наборе без фолбэка на photo_url: [] значит «без картинок»
                    'photo_url' => $manualPhotos ? ($photoUrls[0] ?? null) : ($photoUrls[0] ?? $item->photo_url),
                    'photo_urls' => $photoUrls,
                ];
            } else {
                // софт-удаление строку очереди не снимает, снимаем здесь, до выдачи боту
                $event = $item->event_id ? Event::query()->find($item->event_id) : null;
                if (! $event) {
                    $this->broadcastItemRepository->markSkipped(
                        $item,
                        'событие '.($item->event_id ?? '?').' недоступно (удалено) — снято из очереди',
                    );

                    continue;
                }

                // пока пост лежал в очереди, событие могло начаться; правило то же, что в админке (PostTiming)
                if (! PostTiming::fits($event, $now)) {
                    $this->broadcastItemRepository->markSkipped(
                        $item,
                        'событие '.$event->id.' уже началось к моменту отправки — снято из очереди',
                    );
                    Log::warning('broadcast.poll.skipped_event_started', [
                        'broadcast_id' => $broadcast->id,
                        'item_id' => $item->id,
                        'event_id' => $event->id,
                        'deadline' => optional(PostTiming::deadline($event))->toIso8601String(),
                    ]);

                    continue;
                }

                // На отправке день известен точно — пересобираем под него
                // (почему всегда, а не при смене дня, — в ensureEventCaption).
                $this->ensureEventCaption($item, $broadcast, $event, \Carbon\CarbonImmutable::parse($now));
                // Ручной выбор сильнее автоподбора (как в ветке готового текста выше).
                $eventPhotos = is_array($item->photo_urls)
                    ? array_values(array_filter($item->photo_urls, 'is_string'))
                    : $this->eventPhotos((int) $item->event_id);

                $base += [
                    'kind' => 'event',
                    'event_id' => (int) $item->event_id,
                    'template_code' => (string) $broadcast->template_code,
                    // пустой caption — бот соберёт текст сам по template_code (см. ensureEventCaption)
                    'caption' => (string) ($item->caption ?? ''),
                    // Картинки той же формой, что у портретов площадок. Без
                    // них бот ходил бы за событием только ради обложки.
                    'photo_url' => $eventPhotos[0] ?? null,
                    'photo_urls' => $eventPhotos,
                ];
            }

            if ($item->status === TelegramChatBroadcastItem::STATUS_PENDING_REVIEW) {
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
                if (! $ownerTelegramId) {
                    // без владельца канал не публикует ничего; в админке это показывает channelProblems
                    Log::warning('broadcast.poll.skipped_no_owner', [
                        'broadcast_id' => $broadcast->id,
                        'telegram_chat_id' => $chat->telegram_chat_id,
                        'item_id' => $item->id,
                        'hint' => 'у чата не задан telegram_user_id — владелец канала',
                    ]);

                    continue;
                }
                // claim до выдачи: параллельный поллер или повторный poll после краша не запостит дважды
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
     * Автонаполнение каналов без слотов: одно событие, когда канал due и открытых
     * событийных записей меньше feed_limit. last_run_at двигает только сам пост.
     * Каналы со слотами ведёт fillFeedDays.
     *
     * @return array{checked:int,due:int,enqueued:int,skipped_slots:int,skipped_no_city:int,skipped_queue_busy:int,no_candidate:int,skipped_no_reviewer:int}
     */
    public function enqueueDueForAllChannels(Carbon $now, bool $dryRun = false): array
    {
        $summary = [
            'checked' => 0,
            'due' => 0,
            'enqueued' => 0,
            'skipped_slots' => 0,
            'skipped_no_city' => 0,
            'skipped_queue_busy' => 0,
            'no_candidate' => 0,
            'skipped_no_reviewer' => 0,
            'skipped_not_allowed' => 0,
        ];

        $broadcasts = $this->broadcastRepository->listEnabledWithSchedule();

        foreach ($broadcasts as $broadcast) {
            $summary['checked']++;

            // кап feed_limit каналы со слотами не отсечёт (считает только события), а запись
            // отсюда без дня ушла бы лишним постом посреди дня
            if ($broadcast->slots !== []) {
                $summary['skipped_slots']++;

                continue;
            }

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

            // на стенде очередь боевого канала не наполняем: посты копились бы и не ушли никогда
            if (! BroadcastSafety::postingAllowed((int) $chat->telegram_chat_id)) {
                $summary['skipped_not_allowed']++;

                continue;
            }

            // только событийные записи: у портретов и подборок свой каденс, общий счётчик
            // закрыл бы их навсегда, стоит ленте заполниться
            $openEvents = $this->broadcastItemRepository->countOpenForBroadcast($broadcast->id, 'event');
            if ($openEvents >= $broadcast->feed_limit) {
                $summary['skipped_queue_busy']++;

                continue;
            }

            $eventId = $this->pickBestEventIdForChat(
                $chat,
                $broadcast->id,
                // без notBefore подбор не проверяет срок, и upcoming() пустит начавшееся час назад
                notBefore: $now,
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
                // придержка на время текста: снимает её парсер (parser:tg:describe-due), не успеет — истечёт сама.
                // Без ai_text ждать нечего
                $item = $this->broadcastItemRepository->enqueue(
                    $broadcast->id,
                    $eventId,
                    $broadcast->ai_text ? $now->copy()->addMinutes($this->textGraceMinutes()) : null,
                );

                // текст сразу, чтобы админке было что показать и править до отправки
                $this->ensureEventCaption($item, $broadcast);
            }

            $summary['enqueued']++;
        }

        return $summary;
    }

    /**
     * Занято ли событие другим постом канала. Тот же запрос в AdminBroadcastController::eventTakenByAnotherPost,
     * правятся вместе. По связи: подборка называет несколько событий, своей строки под каждое не заводит.
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
     * Собрать подборку заранее (prepare_hours до слота). Замораживается состав,
     * подпись всё равно пересоберётся перед отправкой.
     *
     * @return string что случилось: composed | text_requested | ready | failed
     */
    public function prepareDigestAhead(
        TelegramChatBroadcastItem $item,
        TelegramChatBroadcast $broadcast,
        Carbon $now,
    ): string {
        // состав на момент слота, а не прогона: от него окно недели, срок событий и даты в шапке
        $at = $item->publish_at ? Carbon::parse($item->publish_at) : $now;

        if ($this->digestRosterSize($item) > 0 && $item->digestTheme() !== null) {
            // текст просим один раз (text_asked): прогон почасовой, и неудачная генерация
            // иначе переспрашивалась бы каждый час, за деньги
            $meta = (array) ($item->digest_meta ?? []);

            if ($this->digestWantsText($item, $broadcast)
                && $item->text_requested_at === null
                && empty($meta['text_asked'])) {
                $meta['text_asked'] = true;
                $item->digest_meta = $meta;
                $item->save();

                $this->requestDigestText($item, $at);

                return 'text_requested';
            }

            return 'ready';
        }

        if (! $this->composeDigest($item, $broadcast, $at)) {
            return 'failed';
        }

        if ($this->digestWantsText($item, $broadcast)) {
            $meta = (array) ($item->digest_meta ?? []);
            $meta['text_asked'] = true;
            $item->digest_meta = $meta;
            $item->save();

            $this->requestDigestText($item, $at);

            return 'text_requested';
        }

        return 'composed';
    }

    /**
     * Довести подборку до отправки в момент слота. Нет состава — выбрать и попросить текст;
     * есть — только recompose: заново выбранный состав был бы другим, и текст достался бы не тем.
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
            // состав рассыпался (события удалили или начались): выбираем заново и просим новый текст
            if (! $this->composeDigest($item, $broadcast, $now)) {
                return false;
            }

            if ($this->digestWantsText($item, $broadcast)) {
                $this->requestDigestText($item, $now);

                return false;
            }

            return true;
        }

        $this->applyDigestDraft($item, $draft);

        Log::info('broadcast.digest.recomposed', [
            'item_id' => $item->id,
            'theme' => $draft['theme_slug'],
            'named' => count($draft['event_ids']),
            'with_text' => $item->hasDigestText(),
        ]);

        // applyDigestDraft мог снять устаревший текст (состав правили руками): просим новый
        // один раз, по тем же отметкам, что prepareDigestAhead; не успеет — уйдёт из фактов
        $meta = (array) ($item->digest_meta ?? []);
        if ($this->digestWantsText($item, $broadcast)
            && $item->text_requested_at === null
            && empty($meta['text_asked'])) {
            $meta['text_asked'] = true;
            $item->digest_meta = $meta;
            $item->save();

            $this->requestDigestText($item, $now);

            return false;
        }

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
     * Стоит ли заказать модели текст подборки; без ai_text подборка собирается из фактов.
     * Копия в AdminBroadcastController::digestNeedsText, правятся вместе.
     */
    private function digestWantsText(TelegramChatBroadcastItem $item, TelegramChatBroadcast $broadcast): bool
    {
        return $broadcast->ai_text
            && $item->caption_source !== TelegramChatBroadcastItem::CAPTION_MANUAL
            // не hasDigestText: после замены события строки прежних остаются. И не фраза каждому
            // событию: гейт парсера фразу может выбросить, и текст заказывался бы по кругу
            && ! $item->digestTextCoversRoster($this->digestRosterIds($item));
    }

    /**
     * Нынешний состав подборки. Удалённые вычитаем, как namedFromLinks в композиторе;
     * начавшиеся нет, иначе проверка покрытия менялась бы от минуты вызова.
     *
     * @return list<int>
     */
    private function digestRosterIds(TelegramChatBroadcastItem $item): array
    {
        return DB::table('telegram.chat_broadcast_item_events as l')
            ->join('events as e', 'e.id', '=', 'l.event_id')
            ->where('l.item_id', $item->id)
            ->whereNull('e.deleted_at')
            ->orderBy('l.position')
            ->pluck('l.event_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

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

    /**
     * Наполнить бронь подборки. false — наполнять нечем, запись снята, и доставка
     * идёт дальше: пустую подборку бот не отправит, а пометит ошибкой.
     */
    private function composeDigest(
        TelegramChatBroadcastItem $item,
        TelegramChatBroadcast $broadcast,
        Carbon $now,
    ): bool {
        // Рубрику мог задать слот расписания. Не набралась — пробуем как
        // обычно: пустой пост хуже не той рубрики.
        $wanted = trim((string) (($item->digest_meta ?? [])['theme_wanted'] ?? '')) ?: null;

        $draft = $wanted !== null
            ? $this->digestComposer->compose($broadcast, $now, $item, $wanted)
            : null;

        $draft ??= $this->digestComposer->compose($broadcast, $now, $item);

        if ($draft === null) {
            // следующую бронь поставит broadcast:enqueue-digests
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
     * Заменить одно событие в составе, не переизбирая остальные. Одной правки строки связи мало:
     * подпись собирается из состава целиком, поэтому следом recompose.
     *
     * @return bool удалось ли; false означает, что новый состав не собрался и
     *              вызывающий обязан откатить транзакцию
     */
    public function replaceDigestEvent(
        TelegramChatBroadcastItem $item,
        TelegramChatBroadcast $broadcast,
        int $outEventId,
        int $inEventId,
        Carbon $publishAt,
    ): bool {
        DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)
            ->where('event_id', $outEventId)
            ->delete();

        $tail = (int) DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)
            ->max('position');

        DB::table('telegram.chat_broadcast_item_events')->insertOrIgnore([
            'item_id' => $item->id,
            'event_id' => $inEventId,
            'position' => $tail + 1,
            'created_at' => now(),
        ]);

        return $this->recomposeRoster($item, $broadcast, $publishAt, [
            'op' => 'replace', 'out' => $outEventId, 'in' => $inEventId,
        ]);
    }

    /**
     * Добавить строку в хвост состава. Правила автоотбора (день, площадка) здесь
     * не действуют: состав, выбранный руками, и есть решение.
     */
    public function addDigestEvent(
        TelegramChatBroadcastItem $item,
        TelegramChatBroadcast $broadcast,
        int $inEventId,
        Carbon $publishAt,
    ): bool {
        $tail = (int) DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)
            ->max('position');

        DB::table('telegram.chat_broadcast_item_events')->insertOrIgnore([
            'item_id' => $item->id,
            'event_id' => $inEventId,
            'position' => $tail + 1,
            'created_at' => now(),
        ]);

        return $this->recomposeRoster($item, $broadcast, $publishAt, [
            'op' => 'add', 'in' => $inEventId,
        ]);
    }

    /** Убрать строку из состава. */
    public function removeDigestEvent(
        TelegramChatBroadcastItem $item,
        TelegramChatBroadcast $broadcast,
        int $outEventId,
        Carbon $publishAt,
    ): bool {
        DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)
            ->where('event_id', $outEventId)
            ->delete();

        return $this->recomposeRoster($item, $broadcast, $publishAt, [
            'op' => 'remove', 'out' => $outEventId,
        ]);
    }

    /**
     * Переставить состав целиком: по одной строке с двумя запросами при обрыве
     * остаются дырки в нумерации, а по ней читают подпись и обложки.
     *
     * @param  list<int>  $eventIds  весь состав в новом порядке
     */
    public function reorderDigestEvents(
        TelegramChatBroadcastItem $item,
        TelegramChatBroadcast $broadcast,
        array $eventIds,
        Carbon $publishAt,
    ): bool {
        foreach (array_values($eventIds) as $i => $eventId) {
            DB::table('telegram.chat_broadcast_item_events')
                ->where('item_id', $item->id)
                ->where('event_id', $eventId)
                ->update(['position' => $i + 1]);
        }

        return $this->recomposeRoster($item, $broadcast, $publishAt, [
            'op' => 'reorder', 'order' => $eventIds,
        ]);
    }

    /**
     * Пересобрать подпись под изменившийся состав. false — состав не собрался,
     * вызывающий откатывает транзакцию.
     *
     * @param  array<string, mixed>  $log
     */
    private function recomposeRoster(
        TelegramChatBroadcastItem $item,
        TelegramChatBroadcast $broadcast,
        Carbon $publishAt,
        array $log,
    ): bool {
        $draft = $this->digestComposer->recompose($item->refresh(), $broadcast, $publishAt);
        if ($draft === null) {
            return false;
        }

        $this->applyDigestDraft($item, $draft);

        Log::info('broadcast.digest.roster_changed', $log + [
            'item_id' => $item->id,
            'named' => count($draft['event_ids']),
        ]);

        return true;
    }

    /**
     * Записать собранное композитором: подпись и состав. Публичный: админка собирает
     * подборку заранее, чтобы человек увидел и поправил текст.
     *
     * @param  array{caption: string, event_ids: list<int>}  $draft
     */
    public function applyDigestDraft(TelegramChatBroadcastItem $item, array $draft): void
    {
        $theme = (string) ($draft['theme_slug'] ?? ($draft['theme']['slug'] ?? ''));
        $meta = (array) ($item->digest_meta ?? []);

        // подводка написана про конкретный состав и тему: сменились — текст про прежний снимаем
        $roster = array_values(array_map('intval', (array) ($draft['event_ids'] ?? [])));
        $themeChanged = $theme !== '' && ($meta['theme'] ?? null) !== $theme;

        if ($item->hasDigestText() && ($themeChanged || ! $item->digestTextCoversRoster($roster))) {
            $meta = self::withoutStaleDigestText($meta, $roster);

            // заявку открываем заново (text_asked снят в withoutStaleDigestText), иначе текст не закажут
            $item->text_requested_at = null;

            Log::info('broadcast.digest.text_invalidated', [
                'item_id' => $item->id,
                'theme_changed' => $themeChanged,
                'roster' => $roster,
            ]);
        }

        if ($theme !== '') {
            $meta['theme'] = $theme;
            // имя темы для парсера: реестр тем лежит в конфиге api
            $meta['theme_title'] = (string) ($draft['theme']['title'] ?? $theme);

            // сколько знаков осталось на фразу под этот состав; читает парсер, у него порог
            // один на все рубрики, а бюджет зависит от числа строк
            if (isset($draft['hook_budget'])) {
                $meta['hook_budget'] = (int) $draft['hook_budget'];
            }
        }

        $item->caption = $draft['caption'];
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_TEMPLATE;
        $item->digest_meta = $meta === [] ? null : $meta;
        $item->save();

        $this->syncDigestEvents($item, $draft['event_ids']);
    }

    /**
     * Мета без текста под прежний состав. Строки оставшихся событий сохраняем:
     * они про своё событие, а не про соседей.
     *
     * @param  array<string, mixed>  $meta
     * @param  list<int>  $roster
     * @return array<string, mixed>
     */
    private static function withoutStaleDigestText(array $meta, array $roster): array
    {
        unset($meta['intro'], $meta['roster'], $meta['text_asked']);

        $keep = array_flip(array_map('strval', $roster));
        $hooks = array_intersect_key((array) ($meta['hooks'] ?? []), $keep);

        if ($hooks === []) {
            unset($meta['hooks']);
        } else {
            $meta['hooks'] = $hooks;
        }

        return $meta;
    }

    /**
     * Записать состав в связь «пост → события». Позиции с единицы: ноль у ведущего
     * события обычного поста, на нём частичный уникальный индекс.
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
     * Обложки событий подборки, по одной на событие, в порядке состава. Один источник
     * для доставки и админки, чтобы превью совпадало с постом.
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
     * События записи по связи: у обычного поста одно, у подборки все названные.
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

    /** Превью ревью ушло в ЛС: message_id запоминаем, чтобы на следующем тике не слать повторно. */
    public function markReviewPreviewSent(int $itemId, int $messageId): void
    {
        $item = $this->broadcastItemRepository->findById($itemId);
        if (! $item) {
            throw new RuntimeException('Элемент очереди не найден.');
        }

        $this->broadcastItemRepository->setReviewMessageId($item, $messageId);
    }

    /**
     * Решение ревьюера по pending_review, идемпотентно: уже решённое — no-op.
     * Решать может только адресат превью (review_reviewer_telegram_id).
     */
    public function decideReview(int $telegramId, int $itemId, bool $approve): void
    {
        $item = $this->broadcastItemRepository->findById($itemId);
        if (! $item) {
            throw new RuntimeException('Элемент очереди не найден.');
        }

        if ($item->status !== TelegramChatBroadcastItem::STATUS_PENDING_REVIEW) {
            return;
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

    public function autoApproveExpiredReviews(Carbon $now): int
    {
        return $this->broadcastItemRepository->autoApproveExpiredReviews($now);
    }

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

    /** TelegramChat с проверкой прав — обёртка над resolveManagedChat. */
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
     * без user-контекста.
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

        // отклонённое — только на REJECTED_COOLDOWN_DAYS, иначе каждый отказ навсегда отъедал бы пул
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
                    // только rejected: skipped значит «снято из ленты» (руками, пересборкой), а не отказ,
                    // и прятать такое на месяц нельзя
                    ->where('status', TelegramChatBroadcastItem::STATUS_REJECTED)
                    // полным именем: в подзапросе ещё таблица связи
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
                            // через связь: у подборки событий несколько, и по колонке записи
                            // их группы не считались бы занятыми
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

        // тема соседнего слота, только по первичному интересу (rank = 0): вторичных у события
        // пачка, и «та же тема» совпала бы почти у всех
        if ($avoidInterestId !== null) {
            $query->whereNotExists(function ($q) use ($avoidInterestId) {
                $q->selectRaw('1')
                    ->from('event_interest as ei_theme')
                    ->whereColumn('ei_theme.event_id', 'events.id')
                    ->where('ei_theme.rank', 0)
                    ->where('ei_theme.interest_id', $avoidInterestId);
            });
        }

        // горизонт от дня слота: от «сейчас» окно у дальних слотов схлопывается
        $query->where(
            'start_time',
            '<=',
            ($notBefore ?? Carbon::now())->copy()->addDays(self::CANDIDATE_HORIZON_DAYS),
        );

        // нижняя граница от момента публикации, а не от «сейчас» (upcoming): к дню поста событие
        // может пройти. Многодневке фора считается до закрытия, см. PostTiming
        if ($notBefore !== null) {
            PostTiming::applyFits($query, $notBefore, self::MIN_LEAD_HOURS);
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

        // Layer 3: не повторять заголовок за окно (один title в разные даты — разные группы).
        // Мягкий: если свежих нет, берём из общего пула
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

        // Layer 4: не больше repeatCap постов на площадку и сеть, повтор заголовков этого не ловит.
        // Мягкий, как слой выше
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
     * Сколько раз площадка и сеть уже заняты в ленте: незакрытые записи и вышедшие за неделю.
     * Счётчик, а не множество: порог растёт с числом слотов (venueRepeatCap).
     *
     * @return array{0: array<int, int>, 1: array<string, int>}
     */
    private function venueUsageInFeed(int $broadcastId): array
    {
        $rows = TelegramChatBroadcastItem::query()
            ->from('telegram.chat_broadcast_items as i')
            // через таблицу связи, чтобы считались и площадки из подборки;
            // distinct: подборка даёт площадке одну отметку, сколько бы её событий ни назвала
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
            // i.id в выборке обязателен: без него distinct схлопнул бы разные записи одной площадки
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

    /** Сколько раз одна площадка может попасть в неделю ленты: один пост на каждые семь. */
    public function venueRepeatCap(TelegramChatBroadcast $broadcast): int
    {
        $postsPerWeek = min($broadcast->horizon_days, 7)
            * max(1, count($this->effectiveSlots($broadcast)));

        return max(1, intdiv($postsPerWeek, 7));
    }

    /**
     * Ключ сети площадок из названия, поля сети в данных нет: «Quest Brothers на Невского».
     * Хвост по « на » режем, только если в остатке от двух слов: иначе «Театр на Таганке» стал бы «театр».
     * Копия в AdminBroadcastController::chainKey, правятся вместе.
     */
    private function venueChainKey(string $name): string
    {
        $name = trim(mb_strtolower($name));
        if ($name === '') {
            return '';
        }

        $head = trim((string) preg_split('/\s+на\s+/u', $name, 2)[0]);
        $words = preg_split('/\s+/u', $head) ?: [];

        return count($words) >= 2 ? preg_replace('/\s+/u', ' ', $head) : $name;
    }

    /**
     * Нормализованные заголовки канала: вышедшие с $since и всё незакрытое в ленте,
     * иначе посты одной недели не видели бы друг друга.
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

    /** Для сравнения «тот же title» в канале; с парсерным EventGroupKey совпадать не обязана. */
    private function normalizeTitle(?string $title): string
    {
        $s = mb_strtolower(trim((string) $title));
        $s = preg_replace('/#\S+/u', ' ', $s);
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', (string) $s);
        $s = preg_replace('/\s+/u', ' ', (string) $s);

        return trim((string) $s);
    }

    /**
     * Картинки события в том же порядке, что отдаёт боту findWithDetails (обложка первой).
     * Админка берёт с большим $limit, чтобы человек мог заменить картинку.
     *
     * @return list<string>
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
     * Отбор из уже загруженного списка: админка грузит картинки ленты одним запросом
     * и получает тот же набор, что уйдёт в канал. Своей копии не заводить.
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
     * Подпись портрета заново, из свежего текста площадки. Площадка без текста оставляет
     * прежнюю подпись: пустую бот не отправит, а пометит ошибкой.
     */
    private function ensureVenueCaption(TelegramChatBroadcastItem $item, Carbon $now): void
    {
        if ($item->caption_source === TelegramChatBroadcastItem::CAPTION_MANUAL) {
            return;
        }

        $venue = $item->venue_id ? Venue::query()->find($item->venue_id) : null;
        if (! $venue || trim((string) $venue->tg_portrait) === '') {
            return;
        }

        $item->caption = $this->venuePortraitService->buildVenueCaption(
            $venue,
            $item->publish_at ? Carbon::parse($item->publish_at) : $now,
            (int) $item->id,
        );
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_TEMPLATE;
        $item->save();
    }

    /**
     * Текст поста из шаблона: досоздать, если его нет, а на пути доставки пересобрать.
     *
     * @param  Event|null  $event  уже загруженное событие, чтобы не ходить в базу второй раз
     * @param  \Carbon\CarbonImmutable|null  $sendingAt  момент отправки: задан только на пути доставки
     */
    private function ensureEventCaption(
        TelegramChatBroadcastItem $item,
        TelegramChatBroadcast $broadcast,
        ?Event $event = null,
        ?\Carbon\CarbonImmutable $sendingAt = null,
    ): void {
        if ($item->caption_source === TelegramChatBroadcastItem::CAPTION_MANUAL) {
            return;
        }

        // день для «сегодня»/«завтра»: на постановке назначенный, на отправке фактический
        $showDay = $sendingAt
            ? $sendingAt->setTimezone(self::SCHEDULE_TZ)
            : $this->itemShowDay($item);

        $hasCaption = trim((string) $item->caption) !== '';

        // на отправке пересобираем всегда: день и анонс парсера (parser:tg:describe-due) меняются до
        // последнего. Сборка бесплатная, без модели. Вне доставки готовый текст не трогаем
        $stale = $sendingAt !== null && $hasCaption;

        if ($hasCaption && ! $stale) {
            return;
        }

        // Площадку тянем сразу: её имя печатается в строке места, и без
        // eager-load сборщик подписи дёрнул бы связь отдельным запросом.
        $event ??= Event::query()->with('venue:id,name')->find($item->event_id);
        if (! $event) {
            return;
        }

        try {
            $item->caption = $this->captionBuilder->build(
                $event,
                // Форма — по дню публикации: весь день одной, назавтра другой.
                $broadcast->templateCodeForDate($item->publish_at ?? $item->planned_at),
                $showDay,
                (int) $item->id,
            );
            $item->caption_source = TelegramChatBroadcastItem::CAPTION_TEMPLATE;
            $item->save();
        } catch (\Throwable $e) {
            // выдачу не роняем: без текста бот соберёт его сам по template_code
            Log::warning('caption.build_failed', [
                'item_id' => $item->id,
                'event_id' => $item->event_id,
                'template_code' => $broadcast->template_code,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Постоянный отказ отправки: статус error выводит запись из выдачи боту. */
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
     * Заполнить свободные слоты ленты на горизонт (broadcast:fill-feed и кнопки админки).
     * publish_at ставим сразу: от него текст считает «сегодня» и «завтра».
     *
     * @return array{filled: int, days: int, no_candidate: int}
     */
    public function fillFeedDays(TelegramChatBroadcast $broadcast, Carbon $now): array
    {
        $chat = $broadcast->chat;
        $summary = ['filled' => 0, 'days' => 0, 'no_candidate' => 0, 'feed_limit' => 0];

        if (! $chat instanceof TelegramChat || ! $chat->city_id) {
            return $summary;
        }

        // BroadcastSafety здесь нет: кнопка админки на стенде должна планировать,
        // запрет стоит в выдаче боту и в автоматических командах

        $slots = $this->effectiveSlots($broadcast);
        $weekday = $this->periodWeekday($broadcast);

        // карта «слот → событие»: занятость по слоту, а не по дню, а по событию узнаём тему соседа
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
            // вне сетки слот не занимают: их publish_at — момент нажатия, в час слота он попадает случайно
            ->where('is_off_grid', false)
            ->get(['publish_at', 'event_id']) as $row) {
            $taken[$this->slotKey(Carbon::parse($row->publish_at))] = $row->event_id !== null
                ? (int) $row->event_id
                : null;
        }

        // тема поста перед горизонтом: первый слот недели тоже чей-то сосед.
        // У брони подборки темы нет (null): её выбирают накануне слота
        $prevTheme = $this->primaryInterestId((int) (TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_EVENT)
            ->whereNotNull('event_id')
            // Снятого поста в канале нет — соседом он быть не может.
            ->where('status', '<>', TelegramChatBroadcastItem::STATUS_WITHDRAWN)
            ->where(function ($w) use ($now) {
                $w->where('posted_at', '<=', $now)
                    ->orWhere(function ($x) use ($now) {
                        $x->whereNotNull('publish_at')->where('publish_at', '<=', $now);
                    });
            })
            ->orderByRaw('COALESCE(posted_at, publish_at) DESC')
            ->value('event_id') ?? 0));

        // ждущие дня занимают слоты первыми, иначе наполнитель всегда брал бы новое из пула
        $waiting = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
            ])
            ->whereNull('publish_at')
            ->whereNull('posted_at')
            // Вне сетки слот не занимает, значит и не ждёт его: без дня такая
            // запись ждёт решения человека.
            ->where('is_off_grid', false)
            ->get()
            // события по близости, записи с готовым текстом в хвост: у них нет срока.
            // По hasReadyCaption: у подборки Event::find(null) дал бы '' и поставил её первой
            ->sortBy(fn (TelegramChatBroadcastItem $i) => $i->hasReadyCaption()
                ? '9999'
                : (string) optional(Event::query()->find($i->event_id))?->start_time)
            ->values()
            ->all();

        // кап feed_limit по событиям, как в enqueueDueForAllChannels. Ограничивает только добор
        // из пула: ждущие уже посчитаны, иначе на полной ленте очередь ожидания встала бы
        $openEvents = $this->broadcastItemRepository->countOpenForBroadcast(
            $broadcast->id,
            TelegramChatBroadcastItem::KIND_EVENT,
        );

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

                // поздний слот занимаем не раньше чем за fill_lead_days (если задан): половина афиши объявляется поздно.
                // Первый слот дня плановый всегда
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
                    // готовый текст (портрет, подборка) занимает слот как есть: события для проверки срока нет
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
                    if (! PostTiming::fits($event, $publishAt, self::MIN_LEAD_HOURS)) {
                        continue;
                    }

                    // событие в день слота — только запасной вариант, если другого ждущего нет
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
                            $fromWaiting->caption = $this->venuePortraitService->buildVenueCaption(
                                $venue,
                                $publishAt,
                                (int) $fromWaiting->id,
                            );
                            $fromWaiting->caption_source = TelegramChatBroadcastItem::CAPTION_TEMPLATE;
                            $fromWaiting->save();
                        }
                    } else {
                        $this->ensureEventCaption($fromWaiting, $broadcast);
                        $exclude = array_merge($exclude, $this->linkedEventIds((int) $fromWaiting->id));
                    }

                    $prevTheme = $this->primaryInterestId($fromWaiting->event_id);
                    $summary['filled']++;

                    continue;
                }

                // кап канала, а не голодание пула (то — no_candidate)
                if ($openEvents >= $broadcast->feed_limit) {
                    if ($summary['feed_limit'] === 0) {
                        Log::info('broadcast.feed.limit_reached', [
                            'broadcast_id' => $broadcast->id,
                            'open_events' => $openEvents,
                            'feed_limit' => $broadcast->feed_limit,
                            'why' => 'открытых событийных записей не меньше капа ленты — добор из пула остановлен',
                        ]);
                    }
                    $summary['feed_limit']++;

                    continue;
                }

                // не та же тема, что у соседа: два концерта подряд читаются как одна новость
                $eventId = $this->pickBestEventIdForChat(
                    $chat,
                    $broadcast->id,
                    $exclude,
                    $publishAt->copy()->utc(),
                    $this->venueRepeatCap($broadcast),
                    $prevTheme,
                );

                // правило мягкое: лучше повтор темы, чем пустой слот; повтор пишем в лог
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

                // enqueue() вернёт существующую запись со старым статусом (UNIQUE на broadcast_id + event_id),
                // ниже её оживляем, иначе пост осядет невидимым
                $item = $this->broadcastItemRepository->enqueue($broadcast->id, $eventId, null);

                // вышедшую не оживляем: выдача боту posted_at не проверяет, и пост ушёл бы второй раз
                if ($item->posted_at !== null) {
                    Log::warning('broadcast.feed.skip_already_posted', [
                        'broadcast_id' => $broadcast->id,
                        'item_id' => $item->id,
                        'event_id' => $eventId,
                        'posted_at' => $item->posted_at->toIso8601String(),
                    ]);

                    continue;
                }

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
                // считаем в памяти: за прогон открытые событийные записи растут только здесь
                $openEvents++;
                $summary['filled']++;
            }
        }

        return $summary;
    }

    /**
     * Кэш первичной темы события на прогон. Первичных интересов бывает несколько, берём
     * наименьший id: нужна не «правильная» тема, а устойчивое сравнение соседей.
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

    /** Один ключ на все сравнения «занято ли место в ленте». */
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

    /** День, когда пост увидят (publish_at, planned_at или сегодня): от него «сегодня» и «завтра» в тексте. */
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

        // слоты делят окно: при двух постах в день нормальное молчание вдвое короче
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
     * Попросить бота посчитать подписчиков, раз в сутки. Через бота: getChatMemberCount
     * отвечает только админу канала с токеном, а api в телеграм не ходит.
     *
     * @return array<string, mixed>|null
     */
    private function buildSubscriberCountTask(
        TelegramChatBroadcast $broadcast,
        TelegramChat $chat,
        Carbon $now,
    ): ?array {
        if (! $chat->telegram_chat_id || ! config('broadcast_subscribers.enabled', true)) {
            return null;
        }

        $today = $now->copy()->setTimezone(self::SCHEDULE_TZ)->toDateString();
        if ((string) (($broadcast->settings ?? [])['subscribers_measured_on'] ?? '') === $today) {
            return null;
        }

        // отметку ставим до ответа бота, иначе при недоступном телеграме задача уходила бы каждую минуту
        $settings = $broadcast->settings ?? [];
        $settings['subscribers_measured_on'] = $today;
        $broadcast->settings = $settings;
        $broadcast->save();

        return [
            'type' => 'subscribers',
            'broadcast_id' => (int) $broadcast->id,
            'telegram_chat_id' => (int) $chat->telegram_chat_id,
        ];
    }

    /** Число подписчиков за сегодня; повторный вызов в тот же день его перезаписывает. */
    public function recordSubscriberCount(
        int $telegramChatId,
        int $count,
        Carbon $now,
        ?bool $reactionsEnabled = null,
    ): bool {
        $chatId = DB::table('telegram.chats')
            ->where('telegram_chat_id', $telegramChatId)
            ->value('id');

        if ($chatId === null) {
            return false;
        }

        // заодно, включены ли реакции: без них пустота в админке неотличима от «реакций не было».
        // null — спросить не удалось, прежнее значение не трогаем
        if ($reactionsEnabled !== null) {
            foreach (TelegramChatBroadcast::query()->where('chat_id', (int) $chatId)->get() as $broadcast) {
                $settings = $broadcast->settings ?? [];
                $settings['reactions_enabled'] = $reactionsEnabled;
                $settings['reactions_checked_at'] = $now->toIso8601String();
                $broadcast->settings = $settings;
                $broadcast->save();
            }
        }

        DB::table('telegram.chat_subscriber_counts')->upsert([[
            'chat_id' => (int) $chatId,
            'measured_on' => $now->copy()->setTimezone(self::SCHEDULE_TZ)->toDateString(),
            'count' => max(0, $count),
            'created_at' => $now,
        ]], ['chat_id', 'measured_on'], ['count']);

        return true;
    }

    /**
     * Реакции на пост: message_reaction_count приносит полный состав, не дельту, поэтому замена.
     * message_id уникален только в чате, ищем в каналах этого чата. Чужой пост — false.
     *
     * @param  list<array{emoji: string, count: int}>  $counts
     */
    public function recordReactions(
        int $telegramChatId,
        int $messageId,
        array $counts,
        Carbon $now,
    ): bool {
        $chatId = DB::table('telegram.chats')
            ->where('telegram_chat_id', $telegramChatId)
            ->value('id');

        if ($chatId === null) {
            return false;
        }

        $item = TelegramChatBroadcastItem::query()
            ->whereIn('broadcast_id', TelegramChatBroadcast::query()
                ->where('chat_id', (int) $chatId)
                ->select('id'))
            ->where('message_id', $messageId)
            ->first();

        if (! $item) {
            return false;
        }

        $clean = [];
        $total = 0;
        foreach ($counts as $row) {
            $emoji = trim((string) ($row['emoji'] ?? ''));
            $count = max(0, (int) ($row['count'] ?? 0));
            if ($emoji === '' || $count === 0) {
                continue;
            }
            $clean[] = ['emoji' => $emoji, 'count' => $count];
            $total += $count;
        }

        usort($clean, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $item->reactions = $total;
        $item->reactions_meta = $clean === [] ? null : $clean;
        $item->reactions_at = $now;
        $item->save();

        Log::info('broadcast.reactions.recorded', [
            'item_id' => $item->id,
            'message_id' => $messageId,
            'total' => $total,
        ]);

        return true;
    }

    /**
     * Задача «канал молчит» или null. Порог — два пропущенных окна подряд (periodWindowHours),
     * напоминание не чаще раза в сутки: поллер тикает каждую минуту.
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
     * Снять вышедший пост, удалённый из канала руками: телеграм об удалении не сообщает.
     * posted_at гасим и ставим withdrawn, чтобы зазор и анти-дубли перестали его считать;
     * подпись и связи с событиями остаются историей.
     */
    public function withdrawPostedItem(TelegramChatBroadcastItem $item): bool
    {
        if ($item->posted_at === null) {
            return false;
        }

        $postedAt = Carbon::parse($item->posted_at);

        $item->status = TelegramChatBroadcastItem::STATUS_WITHDRAWN;
        $item->posted_at = null;
        $item->message_id = null;
        $item->claimed_at = null;
        $item->claim_token = null;
        $item->error_message = 'снято из канала вручную '.Carbon::now()->toDateTimeString();
        $item->save();

        Log::info('broadcast.item_withdrawn', [
            'item_id' => $item->id,
            'broadcast_id' => $item->broadcast_id,
            'kind' => $item->kind,
            'posted_at' => $postedAt->toIso8601String(),
        ]);

        return true;
    }

    /**
     * Когда каналу снова можно постить; null — уже можно. По lastPostedAt, а не по
     * last_run_at: его двигает только событийная отправка.
     */
    public function nextPostAllowedAt(int $broadcastId, Carbon $now): ?Carbon
    {
        $gap = TelegramChatBroadcast::query()->find($broadcastId)?->min_gap_minutes ?? self::MIN_GAP_MINUTES;
        $allowedAt = $this->lastPostedAt($broadcastId)?->addMinutes($gap);

        return $allowedAt && $allowedAt->gt($now) ? $allowedAt : null;
    }

    /**
     * Часы публикации канала по Москве, по возрастанию; см. BroadcastSlotPlanner::slots.
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

            // час московский (SCHEDULE_TZ); Carbon сравнивает абсолютные моменты, разные пояса тут корректны
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

            while ($candidate->dayOfWeek !== $targetDow || $candidate->gt($now)) {
                $candidate->subDay();
            }

            return ! $lastRun || $lastRun->lt($candidate);
        }

        // неизвестный period — игнорируем
        return false;
    }
}
