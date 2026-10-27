<?php

declare(strict_types=1);

namespace App\Services\Occasions;

use App\Services\Telegram\BroadcastDigestComposer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Поводы года — рубрики config/broadcast_digest.php с ключом `occasion`. Одни и те же правила отбора
 * событий для поста в канал (BroadcastDigestComposer) и для страницы праздника на сайте (OccasionPage).
 */
final class OccasionCalendar
{
    private const TZ = 'Europe/Moscow';

    /** @return list<array<string, mixed>> */
    public static function themes(): array
    {
        return array_values(array_filter(
            (array) config('broadcast_digest.themes', []),
            fn ($t) => is_array($t) && isset($t['occasion']),
        ));
    }

    /** @return array<string, mixed>|null */
    public static function byPage(string $page): ?array
    {
        foreach (self::themes() as $theme) {
            if (($theme['page'] ?? null) === $page) {
                return $theme;
            }
        }

        return null;
    }

    /**
     * Ближайший повод, окно которого ещё не кончилось: день и окно событий в московском времени.
     * `occasion` — «месяц-день» каждый год, список дат «год-месяц-день» или 'floating' — даты вписывают
     * в календаре админки (таблица occasion_dates). null — дат впереди нет.
     *
     * @param  array<string, mixed>  $theme
     * @return array{day: Carbon, from: Carbon, to: Carbon}|null
     */
    public static function occurrence(array $theme, Carbon $at): ?array
    {
        $at = $at->copy()->setTimezone(self::TZ);
        $raw = $theme['occasion'];
        $dates = match (true) {
            $raw === 'floating' => self::floating((string) $theme['slug']),
            is_array($raw) => array_map(fn ($d) => [(string) $d, null], $raw),
            default => array_map(
                fn (int $year) => [sprintf('%d-%s', $year, $raw), null],
                [$at->year, $at->year + 1],
            ),
        };

        foreach ($dates as [$start, $end]) {
            $day = Carbon::parse($start, self::TZ)->startOfDay();
            [$from, $to] = self::span($theme, $day, $end);
            if ($to->gte($at)) {
                return ['day' => $day, 'from' => $from, 'to' => $to];
            }
        }

        return null;
    }

    /**
     * Окно событий ближайшего повода; null — дат впереди нет.
     *
     * @param  array<string, mixed>  $theme
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function window(array $theme, Carbon $at): ?array
    {
        $o = self::occurrence($theme, $at);

        return $o === null ? null : [$o['from'], $o['to']];
    }

    /** Даты плавающего повода: [начало, конец или null], по порядку. */
    public static function floating(string $slug): array
    {
        return Cache::remember('occasion-dates:v1:'.$slug, 300, fn () => DB::table('occasion_dates')
            ->where('slug', $slug)
            ->orderBy('starts_on')
            ->get(['starts_on', 'ends_on'])
            ->map(fn ($r) => [(string) $r->starts_on, $r->ends_on !== null ? (string) $r->ends_on : null])
            ->all());
    }

