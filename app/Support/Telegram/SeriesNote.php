<?php

declare(strict_types=1);

namespace App\Support\Telegram;

use Carbon\CarbonImmutable;

/**
 * Строка про серию дат для поста — то же правило, что seriesShape.ts и seriesNote на сайте:
 * меняешь формулировку там — поменяй здесь, иначе пост и страница события скажут о серии по-разному.
 * Серию в один день («сегодня ещё 3 сеанса») пост не пишет: день уже назван в строке времени.
 */
final class SeriesNote
{
    private const TZ = 'Europe/Moscow';

    private const MONTHS_GEN = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    /**
     * @param  array<string, mixed>  $group  поля group_* после EventRepository::hydrateSeriesDates: они считаны от $now, а пост выходит в $asOf
     */
    public static function of(array $group, CarbonImmutable $asOf, CarbonImmutable $now): ?string
    {
        $today = $asOf->setTimezone(self::TZ)->toDateString();
        $serverToday = $now->setTimezone(self::TZ)->toDateString();

        // выставка идёт до декабря, а сеансы выложены на месяц: известные дни соврали бы о конце
        $until = substr((string) ($group['until'] ?? ''), 0, 10);
        if ($until !== '' && $until >= $today && $until > substr((string) ($group['last_day'] ?? ''), 0, 10)) {
            return 'идёт до '.self::human($until);
        }

        $days = [];
        $passedBeforePost = 0;
        foreach ($group['dates'] ?? [] as $d) {
            $date = self::dayOf($d);
            if ($date === null) {
                continue;
            }
            if ($date < $today) {
                $passedBeforePost += $date >= $serverToday ? 1 : 0;

                continue;
            }
            $days[] = ['date' => $date, 'sessions' => self::sessions($d)];
        }

        $daily = ($group['series_kind'] ?? null) === 'daily' && (int) ($group['count'] ?? 0) >= 5;
        $daysTotal = max((int) ($group['days_count'] ?? 0) - $passedBeforePost, count($days));
        if ($days === [] || $daysTotal <= 1) {
            return $daily ? 'идёт каждый день' : null;
        }

        $first = $days[0]['date'];
        $lastShown = $days[count($days) - 1]['date'];
        $lastDay = isset($group['last_day']) ? substr((string) $group['last_day'], 0, 10) : null;
        $last = $lastDay !== null && $lastDay >= $lastShown ? $lastDay : $lastShown;

        $solid = true;
        for ($i = 1; $i < count($days); $i++) {
            $solid = $solid && self::daysBetween($days[$i - 1]['date'], $days[$i]['date']) === 1;
        }
        // сервер режет список дней: подряд, только если ряд закрывает всё расстояние до последнего дня
        $solid = $solid && (count($days) >= $daysTotal || self::daysBetween($first, $last) === $daysTotal - 1);

        $perDay = array_column($days, 'sessions');
        $total = array_sum($perDay);
        $span = self::daysBetween($first, $last) + 1;

        if ($daysTotal >= 3 && $span <= 31 && $daysTotal / $span >= 0.3) {
            $note = match (true) {
                $solid && $daysTotal >= 5 => 'каждый день до '.self::human($last),
                $solid => "{$daysTotal} ".self::plural($daysTotal, 'день', 'дня', 'дней').' подряд, до '.self::human($last),
                default => "{$daysTotal} ".self::plural($daysTotal, 'день', 'дня', 'дней').' до '.self::human($last),
            };
            if (max($perDay) > 1) {
                $note .= ", всего {$total} ".self::plural($total, 'сеанс', 'сеанса', 'сеансов');
            }

            return $note;
        }

        if ($solid && $daysTotal === 2) {
            return substr($first, 5, 2) === substr($last, 5, 2)
                ? 'два дня подряд, '.(int) substr($first, 8, 2).'–'.self::human($last)
                : 'два дня подряд, '.self::human($first).' и '.self::human($last);
        }

        return "{$daysTotal} ".self::plural($daysTotal, 'показ', 'показа', 'показов')
            .' с '.self::human($first).' по '.self::human($last);
    }

    /** @param  array<string, mixed>  $d */
    private static function dayOf(array $d): ?string
    {
        if (! empty($d['start_date'])) {
            return substr((string) $d['start_date'], 0, 10);
        }
        if (! empty($d['start_at'])) {
            return CarbonImmutable::parse((string) $d['start_at'])->setTimezone(self::TZ)->toDateString();
        }

        return null;
    }

    /** @param  array<string, mixed>  $d */
    private static function sessions(array $d): int
    {
        if ((int) ($d['day_count'] ?? 0) > 1) {
            return (int) $d['day_count'];
        }

        return max(1, count($d['day_times'] ?? []));
    }

    private static function daysBetween(string $a, string $b): int
    {
        return (int) round((strtotime($b.' 00:00:00 UTC') - strtotime($a.' 00:00:00 UTC')) / 86400);
    }

    private static function human(string $date): string
    {
        return (int) substr($date, 8, 2).' '.self::MONTHS_GEN[(int) substr($date, 5, 2)];
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $a = abs($n) % 100;
        $b = $a % 10;

        return match (true) {
            $a > 10 && $a < 20 => $many,
            $b === 1 => $one,
            $b >= 2 && $b <= 4 => $few,
            default => $many,
        };
    }
}
