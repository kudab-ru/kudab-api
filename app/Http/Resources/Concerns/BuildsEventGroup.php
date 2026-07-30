<?php

namespace App\Http\Resources\Concerns;

/**
 * Общий сбор поля `group` (ось A: одно событие × N дат) для ленты и детальной.
 * Источник данных — атрибуты group_dates / group_count / group_series, которые
 * навешивает EventRepository::hydrateGroupDates(). Формат ОБЯЗАН быть один:
 * фронт читает group одинаково в карточке и на странице события.
 */
trait BuildsEventGroup
{
    protected function eventGroupIdForPayload(): int
    {
        $gid = (int) ($this->event_group_id ?? 0);

        return $gid > 0 ? $gid : 0;
    }

    protected function eventGroupPayload(): ?array
    {
        $gid = $this->eventGroupIdForPayload();
        if ($gid <= 0) {
            return null;
        }

        $dates = $this->getAttribute('group_dates');

        if (is_string($dates)) {
            $decoded = json_decode($dates, true);
            $dates = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($dates)) $dates = null;

        $count = $this->getAttribute('group_count');
        $count = is_numeric($count) ? (int) $count : ($dates ? count($dates) : 0);

        // Показываем group только если реально есть “серия”
        if (!$dates || $count < 2) {
            return null;
        }

        $days = $this->getAttribute('group_days_count');
        $days = is_numeric($days) ? (int) $days : count($dates);

        $group = [
            'id'         => $gid,
            'count'      => $count,
            'days_count' => $days,
            'dates'      => $dates,
        ];

        // вид повторения (регулярные события PR3): фронт строит фразу
        // «по средам в 16:00» / «ежедневно до 10 июля» / «в репертуаре»
        $series = $this->getAttribute('group_series');
        if (is_array($series) && !empty($series['kind'])) {
            $group['series'] = $series;
        }

        return $group;
    }
}
