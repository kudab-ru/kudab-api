<?php

namespace App\Services\Telegram\Scoring;

use App\Models\Event;
use App\Services\Telegram\PostTiming;
use Carbon\Carbon;

/**
 * Оценка события для автопостинга, без LLM. Жёсткие фильтры в выборке кандидатов
 * (TelegramChatBroadcastService::rankCandidatesForChat). Без withCount('interests')
 * или with('interests') балл за интересы 0, без with('sources') images() идёт в базу
 * на каждое событие.
 */
class EventBroadcastScorer
{
    public const W_PHOTO = 40; // фото = главный драйвер CTR в TG

    public const W_FIAS = 20; // подтверждённый дом-адрес

    public const W_VENUE = 15; // узнаваемая площадка

    public const W_INTERESTS = 15; // протегировано интересами

    public const W_TICKETS = 3; // билеты в продаже; меньше шага свежести, иначе обходит ту же карточку ступенью ближе

    public const W_PRICE = 5; // цена известна

    public const W_DESCRIPTION = 10; // содержательное описание

    public const W_TIME_EXACT = 5; // есть точное время, не только дата

    public const MIN_DESCRIPTION_LEN = 120;

    /** Штраф за дальность старта: [максимум_дней => штраф], дальше последнего порога — FRESHNESS_FAR. */
    private const FRESHNESS_TIERS = [
        2 => 0, // сегодня / завтра / послезавтра
        7 => -5,
        30 => -12,
    ];

    private const FRESHNESS_FAR = -25; // > 30 дней

    /** Идущий прокат штрафуется по дням до закрытия, но не сильнее −12: он идёт сейчас, а не через месяц. */
    private const RUN_TIERS = [
        2 => 0,
        7 => -5,
    ];

    private const RUN_FAR = -12;

    /** Дни свежести — по московскому календарю: в UTC ночное событие попало бы на день раньше. */
    private const TZ = 'Europe/Moscow';

    /** $at — момент поста: от его дня считается свежесть. */
    public function score(Event $e, Carbon $at): int
    {
        return array_sum($this->breakdown($e, $at));
    }

    /**
     * Балл по слагаемым, сумма — score(). Ноль у части карточки значит, что её нет: по этому
     * PickReason пишет, чего в карточке не хватает.
     *
     * @return array{photo: int, address: int, venue: int, theme: int, tickets: int, price: int, description: int, time: int, freshness: int}
     */
    public function breakdown(Event $e, Carbon $at): array
    {
        return [
            'photo' => $this->hasPhoto($e) ? self::W_PHOTO : 0,
            'address' => ! empty($e->house_fias_id) ? self::W_FIAS : 0,
            'venue' => ! empty($e->venue_id) ? self::W_VENUE : 0,
            'theme' => $this->interestsCount($e) >= 1 ? self::W_INTERESTS : 0,
            'tickets' => $e->tickets_status === 'available' ? self::W_TICKETS : 0,
            'price' => $this->hasKnownPrice($e) ? self::W_PRICE : 0,
            'description' => mb_strlen((string) $e->description) >= self::MIN_DESCRIPTION_LEN ? self::W_DESCRIPTION : 0,
            'time' => $e->time_precision === 'datetime' ? self::W_TIME_EXACT : 0,
            'freshness' => $this->freshnessDelta($e, $at),
        ];
    }

    /** @param  iterable<Event>  $events */
    public function pickBest(iterable $events, Carbon $at): ?Event
    {
        return $this->rank($events, $at)[0] ?? null;
    }

    /**
     * От лучшего к худшему: балл, при равном обычное событие раньше идущего проката (начало у проката
     * всегда раньше, и ничья уходила бы ему), дальше ближайшее начало, у проката закрытие.
     * При полном равенстве меньший номер события: порядок строк с одним началом база не обещает.
     *
     * @param  iterable<Event>  $events
     * @return list<Event>
     */
    public function rank(iterable $events, Carbon $at): array
    {
        $rows = [];
        foreach ($events as $e) {
            $closesAt = PostTiming::runningUntil($e, $at);
            $rows[] = [
                [
                    -$this->score($e, $at),
                    $closesAt === null ? 0 : 1,
                    ($closesAt ?? $e->start_time)?->getTimestamp() ?? PHP_INT_MAX,
                    (int) $e->id,
                ],
                $e,
            ];
        }

        usort($rows, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return array_column($rows, 1);
    }

    /** Через сколько дней после дня поста закрывается прокат, который к посту уже идёт; иначе null. */
    public function runDaysLeft(Event $e, Carbon $at): ?int
    {
        $closesAt = PostTiming::runningUntil($e, $at);

        return $closesAt === null ? null : $this->daysAfter($at, $closesAt);
    }

    private function hasPhoto(Event $e): bool
    {
        return count($e->images(1)) > 0;
    }

    private function interestsCount(Event $e): int
    {
        $count = $e->getAttribute('interests_count');
        if ($count !== null) {
            return (int) $count;
        }

        return $e->relationLoaded('interests') ? $e->interests->count() : 0;
    }

    /**
     * Цена известна, если в посте будет сумма, «Бесплатно» или донат. paid без суммы
     * печатается как «Уточняется» (EventCaptionBuilder::priceLabel), балла не даёт.
     */
    private function hasKnownPrice(Event $e): bool
    {
        if (in_array($e->price_status, ['free', 'donation'], true)) {
            return true;
        }

        return $e->price_min !== null || $e->price_max !== null;
    }

    private function freshnessDelta(Event $e, Carbon $at): int
    {
        if ($e->start_time === null) {
            return self::FRESHNESS_FAR;
        }

        $daysLeft = $this->runDaysLeft($e, $at);
        if ($daysLeft !== null) {
            return $this->penalty($daysLeft, self::RUN_TIERS, self::RUN_FAR);
        }

        return $this->penalty($this->daysAfter($at, $e->start_time), self::FRESHNESS_TIERS, self::FRESHNESS_FAR);
    }

    /** @param array<int, int> $tiers */
    private function penalty(int $days, array $tiers, int $far): int
    {
        foreach ($tiers as $maxDays => $penalty) {
            if ($days <= $maxDays) {
                return $penalty;
            }
        }

        return $far;
    }

    private function daysAfter(Carbon $from, Carbon $to): int
    {
        return (int) $from->copy()->setTimezone(self::TZ)->startOfDay()
            ->diffInDays($to->copy()->setTimezone(self::TZ)->startOfDay(), false);
    }
}
