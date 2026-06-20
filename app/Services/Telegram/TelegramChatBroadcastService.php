<?php

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramChatBroadcastItemRepositoryInterface;
use App\Contracts\Telegram\TelegramChatBroadcastRepositoryInterface;
use App\Contracts\Telegram\TelegramChatRepositoryInterface;
use App\Contracts\Telegram\TelegramUserRepositoryInterface;
use App\Contracts\Telegram\BotRoleServiceInterface;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Services\Telegram\Scoring\EventBroadcastScorer;
use Carbon\Carbon;
use DateTimeInterface;
use RuntimeException;

class TelegramChatBroadcastService
{
    /** Кап кандидатного пула под скоринг (на канал — события одного города). */
    private const SCORING_CANDIDATE_LIMIT = 100;

    /** Окно cross-time анти-дубля: не повторять тот же заголовок в канале N дней. */
    private const CROSS_TIME_WINDOW_DAYS = 14;

    public function __construct(
        private readonly TelegramUserRepositoryInterface              $telegramUserRepository,
        private readonly TelegramChatRepositoryInterface              $chatRepository,
        private readonly TelegramChatBroadcastRepositoryInterface     $broadcastRepository,
        private readonly TelegramChatBroadcastItemRepositoryInterface $broadcastItemRepository,
        private readonly BotRoleServiceInterface                      $botRoleService,
        private readonly EventBroadcastScorer                         $scorer,
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

        if (!$event) {
            throw new RuntimeException('Событие не найдено.');
        }

        $eventCityId = $event->community?->city_id;
        $chatCityId  = $chat->city_id;

        if ($chatCityId && $eventCityId && $chatCityId !== $eventCityId) {
            throw new RuntimeException('Это событие относится к другому городу.');
        }

        return $this->broadcastItemRepository->enqueue(
            $broadcast->id,
            $eventId,
            $plannedAt,
        );
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
    ): void {
        [$chat] = $this->resolveManagedChat($telegramId, $telegramChatId);

        $broadcast = $this->broadcastRepository->getOrCreateByChatId($chat->id);

        $item = $this->broadcastItemRepository
            ->findByBroadcastAndEvent($broadcast->id, $eventId);

        if (!$item) {
            $item = $this->broadcastItemRepository->enqueue(
                $broadcast->id,
                $eventId,
                $moment,
            );
        }

        $this->broadcastItemRepository->markPosted($item, $moment);
        $this->broadcastRepository->touchLastRunAt($chat->id, $moment);
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
     * @param int    $telegramId       Telegram ID пользователя (из лички)
     * @param int    $telegramChatId   telegram_chat_id канала/чата
     * @param string $mode             'preview' | 'run' и т.п. (на будущее, пока не используется)
     * @param array  $excludeEventIds  Список event_id, которые нельзя предлагать
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
        if (!empty($excludeEventIds)) {
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

        if (!$item) {
            // Тихо выходим — ничего в очереди не было
            return;
        }

        $this->broadcastItemRepository->markSkipped(
            $item,
            $reason ?: 'cancelled_by_user',
        );
    }

    /**
     * Собрать список «запланированных запусков» одиночной рассылки
     * на текущий момент.
     *
     * Каждый элемент = [
     *   'telegram_id'      => int,  // владелец/админ, от имени которого считаем права
     *   'telegram_chat_id' => int,  // сам канал/чат
     *   'event_id'         => int,
     *   'template_code'    => string,
     * ]
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
            if (!$this->isSingleRunDue($broadcast, $now)) {
                continue;
            }

            // Ищем следующий элемент очереди для этого канала
            $item = $this->broadcastItemRepository
                ->findNextDueForBroadcast($broadcast->id, $now);

            if (!$item) {
                // очередь пустая или всё ещё рано по planned_at
                continue;
            }

            $chat = $broadcast->chat;
            if (!$chat instanceof TelegramChat || !$chat->telegram_chat_id) {
                continue;
            }

            // Владелец канала (telegram_id пользователя, который привязал чат)
            $ownerTelegramId = $chat->owner?->telegram_id ?? null;
            if (!$ownerTelegramId) {
                // Если владельца не знаем — безопаснее пропустить
                continue;
            }

            $tasks[] = [
                'telegram_id'      => (int) $ownerTelegramId,
                'telegram_chat_id' => (int) $chat->telegram_chat_id,
                'event_id'         => (int) $item->event_id,
                'template_code'    => (string) $broadcast->template_code,
            ];

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
     * @return array{checked:int,due:int,enqueued:int,skipped_no_city:int,skipped_queue_busy:int,no_candidate:int}
     */
    public function enqueueDueForAllChannels(Carbon $now, bool $dryRun = false): array
    {
        $summary = [
            'checked'           => 0,
            'due'               => 0,
            'enqueued'          => 0,
            'skipped_no_city'   => 0,
            'skipped_queue_busy' => 0,
            'no_candidate'      => 0,
        ];

        $broadcasts = $this->broadcastRepository->listEnabledWithSchedule();

        foreach ($broadcasts as $broadcast) {
            $summary['checked']++;

            if (!$this->isSingleRunDue($broadcast, $now)) {
                continue;
            }
            $summary['due']++;

            $chat = $broadcast->chat;
            if (!$chat instanceof TelegramChat || !$chat->city_id || !$chat->telegram_chat_id) {
                $summary['skipped_no_city']++;
                continue;
            }

            // Одно событие в полёте: если в очереди уже есть незакрытый item — не плодим.
            $open = $this->broadcastItemRepository->countForBroadcast($broadcast->id, [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
            ]);
            if ($open > 0) {
                $summary['skipped_queue_busy']++;
                continue;
            }

            $eventId = $this->pickBestEventIdForChat($chat, $broadcast->id);
            if (!$eventId) {
                $summary['no_candidate']++;
                continue;
            }

            if (!$dryRun) {
                $this->broadcastItemRepository->enqueue($broadcast->id, $eventId, null);
            }
            $summary['enqueued']++;
        }

        return $summary;
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
        if (!in_array($role, ['user', 'moderator', 'admin', 'superadmin'], true)) {
            throw new RuntimeException('Недостаточно прав для управления связанными чатами');
        }

        $telegramUser = $this->telegramUserRepository->findByTelegramId($telegramId);
        if (!$telegramUser) {
            throw new RuntimeException('Telegram-пользователь не найден в БД');
        }

        $telegramChat = $this->chatRepository->findByTelegramChatId($telegramChatId);
        if (!$telegramChat) {
            throw new RuntimeException('Чат не найден в БД: '.$telegramChatId);
        }

        // Базовое ограничение: чат должен принадлежать этому пользователю.
        if ($telegramChat->telegram_user_id !== $telegramUser->id) {
            // Разрешаем superadmin/admin управлять любыми чатами (опционально).
            if (!in_array($role, ['admin', 'superadmin'], true)) {
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
     * @param int[] $excludeEventIds
     */
    private function pickBestEventIdForChat(
        TelegramChat $chat,
        int $broadcastId,
        array $excludeEventIds = [],
    ): ?int {
        $usedStatuses = [
            TelegramChatBroadcastItem::STATUS_PENDING,
            TelegramChatBroadcastItem::STATUS_PLANNED,
            TelegramChatBroadcastItem::STATUS_POSTED,
            TelegramChatBroadcastItem::STATUS_SKIPPED,
        ];

        $query = Event::query()
            ->active()
            ->upcoming()
            ->whereDoesntHave('broadcastItems', function ($q) use ($broadcastId, $usedStatuses) {
                $q->where('broadcast_id', $broadcastId)
                    ->whereIn('status', $usedStatuses);
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

        if (!empty($excludeEventIds)) {
            $query->whereNotIn('id', array_values(array_unique(array_map('intval', $excludeEventIds))));
        }

        // Кандидатный пул — ближайшие, кап; качество выбираем скорингом в PHP.
        $candidates = $query
            ->with(['sources:id,event_id,images,published_at'])
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
        if (!empty($recentTitles)) {
            $fresh = $candidates->reject(
                fn (Event $e) => in_array($this->normalizeTitle($e->title), $recentTitles, true),
            );
            if ($fresh->isNotEmpty()) {
                $pool = $fresh;
            }
        }

        return $this->scorer->pickBest($pool)?->id;
    }

    /**
     * Нормализованные заголовки событий, ПОСТНУТЫХ в этом канале за окно $since..now.
     * Для cross-time анти-дубля повторяющихся событий.
     *
     * @return string[]
     */
    private function recentlyPostedTitleNorms(int $broadcastId, Carbon $since): array
    {
        $titles = TelegramChatBroadcastItem::query()
            ->from('telegram.chat_broadcast_items as i')
            ->join('events as e', 'e.id', '=', 'i.event_id')
            ->where('i.broadcast_id', $broadcastId)
            ->where('i.status', TelegramChatBroadcastItem::STATUS_POSTED)
            ->where('i.posted_at', '>=', $since)
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
    private function isSingleRunDue(
        TelegramChatBroadcast $broadcast,
        Carbon $now,
    ): bool {
        if (!$broadcast->enabled) {
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
            return !$lastRun || $lastRun->lt($candidate);
        }

        // weekly_<dow>_<HH>  (пример: weekly_fri_12)
        if (str_starts_with($period, 'weekly_')) {
            $parts   = explode('_', $period); // [weekly, fri, 12]
            $dowCode = $parts[1] ?? 'fri';
            $hour    = isset($parts[2]) ? (int) $parts[2] : 12;

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

            return !$lastRun || $lastRun->lt($candidate);
        }

        // неизвестный period — игнорируем
        return false;
    }
}
