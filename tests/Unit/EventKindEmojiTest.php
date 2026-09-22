<?php

namespace Tests\Unit;

use App\Services\Telegram\EventCaptionBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Значок по теме события — вместо 🎟, который стоял у всех постов одинаково.
 *
 * Подсмотрено у московского агрегатора: значок поддерживает название
 * («Фонтаны на ВДНХ ⛲»). Подбирается без модели.
 *
 * ПОРЯДОК ИСТОЧНИКОВ: интересы → слова названия → тип события. Словарь по
 * названию стоял первым и покрывал 209 событий из 400: «Побег из тюрьмы» и
 * «Припять 36» — квесты, но слова «квест» в названии нет. Интересы проставлены
 * у 439 из 449 и подняли покрытие до 442.
 *
 * Главное правило — НЕЗНАКОМАЯ ТЕМА ОСТАЁТСЯ БЕЗ ЗНАЧКА. Случайный значок
 * хуже отсутствующего: ✨ у панихиды читается издевательством.
 */
class EventKindEmojiTest extends TestCase
{
    private function emoji(array $raw): string
    {
        $m = (new ReflectionClass(EventCaptionBuilder::class))->getMethod('kindEmoji');
        $m->setAccessible(true);

        return $m->invoke(null, $raw);
    }

    private function priceEmoji(array $raw): string
    {
        $m = (new ReflectionClass(EventCaptionBuilder::class))->getMethod('priceEmoji');
        $m->setAccessible(true);

        return $m->invoke(null, $raw);
    }

    public static function titles(): array
    {
        return [
            'спектакль' => ['Спектакль «Белый Бим Черное Ухо»', '🎭'],
            'концерт' => ['Концерт Губернаторского оркестра', '🎵'],
            'квартирник' => ['Квартирник у Бунина', '🎵'],
            'стендап' => ['Открытый микрофон в баре', '🎤'],
            'выставка' => ['Выставка Клода Моне', '🖼'],
            'киноклуб' => ['Киноклуб: «Донни Дарко»', '🎬'],
            'квест' => ['Квест «Корпорация Монстров»', '🧩'],
            'забег' => ['Ночной забег по набережной', '🏃'],
            'маркет' => ['Маркет локальных мастеров', '🛍'],
            'экскурсия' => ['Экскурсия в Ошка-парк', '🚶'],
            'лекция' => ['Лекция о городской архитектуре', '🎓'],
        ];
    }

    /** @dataProvider titles */
    public function test_emoji_matches_the_title(string $title, string $expected): void
    {
        $this->assertSame($expected, $this->emoji(['title' => $title]));
    }

    /** Ради этого правила всё: случайный значок хуже никакого. */
    public function test_unknown_theme_gets_no_emoji(): void
    {
        $this->assertSame('', $this->emoji(['title' => 'Панихида по жертвам наводнения']));
        $this->assertSame('', $this->emoji(['title' => 'Собрание жильцов дома 14']));
    }

    /** Не угадалось по названию — падаем на тип события. */
    public function test_falls_back_to_content_kind(): void
    {
        $this->assertSame('🎭', $this->emoji(['title' => 'Вечер у камина', 'content_kind' => 'culture']));
        $this->assertSame('🏃', $this->emoji(['title' => 'Вечер у камина', 'content_kind' => 'sport']));
        $this->assertSame('', $this->emoji(['title' => 'Вечер у камина', 'content_kind' => 'entertainment']));
    }

    /** Слово в названии важнее типа: оно точнее. */
    public function test_title_wins_over_content_kind(): void
    {
        $this->assertSame('🖼', $this->emoji(['title' => 'Выставка кошек', 'content_kind' => 'sport']));
    }

    /**
     * Ради чего переписывали: название молчит, а тема известна.
     *
     * Ровно такие события — квесты без слова «квест» — и составляли основную
     * массу постов без значка.
     */
    public function test_interest_wins_over_silent_title(): void
    {
        $this->assertSame('🧩', $this->emoji([
            'title' => 'Припять 36',
            'content_kind' => 'entertainment',
            'interest_slugs' => ['quiz-games'],
        ]));
    }

    /** Интерес сильнее названия даже там, где название что-то подсказывает. */
    public function test_interest_wins_over_title_word(): void
    {
        $this->assertSame('🎷', $this->emoji([
            'title' => 'Концерт в подвале',
            'interest_slugs' => ['jazz'],
        ]));
    }

    /** Узкая тема бьёт широкую независимо от порядка слагов у события. */
    public function test_narrow_interest_wins_over_broad(): void
    {
        $this->assertSame('🎸', $this->emoji(['interest_slugs' => ['music', 'rock']]));
        $this->assertSame('🎸', $this->emoji(['interest_slugs' => ['rock', 'music']]));
        $this->assertSame('🎻', $this->emoji(['interest_slugs' => ['music', 'classical']]));
    }

    /** Широкая тема всё равно лучше пустоты, если узкой не проставили. */
    public function test_broad_interest_is_better_than_nothing(): void
    {
        $this->assertSame('🎵', $this->emoji(['interest_slugs' => ['music']]));
    }

    /** Неизвестный слаг не должен подменять собой правило «лучше пусто». */
    public function test_unknown_interest_falls_through(): void
    {
        $this->assertSame('', $this->emoji(['title' => 'Панихида', 'interest_slugs' => ['taxidermy']]));
        $this->assertSame('🎭', $this->emoji(['title' => 'Спектакль', 'interest_slugs' => ['taxidermy']]));
    }

    /** Пустые и кривые значения не роняют подпись. */
    public function test_empty_interests_are_harmless(): void
    {
        $this->assertSame('', $this->emoji(['interest_slugs' => []]));
        $this->assertSame('', $this->emoji(['interest_slugs' => null]));
        $this->assertSame('', $this->emoji(['interest_slugs' => 'jazz']));
        $this->assertSame('🎷', $this->emoji(['interest_slugs' => [null, '', 'jazz']]));
    }

    /**
     * 💸 у бесплатного события читается как ошибка — значок денег там, где
     * денег не надо. А бесплатное это то, что выхватывают взглядом.
     */
    public function test_free_events_get_their_own_price_emoji(): void
    {
        $this->assertSame('🆓', $this->priceEmoji(['price_status' => 'free']));
        $this->assertSame('🤝', $this->priceEmoji(['price_status' => 'donation']));
        $this->assertSame('💸', $this->priceEmoji(['price_status' => 'range']));
        $this->assertSame('💸', $this->priceEmoji(['price_status' => 'external']));
        $this->assertSame('💸', $this->priceEmoji([]));
    }
}
