<?php

namespace App\Services\Telegram\Scoring;

use App\Models\Event;

/**
 * Контент-скоринг события для автопостинга в city-канал (P0.3, без LLM).
 *
 * Заменяет «ближайшее по start_time» на качественный выбор: карточка с фото и точным
 * адресом постится охотнее далёкой/безкартиночной. Чистая функция score(Event):int —
 * покрыта unit-тестами; жёсткие фильтры (sold_out / official-religious / status) живут
 * в SQL-выборке кандидатов (TelegramChatBroadcastService::pickBestEventIdForChat).
 *
 * Сигналы читаются из загруженного события: фото — через images() (нужен eager-load
 * `sources`), интересы — через interests_count (withCount('interests')).
 */
class EventBroadcastScorer
{
    // Веса факторов (см. ROADMAP, эпик P0, scoring_design).
    public const W_PHOTO        = 40; // фото = главный драйвер CTR в TG
    public const W_FIAS         = 20; // подтверждённый дом-адрес
    public const W_VENUE        = 15; // узнаваемая площадка
    public const W_INTERESTS    = 15; // протегировано интересами
    public const W_TICKETS      = 10; // билеты доступны
    public const W_PRICE        = 5;  // цена известна
    public const W_DESCRIPTION  = 10; // содержательное описание
    public const W_TIME_EXACT   = 5;  // есть точное время, не только дата

    public const MIN_DESCRIPTION_LEN = 120;

    /**
     * Штраф за дальность старта (daily-канал не любит далёкие события).
     * [максимум_дней => штраф]; всё, что дальше последнего порога — последний штраф.
     */
    private const FRESHNESS_TIERS = [
        2  => 0,    // сегодня / завтра / послезавтра
        7  => -5,
        30 => -12,
    ];
    private const FRESHNESS_FAR = -25; // > 30 дней

    public function score(Event $e): int
    {
        $score = 0;

        if ($this->hasPhoto($e)) {
            $score += self::W_PHOTO;
        }
        if (!empty($e->house_fias_id)) {
            $score += self::W_FIAS;
        }
        if (!empty($e->venue_id)) {
            $score += self::W_VENUE;
        }
        if ($this->interestsCount($e) >= 1) {
            $score += self::W_INTERESTS;
        }
        if ($e->tickets_status === 'available') {
            $score += self::W_TICKETS;
        }
        if ($this->hasKnownPrice($e)) {
            $score += self::W_PRICE;
        }
        if (mb_strlen((string) $e->description) >= self::MIN_DESCRIPTION_LEN) {
            $score += self::W_DESCRIPTION;
        }
        if ($e->time_precision === 'datetime') {
            $score += self::W_TIME_EXACT;
        }

        $score += $this->freshnessDelta($e);

        return $score;
    }

    /**
     * Лучшее событие из набора: максимальный score, при равенстве — ближайшее по
     * start_time (детерминированный tie-break — защита от гонок параллельных тиков).
     *
     * @param iterable<Event> $events
     */
    public function pickBest(iterable $events): ?Event
    {
        $best = null;
        $bestScore = PHP_INT_MIN;

        foreach ($events as $e) {
            $s = $this->score($e);

            if ($s > $bestScore) {
                $best = $e;
                $bestScore = $s;
                continue;
            }

            if ($s === $bestScore && $best !== null
                && $e->start_time !== null && $best->start_time !== null
                && $e->start_time->lt($best->start_time)) {
                $best = $e;
            }
        }

        return $best;
    }

    private function hasPhoto(Event $e): bool
    {
        return count($e->images(1)) > 0;
    }

    private function interestsCount(Event $e): int
    {
        // interests_count проставляет withCount('interests'); fallback — посчитать связь.
        $count = $e->getAttribute('interests_count');
        if ($count !== null) {
            return (int) $count;
        }

        return $e->relationLoaded('interests') ? $e->interests->count() : 0;
    }

    private function hasKnownPrice(Event $e): bool
    {
        if (in_array($e->price_status, ['free', 'priced'], true)) {
            return true;
        }

        return $e->price_min !== null;
    }

    private function freshnessDelta(Event $e): int
    {
        if ($e->start_time === null) {
            return self::FRESHNESS_FAR;
        }

        $days = now()->startOfDay()->diffInDays($e->start_time->copy()->startOfDay(), false);

        foreach (self::FRESHNESS_TIERS as $maxDays => $penalty) {
            if ($days <= $maxDays) {
                return $penalty;
            }
        }

        return self::FRESHNESS_FAR;
    }
}
