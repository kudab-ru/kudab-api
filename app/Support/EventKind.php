<?php

namespace App\Support;

use App\Services\Occasions\OccasionCalendar;

/**
 * Вид события: концерт, спектакль, квиз… Тема шире вида: у спектакля с музыкой тема «музыка»,
 * а вид «спектакль». Сначала смотрим слово в названии, без него — главную тему (rank 0).
 */
final class EventKind
{
    // Порядок важен: побеждает первое слово из списка. «Стендап-концерт» — стендап,
    // «лекция-концерт» — лекция, «шоу-спектакль» — спектакль.
    public const WORDS = [
        'market' => ['маркет', 'ярмарк', 'барахолк'],
        'quiz' => ['квиз', 'quiz', 'лото'],
        'party' => ['вечеринк', 'party', 'пати', 'дискотек'],
        'workshop' => ['мастер-класс', 'мастер класс'],
        'excursion' => ['экскурси'],
        'sport' => ['бокс', 'поединок', 'поединки', 'матч', 'хоккей', 'футбол', 'баскетбол', 'волейбол', 'забег', 'единоборств'],
        'standup' => ['стендап', 'stand-up', 'stand up', 'standup'],
        'theatre' => ['спектакл', 'мюзикл', 'комеди'],
        'lecture' => ['лекци'],
        'concert' => ['концерт'],
        'exhibition' => ['выставк'],
        'cinema' => ['киноклуб', 'кинопоказ', 'показ фильм'],
        'festival' => ['фестивал'],
        'show' => ['шоу'],
    ];

    // Тема без своего вида ищется по родителю: `rock` → `music` → концерт.
    public const BY_INTEREST = [
        'parties' => 'party', 'quiz-games' => 'quiz', 'music' => 'concert', 'theatre' => 'theatre', 'kids' => 'kids',
        'exhibitions' => 'exhibition', 'standup' => 'standup', 'education' => 'lecture', 'literature' => 'lecture',
        'excursions' => 'excursion', 'cinema' => 'cinema', 'circus-show' => 'show', 'festival' => 'festival',
        'city' => 'city', 'sport' => 'sport', 'markets' => 'market', 'workshops' => 'workshop',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_unique([...array_keys(self::WORDS), ...array_values(self::BY_INTEREST), 'other']));
    }

    public static function of(string $title, ?string $mainSlug, ?string $mainParent): string
    {
        $title = mb_strtolower($title);
        foreach (self::WORDS as $kind => $words) {
            if (OccasionCalendar::titleNames($title, $words)) {
                return $kind;
            }
        }

        return self::BY_INTEREST[(string) $mainSlug] ?? self::BY_INTEREST[(string) $mainParent] ?? 'other';
    }

    /**
     * То же, что `of`, выражением SQL над строкой `events`: для отбора в ленте с постраничной выдачей.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function sql(): array
    {
        $case = 'CASE';
        $args = [];
        foreach (self::WORDS as $kind => $words) {
            $case .= ' WHEN events.title ~* ? THEN ?';
            $args[] = '(?<![[:alnum:]])('.implode('|', array_map(fn ($w) => preg_quote($w), $words)).')';
            $args[] = $kind;
        }

        $map = str_repeat('WHEN ? THEN ? ', count(self::BY_INTEREST));
        $mapArgs = [];
        foreach (self::BY_INTEREST as $slug => $kind) {
            array_push($mapArgs, $slug, $kind);
        }
        $main = "(SELECT coalesce(CASE i.slug {$map}END, CASE p.slug {$map}END)
            FROM event_interest ei JOIN interests i ON i.id = ei.interest_id LEFT JOIN interests p ON p.id = i.parent_id
            WHERE ei.event_id = events.id ORDER BY ei.rank, ei.interest_id LIMIT 1)";

        return [
            "{$case} ELSE coalesce({$main}, 'other') END",
            [...$args, ...$mapArgs, ...$mapArgs],
        ];
    }
}
