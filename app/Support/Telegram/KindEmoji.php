<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Значок темы события для поста ленты и строки подборки. Подавление «тема названа
 * словом» решает вызывающий: пост включает, подборка нет (см. EventCaptionBuilder::kindEmoji,
 * BroadcastDigestComposer::emojiFor).
 */
final class KindEmoji
{
    /**
     * Порядок ключей не влияет, выбирает rank (см. interestEmoji).
     * Значки должны различаться на телефоне: 🎶 рядом с 🎵 не отличить.
     * У 🖼️ и 🏛️ нужен U+FE0F, иначе часть клиентов рисует их чёрно-белыми.
     */
    private const INTEREST_EMOJI = [
        // Музыкальные жанры — подвиды темы `music` (см. MUSIC_GENRES).
        'jazz' => '🎷',
        'rock' => '🎸',
        'classical' => '🎻',
        'opera-ballet' => '🩰',
        'electronic' => '🎧',
        'pop' => '🎵',
        // Всё остальное — уже конкретные темы.
        'theatre' => '🎭',
        'standup' => '🎤',
        'cinema' => '🎬',
        'exhibitions' => '🖼️',
        'literature' => '📖',
        'quiz-games' => '🧩',
        'parties' => '🪩',
        'circus-show' => '🎪',
        'festival' => '🎊',
        'excursions' => '🚶',
        'kids' => '🧸',
        'sport' => '🏃',
        'science' => '🔬',
        'workshops' => '🎨',
        'yoga-wellness' => '🧘',
        'city' => '🏛️',
        // Широкие темы.
        'education' => '🎓',
        'music' => '🎵',
    ];

    /**
     * Стемы названия, при которых значок этой темы в посте лишний (см. titleNamesTheme).
     */
    private const THEME_SAID_IN_TITLE = [
        '🎭' => ['спектакл', 'театр', 'моноспектакл'],
        '🎵' => ['концерт', 'музык', 'оркестр', 'квартирник'],
        '🎷' => ['джаз'],
        '🎸' => ['рок-', 'панк', 'метал'],
        '🎻' => ['симфон', 'классик', 'камерн', 'филармон', 'орган'],
        '🩰' => ['балет', 'опера', 'мюзикл', 'оперетт'],
        '🎤' => ['стендап', 'стенд-ап', 'открытый микрофон', 'камеди'],
        '🖼️' => ['выставк', 'экспозиц', 'вернисаж'],
        '🎬' => ['кинопоказ', 'киносеанс', 'кинопремьер', 'кинофестивал'],
        '🧩' => ['квест', 'квиз', 'игротек', 'настолк'],
        '🚶' => ['экскурс', 'прогулк'],
        '🎓' => ['лекци', 'семинар', 'лекторий', 'мастер-класс', 'мастер класс', 'мастерск'],
        '📖' => ['поэтическ', 'поэзи', 'литератур', 'книжн'],
        '🏃' => ['забег', 'марафон', 'турнир', 'чемпионат'],
        '🧸' => ['детск', 'для детей', 'малыш'],
        '🪩' => ['вечеринк', 'дискотек'],
        '🎪' => ['цирк'],
        '🎊' => ['фестивал'],
        '🎨' => ['мастер-класс', 'мастер класс'],
    ];

    /** Подвиды темы `music`: только они могут её вытеснить. */
    private const MUSIC_GENRES = [
        'jazz' => true,
        'rock' => true,
        'classical' => true,
        'opera-ballet' => true,
        'electronic' => true,
        'pop' => true,
    ];

    /**
     * Сначала темы: слова названия и content_kind — запас, если темы значка не дали.
     * Значка по умолчанию нет: случайный хуже пустого (✨ у панихиды).
     *
     * @param  array<string, mixed>  $raw
     */
    public static function for(array $raw, bool $suppressWhenTitleSaysIt = true): string
    {
        $title = mb_strtolower((string) ($raw['title'] ?? $raw['name'] ?? ''));

        $byInterest = self::interestEmoji($raw['interest_slugs'] ?? null);
        if ($byInterest !== '') {
            return $suppressWhenTitleSaysIt && self::titleNamesTheme($title, $byInterest) ? '' : $byInterest;
        }

        $byWord = [
            '🎭' => ['спектакл', 'театр', 'моноспектакл', 'премьер'],
            '🎵' => ['концерт', 'джаз', 'рок-', 'симфон', 'оркестр', 'квартирник'],
            '🎤' => ['стендап', 'открытый микрофон', 'караоке'],
            '🖼️' => ['выставк', 'экспозиц', 'галере', 'вернисаж'],
            '🎬' => ['кинопоказ', 'киноклуб', 'фильм', 'кино'],
            '🧩' => ['квест', 'игротек', 'настолк', 'квиз'],
            '🏃' => ['забег', 'марафон', 'турнир', 'матч', 'чемпионат'],
            '🛍' => ['маркет', 'ярмарк', 'барахолк', 'своп'],
            '🚶' => ['экскурс', 'прогулк'],
            '🧸' => ['для детей', 'детск', 'малыш'],
            '🎓' => ['лекци', 'дискусс', 'мастер-класс'],
        ];

        foreach ($byWord as $emoji => $words) {
            foreach ($words as $word) {
                if ($word !== '' && mb_strpos($title, $word) !== false) {
                    return $emoji;
                }
            }
        }

        return match ((string) ($raw['content_kind'] ?? '')) {
            'culture' => '🎭',
            'education' => '🎓',
            'sport' => '🏃',
            'civic' => '🏛️',
            default => '',
        };
    }

    /**
     * Граница слова только слева: справа у стема меняется окончание («экскурсию»),
     * а без левой «лекци» найдётся внутри «коллекция».
     */
    private static function titleNamesTheme(string $lowerTitle, string $emoji): bool
    {
        foreach (self::THEME_SAID_IN_TITLE[$emoji] ?? [] as $stem) {
            if (preg_match('~(?<![а-яёa-z])'.preg_quote($stem, '~').'~u', $lowerTitle) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Первая тема со значком в порядке rank, не в порядке словаря. Музыку вытесняет жанр
     * (MUSIC_GENRES); другие пары так не подменять: quiz-games не подвид education.
     *
     * @param  mixed  $slugs  слаги в порядке rank: нулевой — главная тема
     */
    private static function interestEmoji(mixed $slugs): string
    {
        if (! is_array($slugs)) {
            return '';
        }

        $ordered = [];
        foreach ($slugs as $slug) {
            if (is_string($slug) && trim($slug) !== '') {
                $ordered[] = mb_strtolower(trim($slug));
            }
        }

        foreach ($ordered as $slug) {
            $emoji = self::INTEREST_EMOJI[$slug] ?? null;
            if ($emoji === null) {
                continue;
            }

            if ($slug === 'music') {
                foreach ($ordered as $other) {
                    // жанр из MUSIC_GENRES может быть без значка, тогда остаётся 🎵
                    $genre = self::INTEREST_EMOJI[$other] ?? '';
                    if ($genre !== '' && isset(self::MUSIC_GENRES[$other])) {
                        return $genre;
                    }
                }
            }

            return $emoji;
        }

        return '';
    }
}
