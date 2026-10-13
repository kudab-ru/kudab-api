<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Event;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Слова и сочетания из названий будущих событий и имён площадок города. Из них автодополнение
 * поиска дописывает набранное слово, поэтому любой вариант что-то находит.
 */
final class SearchVocabulary
{
    // Подсказки зовутся на каждую паузу в наборе, а афиша за десять минут почти не меняется.
    private const TTL = 600;

    // Сочетание из одного названия ещё не сочетание, а кусок этого названия.
    private const PAIR_MIN = 2;

    // Служебные слова дописывать незачем, а в сочетания они склеивают случайные пары.
    private const STOP = [
        'для', 'при', 'про', 'под', 'над', 'без', 'или', 'как', 'что', 'это', 'все', 'всё', 'его',
        'она', 'они', 'так', 'уже', 'еще', 'ещё', 'где', 'кто', 'чем', 'через', 'после', 'перед',
        'между', 'только', 'также', 'будет', 'наш', 'ваш', 'наша', 'ваша', 'наши', 'ваши', 'этот',
        'эта', 'эти', 'тот', 'том', 'той', 'год', 'года', 'днём', 'днем',
    ];

    // Снимается одно, самое длинное окончание: «выставка», «выставки» и «выставке» сводятся в одно слово.
    private const ENDINGS = [
        'ого', 'его', 'ому', 'ему', 'ыми', 'ими', 'ами', 'ями',
        'ая', 'яя', 'ое', 'ее', 'ые', 'ие', 'ый', 'ий', 'ой', 'ей', 'ов', 'ев',
        'ам', 'ям', 'ах', 'ях', 'ом', 'ем', 'ию', 'ия',
        'а', 'я', 'ы', 'и', 'у', 'ю', 'е', 'ь', 'й',
    ];

    /**
     * Варианты для набранного, частые первыми: одно слово дописывается словом или сочетанием,
     * второе — только до сочетания из афиши. Поиск ищет фразу целиком, и выдуманная пара
     * («театр кулаков») не нашла бы ничего. Третье слово не дописываем по той же причине.
     *
     * @return list<string>
     */
    public static function complete(int $cityId, string $typed, int $limit = 6): array
    {
        $words = self::words($typed);
        if ($words === [] || count($words) > 2 || preg_match('~\s$~u', $typed)) {
            return [];
        }
        $prefix = self::key((string) array_pop($words));
        if (mb_strlen($prefix) < 2) {
            return [];
        }
        $prev = $words === [] ? null : self::stem(self::key($words[0]));

        $vocab = Cache::remember("search-vocab:$cityId", self::TTL, fn () => self::build($cityId));
        $found = [];
        foreach ($vocab['pairs'] as $key => [$text, $n]) {
            [$first, $second] = explode(' ', $key, 2);
            $fits = $prev === null
                ? str_starts_with($key, $prefix)
                : self::stem($first) === $prev && str_starts_with($second, $prefix);
            if ($fits) {
                $found[$text] = $n;
            }
        }
        if ($prev === null) {
            foreach ($vocab['words'] as $key => [$text, $n]) {
                if ($key !== $prefix && str_starts_with($key, $prefix)) {
                    $found[$text] ??= $n;
                }
            }
        }
        arsort($found);

        return array_slice(array_map('strval', array_keys($found)), 0, $limit);
    }

    /** @return array{words: array<string, array{0: string, 1: int}>, pairs: array<string, array{0: string, 1: int}>} */
    private static function build(int $cityId): array
    {
        $now = CarbonImmutable::now('Europe/Moscow');
        // Видимость как у поиска словом: детское и семейное он показывает, и дописывать их тоже можно.
        $titles = Event::query()
            ->where('events.city_id', $cityId)
            ->where('events.status', 'active')
            ->whereNull('events.deleted_at')
            ->webNotBlacklisted()
            ->webFeedContentKind()
            ->where(function ($w) use ($now) {
                $w->where('events.start_time', '>=', $now->format('Y-m-d H:i:sP'))
                    ->orWhere(fn ($x) => $x->whereNull('events.start_time')->where('events.start_date', '>=', $now->toDateString()));
            })
            ->get(['events.id', 'events.event_group_id', 'events.title'])
            ->unique(fn (Event $e) => $e->event_group_id ?? 'e'.$e->id)
            // одно название на двух площадках не делает из своего куска сочетание
            ->unique(fn (Event $e) => mb_strtolower((string) $e->title))
            ->pluck('title');
        $names = Venue::query()->active()->where('venues.city_id', $cityId)->pluck('name');

        $forms = [];
        $pairs = [];
        foreach ([...$titles, ...$names] as $title) {
            $seq = self::words((string) $title);
            $seenWord = [];
            $seenPair = [];
            foreach ($seq as $i => $word) {
                if (! self::useful($word)) {
                    continue;
                }
                $stem = self::stem(self::key($word));
                if (! isset($seenWord[$stem])) {
                    $seenWord[$stem] = true;
                    $forms[$stem][$word] = ($forms[$stem][$word] ?? 0) + 1;
                }
                $next = $seq[$i + 1] ?? null;
                if ($next === null || ! self::useful($next)) {
                    continue;
                }
                $pair = $stem.' '.self::stem(self::key($next));
                if (! isset($seenPair[$pair])) {
                    $seenPair[$pair] = true;
                    $pairs[$pair]["$word $next"] = ($pairs[$pair]["$word $next"] ?? 0) + 1;
                }
            }
        }

        return ['words' => self::pick($forms, 1), 'pairs' => self::pick($pairs, self::PAIR_MIN)];
    }

    /**
     * Из форм одного слова — самая частая, с суммой по всем формам.
     *
     * @param  array<string, array<string, int>>  $groups
     * @return array<string, array{0: string, 1: int}>
     */
    private static function pick(array $groups, int $min): array
    {
        $out = [];
        foreach ($groups as $forms) {
            $n = array_sum($forms);
            if ($n < $min) {
                continue;
            }
            arsort($forms);
            $text = (string) array_key_first($forms);
            $out[self::key($text)] = [$text, $n];
        }

        return $out;
    }

    /** @return list<string> слова строчными, дефис внутри слова остаётся: «мастер-класс» одно слово */
    private static function words(string $text): array
    {
        preg_match_all('~\p{L}+(?:-\p{L}+)*~u', mb_strtolower($text), $m);

        return $m[0];
    }

    private static function useful(string $word): bool
    {
        return mb_strlen($word) >= 3 && ! in_array($word, self::STOP, true);
    }

    private static function key(string $word): string
    {
        return str_replace('ё', 'е', $word);
    }

    private static function stem(string $word): string
    {
        foreach (self::ENDINGS as $end) {
            if (str_ends_with($word, $end) && mb_strlen($word) - mb_strlen($end) >= 3) {
                return mb_substr($word, 0, mb_strlen($word) - mb_strlen($end));
            }
        }

        return $word;
    }
}
