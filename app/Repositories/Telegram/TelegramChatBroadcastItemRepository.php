<?php

namespace App\Repositories\Telegram;

use App\Contracts\Telegram\TelegramChatBroadcastItemRepositoryInterface;
use App\Models\TelegramChatBroadcastItem;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramChatBroadcastItemRepository implements TelegramChatBroadcastItemRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function findByBroadcastAndEvent(
        int $broadcastId,
        int $eventId,
    ): ?TelegramChatBroadcastItem {
        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->where('event_id', $eventId)
            // Только событийные: это вопрос «какую строку оживить», а НЕ
            // «показывал ли канал событие» — на второй отвечает связь
            // «пост → события». Без фильтра enqueue() мог бы вернуть запись
            // рубрики и оживить подборку как обычный пост.
            ->where('kind', TelegramChatBroadcastItem::KIND_EVENT)
            ->first();
    }


    /**
     * {@inheritdoc}
     */
    public function enqueue(
        int $broadcastId,
        int $eventId,
        ?DateTimeInterface $plannedAt = null,
    ): TelegramChatBroadcastItem {
        $existing = $this->findByBroadcastAndEvent($broadcastId, $eventId);
        if ($existing) {
            return $existing;
        }

        // В транзакции: на сохранении висит обсервер, пишущий связь
        // «пост → события». Без неё запись и её связь могли бы разъехаться,
        // если между ними что-то упадёт, — а недостающую строку связи не видно
        // ничем, кроме broadcast:links:backfill --check.
        return DB::transaction(function () use ($broadcastId, $eventId, $plannedAt) {
            $item = new TelegramChatBroadcastItem;
            $item->broadcast_id = $broadcastId;
            $item->event_id = $eventId;

            if ($plannedAt) {
                $item->status = TelegramChatBroadcastItem::STATUS_PLANNED;
                $item->planned_at = $plannedAt;
            } else {
                $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
            }

            $item->save();

            return $item->refresh();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function enqueueForReview(
        int $broadcastId,
        int $eventId,
        int $reviewerTelegramId,
        DateTimeInterface $deadlineAt,
        ?DateTimeInterface $plannedAt = null,
    ): TelegramChatBroadcastItem {
        $existing = $this->findByBroadcastAndEvent($broadcastId, $eventId);
        if ($existing) {
            return $existing;
        }

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcastId;
        $item->event_id = $eventId;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING_REVIEW;
        $item->review_reviewer_telegram_id = $reviewerTelegramId;
        $item->review_deadline_at = $deadlineAt;
        // придержка на время генерации текста: превью ревьюеру должно уйти уже с ним
        $item->planned_at = $plannedAt;
        $item->save();

        return $item->refresh();
    }

    /**
     * {@inheritdoc}
     */
    public function findNextPlannedForBroadcast(
        int $broadcastId,
        ?DateTimeInterface $before = null,
    ): ?TelegramChatBroadcastItem {
        $before = $before ?: now();

        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->where('status', TelegramChatBroadcastItem::STATUS_PLANNED)
            ->where('planned_at', '<=', $before)
            ->orderBy('planned_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * {@inheritdoc}
     */
    public function markPosted(
        TelegramChatBroadcastItem $item,
        ?DateTimeInterface $moment = null,
    ): TelegramChatBroadcastItem {
        $item->status = TelegramChatBroadcastItem::STATUS_POSTED;
        $item->posted_at = $moment ?: now();
        // planned_at оставляем как есть (может пригодиться для анализа)
        $item->error_message = null;
        $item->claimed_at = null;
        $item->claim_token = null;
        $item->save();

        return $item->refresh();
    }

    /**
     * {@inheritdoc}
     *
     * Так параллельный поллер / повторный poll после краша не берёт айтем дважды.
     */
    public function claimForPublish(int $itemId, DateTimeInterface $now, int $leaseSeconds): ?string
    {
        $nowCarbon = $now instanceof Carbon ? $now->copy() : Carbon::instance($now);
        $cutoff = $nowCarbon->copy()->subSeconds(max(1, $leaseSeconds));
        $token = (string) Str::uuid();

        $affected = TelegramChatBroadcastItem::query()
            ->where('id', $itemId)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ])
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('claimed_at')->orWhere('claimed_at', '<', $cutoff);
            })
            ->update([
                'claimed_at' => $nowCarbon,
                'claim_token' => $token,
                'updated_at' => $nowCarbon,
            ]);

        return $affected === 1 ? $token : null;
    }

    /**
     * {@inheritdoc}
     *
     * Если lease истёк и айтем реклеймил другой поллер, наш токен не совпадёт
     * → 0 строк → false: не двигаем last_run за чужой пост.
     */
    public function markPostedIfClaimed(int $itemId, string $claimToken, ?DateTimeInterface $moment = null): bool
    {
        $affected = TelegramChatBroadcastItem::query()
            ->where('id', $itemId)
            ->where('claim_token', $claimToken)
            // status-guard: помечаем posted только из публикуемого статуса — не
            // флипаем уже skipped/error/posted айтем, даже если токен совпал.
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ])
            ->update([
                'status' => TelegramChatBroadcastItem::STATUS_POSTED,
                'posted_at' => $moment ?: now(),
                'error_message' => null,
                'claimed_at' => null,
                'claim_token' => null,
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    /**
     * {@inheritdoc}
     */
    public function markSkipped(
        TelegramChatBroadcastItem $item,
        ?string $reason = null,
    ): TelegramChatBroadcastItem {
        $item->status = TelegramChatBroadcastItem::STATUS_SKIPPED;
        $item->error_message = $reason;
        $item->save();

        return $item->refresh();
    }

    /**
     * {@inheritdoc}
     */
    public function markError(
        TelegramChatBroadcastItem $item,
        string $errorMessage,
    ): TelegramChatBroadcastItem {
        $item->status = TelegramChatBroadcastItem::STATUS_ERROR;
        $item->error_message = $errorMessage;
        $item->save();

        return $item->refresh();
    }


    /**
     * {@inheritdoc}
     */
    public function listForBroadcast(
        int $broadcastId,
        array $statuses,
        int $limit,
    ): Collection {
        $query = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId);

        if (! empty($statuses)) {
            $query->whereIn('status', $statuses);
        }

        return $query
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * {@inheritdoc}
     */
    public function countOpenForBroadcast(int $broadcastId, ?string $kind = null): int
    {
        $q = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ]);

        if ($kind === TelegramChatBroadcastItem::KIND_VENUE) {
            $q->where('kind', TelegramChatBroadcastItem::KIND_VENUE);
        } elseif ($kind === 'event') {
            // Явный тип, а не «всё, что не портрет площадки». Отрицание молча
            // зачисляет в события ЛЮБУЮ новую рубрику: подборка недели съела бы
            // ячейку feed_limit, и автонаполнение перестало бы докладывать
            // события на день раньше срока. Про исторический NULL — колонка
            // NOT NULL DEFAULT 'event', такой записи в базе быть не может.
            $q->where('kind', TelegramChatBroadcastItem::KIND_EVENT);
        }

        return (int) $q->count();
    }

    public function countForBroadcast(
        int $broadcastId,
        array $statuses,
    ): int {
        $query = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId);

        if (! empty($statuses)) {
            $query->whereIn('status', $statuses);
        }

        return (int) $query->count();
    }

    /**
     * {@inheritdoc}
     */
    public function findActiveForBroadcast(int $broadcastId, DateTimeInterface $now): ?TelegramChatBroadcastItem
    {
        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->whereIn('status', [
                TelegramChatBroadcastItem::STATUS_PENDING,
                TelegramChatBroadcastItem::STATUS_PLANNED,
                TelegramChatBroadcastItem::STATUS_PENDING_REVIEW,
                TelegramChatBroadcastItem::STATUS_APPROVED,
                TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
            ])
            ->where(function ($q) use ($now) {
                // pending/planned ждут planned_at; ревью-статусы готовы сразу.
                $q->whereNotIn('status', [
                    TelegramChatBroadcastItem::STATUS_PENDING,
                    TelegramChatBroadcastItem::STATUS_PLANNED,
                ])
                    ->orWhereNull('planned_at')
                    ->orWhere('planned_at', '<=', $now);
            })
            // Назначенный день. До этого publish_at не читал НИКТО: поллер
            // брал самый старый открытый пост по created_at, поэтому вся
            // недельная сетка, перетаскивание и «отправить сейчас» на эфир
            // не влияли, а вытесненный пост уходил в канал первым — он ведь
            // старше того, кто его вытеснил.
            //
            // Ревью-задачу выпускаем заранее, не дожидаясь дня: иначе превью
            // пришло бы рецензенту ровно в момент публикации и решать было бы
            // уже нечего.
            ->where(function ($q) use ($now) {
                $q->whereNull('publish_at')
                    ->orWhere('publish_at', '<=', $now)
                    ->orWhere('status', TelegramChatBroadcastItem::STATUS_PENDING_REVIEW);
            })
            // Сначала назначенные на день, и только потом — те, кому дня не
            // досталось. Одного COALESCE мало: у поста без дня подставляется
            // его created_at, а он старше, поэтому вытесненный пост обгонял
            // бы того, кто занял его день, — ровно наоборот обещанию «ждёт
            // свободного дня».
            // Номер записи последним ключом — не украшение. `created_at` имеет
            // точность до секунды, и два поста, заведённых в одну секунду,
            // дают полную ничью: что вернёт Postgres, не определено ничем.
            // На проде это «какой из двух постов одного слота уйдёт первым»,
            // а в тестах — падение раз через раз, в зависимости от того,
            // успели ли две вставки в одну секунду.
            ->orderByRaw('(publish_at IS NULL) ASC, COALESCE(publish_at, planned_at, created_at) ASC, id ASC')
            ->first();
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $itemId): ?TelegramChatBroadcastItem
    {
        return TelegramChatBroadcastItem::query()->find($itemId);
    }

    /**
     * {@inheritdoc}
     */
    public function setReviewMessageId(TelegramChatBroadcastItem $item, int $messageId): TelegramChatBroadcastItem
    {
        $item->review_message_id = $messageId;
        $item->save();

        return $item->refresh();
    }

    /**
     * {@inheritdoc}
     */
    public function applyReviewDecision(
        TelegramChatBroadcastItem $item,
        string $newStatus,
        string $action,
        DateTimeInterface $now,
    ): bool {
        // Атомарный guard по status: если timeout-sweeper / другой запрос уже увёл item
        // из pending_review — наш UPDATE его не тронет (0 затронутых), решение не теряется.
        $affected = TelegramChatBroadcastItem::query()
            ->where('id', $item->id)
            ->where('status', TelegramChatBroadcastItem::STATUS_PENDING_REVIEW)
            ->update([
                'status' => $newStatus,
                'review_action' => $action,
                'reviewed_at' => $now,
            ]);

        return $affected > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function autoApproveExpiredReviews(DateTimeInterface $now): int
    {
        return TelegramChatBroadcastItem::query()
            ->where('status', TelegramChatBroadcastItem::STATUS_PENDING_REVIEW)
            ->whereNotNull('review_deadline_at')
            ->where('review_deadline_at', '<=', $now)
            ->update([
                'status' => TelegramChatBroadcastItem::STATUS_AUTO_APPROVED,
                'review_action' => 'timeout',
                'reviewed_at' => $now,
            ]);
    }
}
