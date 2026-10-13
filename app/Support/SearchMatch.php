<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Правило поиска по словам, общее для событий и площадок: что считается совпадением
 * и какое слово считается похожим.
 */
final class SearchMatch
{
    // У слов короче пяти букв похожими выходят чужие слова: «квиз» и «квинтет» дают 0.6.
    private const FUZZY_MIN_LEN = 5;

    // Другая форма слова: «выставка» и «выставке» дают 0.78, а слова, у которых
    // совпадает только начало («концерт» и «конца»), — 0.5.
    private const FORM_THRESHOLD = 0.6;

    // Опечатку ловит сравнение слов целиком: «консерт» и «концерт» дают 0.45, «мафия» и «магия» — 0.33.
    private const TYPO_THRESHOLD = 0.45;

    // Одно и то же люди называют по-разному. Если слово запроса начинается с основы
    // из группы, поиск ищет и остальные основы этой группы.
    // В названиях дефис остаётся («Stand-up»), а в запросе он становится пробелом, поэтому оба написания.
    private const SYNONYMS = [
        ['настолк', 'настольн', 'игротек'],
        ['квест', 'quest'],
        ['стендап', 'stand up', 'stand-up', 'standup'],
    ];

    // Запрос из одного слова, которое называет вид места. Виды — значения `venues.kind`.
    private const KIND_WORDS = [
        'Парки' => '(парк|сквер)(и|ы|ов|а|е|у)?',
        'Музеи' => 'музе(й|и|я|е|ев)',
        'Кинотеатры' => 'кино|кинотеатр(ы|ов|а|е)?',
        'Квесты' => 'квест(ы|ов|а|е)?',
        'Клубы и бары' => '(бар|клуб|паб)(ы|ов|а|е)?',
    ];

    private static ?bool $trgm = null;

    /** @param list<string> $likes */
    private function __construct(
        public readonly string $text,
        private readonly array $likes,
        private readonly string $token,
        private readonly bool $fuzzy,
    ) {}

    /** null — искать нечего: строка пустая или из одних знаков. */
    public static function from(?string $query): ?self
    {
        $text = self::normalize($query);
        if ($text === null) {
            return null;
        }

        $token = self::longestWord($text);

        return new self($text, self::likesFor($text), $token, mb_strlen($token) >= self::FUZZY_MIN_LEN && self::trgmEnabled());
    }

    /**
     * В колонке есть слово запроса или основа из его группы синонимов.
     *
     * @return array{0: string, 1: list<string>} [sql, параметры]
     */
    public function like(string $column): array
    {
        return ['('.implode(' OR ', array_fill(0, count($this->likes), "$column LIKE ?")).')', $this->likes];
    }

    /**
     * В колонке есть похожее слово: другая форма или опечатка. null — у короткого запроса
     * похожие слова не ищутся.
     *
     * @return array{0: string, 1: list<mixed>}|null [sql, параметры]
     */
    public function similar(string $column): ?array
    {
        if (! $this->fuzzy) {
            return null;
        }

        return [
            "(word_similarity(?, $column) >= ? OR strict_word_similarity(?, $column) >= ?)",
            [$this->token, self::FORM_THRESHOLD, $this->token, self::TYPO_THRESHOLD],
        ];
    }

    /** Вид места, который называет весь запрос: «парк», «музеи», «бары». null — запрос не про вид. */
    public function venueKind(): ?string
    {
        foreach (self::KIND_WORDS as $kind => $words) {
            if (preg_match('~^(?:'.$words.')$~u', $this->text)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Совпадение по имени места с поправкой на вид: по «парк» подходит любой парк, а место другого
     * вида не подходит, даже если слово есть в имени (кинотеатр «Синема Парк»).
     *
     * @param  list<mixed>  $args
     * @return array{0: string, 1: list<mixed>} [sql, параметры]
     */
    public function place(string $byName, array $args, string $kindColumn): array
    {
        $kind = $this->venueKind();
        if ($kind === null) {
            return ["($byName)", $args];
        }

        return ["((($byName) AND COALESCE($kindColumn, '') IN ('', ?)) OR $kindColumn = ?)", [...$args, $kind, $kind]];
    }

    private static function normalize(?string $query): ?string
    {
        $q = mb_strtolower(trim((string) $query));
        $q = str_replace(['ё', '-'], ['е', ' '], $q);
        $q = preg_replace('~[^\p{L}\p{N}\s]+~u', ' ', $q);
        $q = trim(preg_replace('~\s+~u', ' ', $q));

        return $q !== '' ? $q : null;
    }

    private static function longestWord(string $text): string
    {
        $token = '';
        foreach (explode(' ', $text) as $word) {
            if (mb_strlen($word) > mb_strlen($token)) {
                $token = $word;
            }
        }

        return $token;
    }

    /** @return list<string> */
    private static function likesFor(string $text): array
    {
        $likes = ['%'.$text.'%'];
        foreach (self::SYNONYMS as $group) {
            foreach ($group as $stem) {
                if (preg_match('~(?:^|\s)'.preg_quote($stem, '~').'~u', $text)) {
                    foreach ($group as $synonym) {
                        $likes[] = '%'.$synonym.'%';
                    }
                    break;
                }
            }
        }

        return array_values(array_unique($likes));
    }

    /** На базе без pg_trgm (свежий стенд, чужая база) поиск остаётся строгим, а не падает. */
    private static function trgmEnabled(): bool
    {
        if (self::$trgm === null) {
            try {
                DB::selectOne("select strict_word_similarity('a', 'a') as s");
                self::$trgm = true;
            } catch (\Throwable) {
                self::$trgm = false;
            }
        }

        return self::$trgm;
    }
}
