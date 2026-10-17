<?php

declare(strict_types=1);

namespace App\Services\Occasions;

use App\Services\Telegram\BroadcastDigestComposer;
use Carbon\Carbon;

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
     * Окно событий ближайшего повода в московском времени; null — дат в списке больше нет.
     *
     * @param  array<string, mixed>  $theme
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function window(array $theme, Carbon $at): ?array
    {
        $day = BroadcastDigestComposer::occasionDate($theme, $at);
        if ($day === null) {
            return null;
        }

        // часы от начала дня повода: у «Где встретить Новый год» [18, 30] — с вечера до шести утра
        if (isset($theme['hours'])) {
            [$from, $to] = array_map('intval', (array) $theme['hours']);

            return [$day->copy()->addHours($from), $day->copy()->addHours($to)];
        }

        return [
            $day->copy()->subDays((int) ($theme['window_before'] ?? 0))->startOfDay(),
            $day->copy()->addDays((int) ($theme['window_after'] ?? 0))->endOfDay(),
        ];
    }

    /**
     * Слова повода в названии или в начале описания, слова-исключения в названии и темы из `kinds`.
     * Слово ищется с начала слова, иначе «елк» находится внутри «Щелкунчика».
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder  $q
     * @param  array<string, mixed>  $theme
     * @param  string  $table  имя или псевдоним таблицы событий в запросе
     */
    public static function applyWords($q, array $theme, string $table): void
    {
        $words = array_values(array_filter(array_map('strval', (array) ($theme['words'] ?? []))));
        if ($words !== []) {
            $q->where(function ($w) use ($words, $table) {
                foreach ($words as $word) {
                    $w->orWhere($table.'.title', '~*', self::wordStart($word))
                        ->orWhereRaw('left('.$table.'.description, 300) ~* ?', [self::wordStart($word)]);
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
