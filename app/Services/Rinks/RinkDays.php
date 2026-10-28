<?php

declare(strict_types=1);

namespace App\Services\Rinks;

use App\Models\Rink;
use Carbon\CarbonImmutable;

/** Что на катке в конкретный день: сеансы массового катания, предупреждение об оттепели или почему катания нет. */
final class RinkDays
{
    public const NO_SKATING = 'в этот день массового катания нет';

    /** Выше этой температуры днём лёд под открытым небом подтаивает, если он не искусственный. */
    private const THAW_MAX = 1;

    /**
     * Закрытым каток бывает только по своим словам (`status`), прогноз погоды даёт лишь предупреждение:
     * многие уличные катки с искусственным льдом работают и в плюс.
     *
     * @param  array<string, list<array{t: string, url: ?string}>>  $dated  сеансы по датам из касс и таблиц катка
     * @return array{open: bool, slots: list<array{t: string, url: ?string}>, caution: ?string, closed: bool, why: ?string}
     */
    public static function day(Rink $rink, CarbonImmutable $date, ?int $tMax, array $dated = []): array
    {
        $none = fn (?string $why, bool $closed = false) => ['open' => false, 'slots' => [], 'caution' => null, 'closed' => $closed, 'why' => $why];

        if ($rink->status === 'closed') {
            return $none($rink->status_note, true);
        }
        if ($rink->status !== 'open') {
            return $none($rink->status_note);
        }

        $iso = $date->toDateString();
        foreach ((array) $rink->closures as $c) {
            if (($c['date'] ?? null) === $iso) {
                return $none((string) ($c['note'] ?? self::NO_SKATING));
            }
        }

        $slots = $dated[$iso] ?? self::manual($rink, $iso) ?? self::weekly($rink, $date);
        if ($slots === []) {
            return $none(self::NO_SKATING);
        }

        $thaw = ! $rink->indoor && $rink->ice !== 'artificial' && $tMax !== null && $tMax > self::THAW_MAX;

        return [
            'open' => true,
            'slots' => $slots,
            'caution' => $thaw ? ($rink->ice === 'natural' ? 'лёд натуральный, в оттепель может не работать' : 'в оттепель может не работать') : null,
            'closed' => false,
            'why' => null,
        ];
    }

    /**
     * Ближайший день с катанием после `from`, не дальше трёх недель.
     *
     * @param  array<string, list<array{t: string, url: ?string}>>  $dated
     * @return array{date: string, time: string}|null
     */
    public static function next(Rink $rink, CarbonImmutable $from, array $dated = []): ?array
    {
        for ($i = 1; $i <= 21; $i++) {
            $d = $from->addDays($i);
            $day = self::day($rink, $d, null, $dated);
            if ($day['open']) {
                return ['date' => $d->toDateString(), 'time' => explode('–', $day['slots'][0]['t'])[0]];
            }
        }

        return null;
    }

    /** @return list<array{t: string, url: ?string}>|null */
    private static function manual(Rink $rink, string $iso): ?array
    {
        foreach ((array) $rink->sessions as $s) {
            if (($s['date'] ?? null) === $iso) {
                return array_map(fn ($t) => ['t' => (string) $t, 'url' => null], (array) ($s['times'] ?? []));
            }
        }

        return null;
    }

    /** @return list<array{t: string, url: ?string}> */
    private static function weekly(Rink $rink, CarbonImmutable $date): array
    {
        $out = [];
        foreach ((array) $rink->schedule as $row) {
            if (in_array($date->isoWeekday(), array_map('intval', (array) ($row['days'] ?? [])), true)) {
                foreach ((array) ($row['times'] ?? []) as $t) {
                    $out[] = ['t' => (string) $t, 'url' => null];
                }
            }
        }

        return $out;
    }
}
