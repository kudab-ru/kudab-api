<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Event;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Слова и сочетания из названий будущих событий и имён площадок города. Из них сайт дописывает
 * набираемое в поиске слово, поэтому любой вариант что-то находит.
 */
final class SearchVocabulary
{
    // Афиша за десять минут почти не меняется.
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

    // Формы с такими окончаниями в подсказке звучат странно («музыкальном»): берём другую форму, если она есть.
    private const OBLIQUE = ['ого', 'его', 'ому', 'ему', 'ыми', 'ими', 'ами', 'ями', 'ом', 'ем', 'ым', 'им', 'ах', 'ях', 'ов', 'ев'];

    // Снимается одно, самое длинное окончание: «выставка», «выставки» и «выставке» сводятся в одно слово.
    private const ENDINGS = [
        'ого', 'его', 'ому', 'ему', 'ыми', 'ими', 'ами', 'ями',
        'ая', 'яя', 'ое', 'ее', 'ые', 'ие', 'ый', 'ий', 'ой', 'ей', 'ов', 'ев',
        'ам', 'ям', 'ах', 'ях', 'ом', 'ем', 'ию', 'ия',
        'а', 'я', 'ы', 'и', 'у', 'ю', 'е', 'ь', 'й',
    ];

    /**
     * Словарь города: формы слова сведены, у каждого слова и сочетания самая частая форма
     * и число названий. Сочетание — только встреченное хотя бы в двух разных названиях:
     * поиск ищет фразу целиком, и случайная пара не нашла бы ничего.
     *
     * @return array{words: list<array{0: string, 1: int}>, pairs: list<array{0: string, 1: int}>}
     */
    public static function forCity(int $cityId): array
    {
        return Cache::remember("search-vocab:v2:$cityId", self::TTL, function () use ($cityId) {
            $vocab = self::build($cityId);
            $sorted = function (array $items): array {
                $items = array_values($items);
                usort($items, fn ($a, $b) => $b[1] <=> $a[1]);

                return $items;
            };

            return ['words' => $sorted($vocab['words']), 'pairs' => $sorted($vocab['pairs'])];
        });
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
            foreach ($seq as $i => [$word]) {
                if (! self::useful($word)) {
                    continue;
                }
                $stem = self::stem(self::key($word));
                if (! isset($seenWord[$stem])) {
                    $seenWord[$stem] = true;
                    $forms[$stem][$word] = ($forms[$stem][$word] ?? 0) + 1;
                }
                // с косвенной формы сочетание не начинают: «музыкальном фестивале» обрывок фразы
                [$next, $joined] = $seq[$i + 1] ?? [null, false];
                if ($next === null || ! $joined || ! self::useful($next) || self::oblique($word)) {
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
     * Из форм одного слова — самая частая не косвенная, с суммой по всем формам.
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
            $texts = array_map('strval', array_keys($forms));
            usort($texts, fn ($a, $b) => [self::oblique($a), $forms[$b]] <=> [self::oblique($b), $forms[$a]]);
            $out[self::key($texts[0])] = [$texts[0], $n];
        }

        return $out;
    }

    private static function oblique(string $text): bool
    {
        $last = (string) preg_replace('~^.*\s~u', '', $text);
        foreach (self::OBLIQUE as $end) {
            if (str_ends_with($last, $end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Слова строчными, дефис внутри слова остаётся: «мастер-класс» одно слово. Второе в паре —
     * отделено ли слово от прошлого одним пробелом: через кавычку, точку или цифру сочетание
     * не тянем, «Спектакль «Любовь и голуби»» не даёт «спектакль любовь».
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function words(string $text): array
    {
        $lower = mb_strtolower($text);
        preg_match_all('~\p{L}+(?:-\p{L}+)*~u', $lower, $m, PREG_OFFSET_CAPTURE);
        $out = [];
        $end = null;
        foreach ($m[0] as [$word, $at]) {
            $joined = $end !== null && preg_match('~^ +$~u', substr($lower, $end, $at - $end)) === 1;
            $out[] = [$word, $joined];
            $end = $at + strlen($word);
        }

        return $out;
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