    public static function forgetFloating(string $slug): void
    {
        Cache::forget('occasion-dates:v1:'.$slug);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private static function span(array $theme, Carbon $day, ?string $end): array
    {
        // часы от начала дня повода: у «Где встретить Новый год» [18, 30] — с вечера до шести утра
        if (isset($theme['hours'])) {
            [$from, $to] = array_map('intval', (array) $theme['hours']);

            return [$day->copy()->addHours($from), $day->copy()->addHours($to)];
        }
        // у вписанной в админке даты с концом окно — сами даты: у каникул длина каждый раз своя
        if ($end !== null) {
            return [$day->copy(), Carbon::parse($end, self::TZ)->endOfDay()];
        }

        return [
            $day->copy()->subDays((int) ($theme['window_before'] ?? 0))->startOfDay(),
            $day->copy()->addDays((int) ($theme['window_after'] ?? 0))->endOfDay(),
        ];
    }

    /**
     * Слова повода в названии или в начале описания, слова-исключения в названии и темы из `kinds`.
     * Слово ищется с начала слова, иначе «елк» находится внутри «Щелкунчика». С `source_words` слово
     * ищется и в тексте поста или страницы, откуда пришло событие: программу ночи музей пишет одним
     * постом, а в описаниях её отдельных событий слов праздника нет.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder  $q
     * @param  array<string, mixed>  $theme
     * @param  string  $table  имя или псевдоним таблицы событий в запросе
     */
    public static function applyWords($q, array $theme, string $table): void
    {
        $words = array_values(array_filter(array_map('strval', (array) ($theme['words'] ?? []))));
        if ($words !== []) {
            $inSource = ! empty($theme['source_words']);
            $q->where(function ($w) use ($words, $table, $inSource, $theme) {
                foreach ($words as $word) {
                    $w->orWhere($table.'.title', '~*', self::wordStart($word))
                        ->orWhereRaw('left('.$table.'.description, 300) ~* ?', [self::wordStart($word)]);
                }
                if ($inSource) {
                    $w->orWhereExists(fn ($s) => $s->selectRaw('1')
                        ->from('event_sources as ws')
                        ->join('context_posts as wp', 'wp.id', '=', 'ws.context_post_id')
                        ->whereColumn('ws.event_id', $table.'.id')
                        ->where(function ($t) use ($words) {
                            foreach ($words as $word) {
                                $t->orWhere('wp.text', '~*', self::wordStart($word));
                            }
                        }));
                }
                if (isset($theme['evening_at'])) {
                    $w->orWhere(fn ($e) => self::eveningAt($e, (array) $theme['evening_at'], $table));
                }
            });
        }

        // «Новогодний корпоратив» — предложение для фирм, а не праздник для читателя
        foreach ((array) ($theme['not_words'] ?? []) as $word) {
            $q->where($table.'.title', '!~*', self::wordStart((string) $word));
        }

        // главная тема события внутри деревьев `kinds`: детские «Ёлки» без концерта певицы Ёлки
        $roots = array_map('strval', (array) ($theme['kinds'] ?? []));
        if ($roots !== []) {
            $ids = array_values(array_unique(array_merge(...array_map(
                fn ($slug) => BroadcastDigestComposer::interestTree($slug),
                $roots,
            ))));
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('event_interest as oi')
                ->whereColumn('oi.event_id', $table.'.id')
                ->where('oi.rank', 0)
                ->whereIn('oi.interest_id', $ids === [] ? [0] : $ids));
        }
    }

    /**
     * Вечер в музее или библиотеке в день ночи. Площадка часто пишет «3 ноября с 15 до 23 во всех отделах»
     * без названия акции, а вечером в этот день у неё идёт программа ночи. Окно повода задаёт вызывающий.
     *
     * @param  array{kinds?: list<string>, names?: list<string>}  $places  виды площадок и основы их названий
     */
    private static function eveningAt($q, array $places, string $table): void
    {
        $kinds = array_values(array_map('strval', (array) ($places['kinds'] ?? [])));
        $names = array_values(array_map('strval', (array) ($places['names'] ?? [])));
        if ($kinds === [] && $names === []) {
            $q->whereRaw('false');

            return;
        }
        $local = fn (string $column) => 'EXTRACT(HOUR FROM '.$table.'.'.$column." AT TIME ZONE '".self::TZ."')";

        $q->where(fn ($t) => $t->whereNull($table.'.time_precision')->orWhere($table.'.time_precision', '<>', 'date'))
            ->whereExists(fn ($v) => $v->selectRaw('1')->from('venues as ov')
                ->whereColumn('ov.id', $table.'.venue_id')
                ->where(function ($p) use ($kinds, $names) {
                    if ($kinds !== []) {
                        $p->orWhereIn('ov.kind', $kinds);
                    }
                    if ($names !== []) {
                        // «Музей пива» у бара и квест «Ночь в музее» по имени похожи, но это не музеи
                        $p->orWhere(fn ($n) => $n->where('ov.name', '~*', implode('|', $names))
                            ->where(fn ($k) => $k->whereNull('ov.kind')
                                ->orWhereNotIn('ov.kind', ['Клубы и бары', 'Квесты', 'Кинотеатры', 'Сцены'])));
                    }
                }))
            // многодневная выставка идёт и вечером, но в программу ночи её не ставят
            ->where(fn ($d) => $d->whereNull($table.'.end_time')
                ->orWhereRaw($table.'.end_time - '.$table.".start_time <= interval '12 hours'"))
            ->where(fn ($h) => $h->whereRaw($local('start_time').' >= 18')
                ->orWhereRaw($local('end_time').' >= 21'));
    }

    /** Регулярное выражение базы: слово с начала слова. */
    public static function wordStart(string $word): string
    {
        return '\m'.preg_replace('/([.^$|?*+()\[\]{}\\\\])/', '\\\\$1', $word);
    }

    /** Есть ли слово повода в названии — тем же правилом начала слова. */
    public static function titleNames(string $title, array $words): bool
    {
        foreach ($words as $word) {
            if ($word !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote((string) $word, '/').'/iu', $title)) {
                return true;
            }
        }

        return false;
    }
}
