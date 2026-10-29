<?php

declare(strict_types=1);

namespace App\Services\Rinks;

use App\Models\Rink;
use Carbon\CarbonImmutable;

/** Что на катке в конкретный день: сеансы массового катания, предупреждение об оттепели или почему катания нет. */
final class RinkDays
{
    public const NO_SKATING = 'в этот день массового катания нет';

    public const HOLIDAY_NOTE = 'праздник, часы могут отличаться';

    /** Выше этой температуры днём лёд под открытым небом подтаивает, если он не искусственный. */
    private const THAW_MAX = 1;

    /** Нерабочие дни зимы по постановлению о переносе выходных; список обновлять раз в год, к ноябрю. */
    public const HOLIDAYS = [
        '2026-11-04', '2026-12-31', '2027-01-01', '2027-01-02', '2027-01-03', '2027-01-04', '2027-01-05',
        '2027-01-06', '2027-01-07', '2027-01-08', '2027-01-09', '2027-01-10', '2027-02-22', '2027-02-23', '2027-03-08',
    ];

    /**
     * Закрытым каток бывает только по своим словам (`status`), погода даёт лишь предупреждение: уличные катки
     * с искусственным льдом работают и в плюс. Праздничный будний день идёт по воскресенью, а у катка без
     * воскресных сеансов по своему дню, с пометкой `holiday`, пока часы на дату не пришли из кассы или админки.
     *
     * @param  array<string, list<array{t: string, url: ?string}>>  $dated  сеансы по датам из касс и таблиц катка
     * @return array{open: bool, slots: list<array{t: string, url: ?string}>, caution: ?string, holiday: ?string, closed: bool, why: ?string}
     */
    public static function day(Rink $rink, CarbonImmutable $date, ?int $tMax, array $dated = []): array
    {
        $none = fn (?string $why, bool $closed = false) => ['open' => false, 'slots' => [], 'caution' => null, 'holiday' => null, 'closed' => $closed, 'why' => $why];

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

        $known = $dated[$iso] ?? self::manual($rink, $iso);
        $holiday = in_array($iso, self::HOLIDAYS, true);
        $dow = $date->isoWeekday();
        $slots = $known ?? ($holiday && $dow < 6 ? (self::weekly($rink, 7) ?: self::weekly($rink, $dow)) : self::weekly($rink, $dow));
        if ($slots === []) {
            return $none(self::NO_SKATING);
        }

        $thaw = ! $rink->indoor && $rink->ice !== 'artificial' && $tMax !== null && $tMax > self::THAW_MAX;

        return [
            'open' => true,
            'slots' => $slots,
            'caution' => $thaw ? ($rink->ice === 'natural' ? 'лёд натуральный, в оттепель может не работать' : 'в оттепель может не работать') : null,
            'holiday' => $holiday && $known === null ? self::HOLIDAY_NOTE : null,
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
    private static function weekly(Rink $rink, int $isoWeekday): array
    {
        $out = [];
        foreach ((array) $rink->schedule as $row) {
            if (in_array($isoWeekday, array_map('intval', (array) ($row['days'] ?? [])), true)) {
                foreach ((array) ($row['times'] ?? []) as $t) {
                    $out[] = ['t' => (string) $t, 'url' => null];
                }
            }
        }

        return $out;
    }
}
