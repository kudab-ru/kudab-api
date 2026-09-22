<?php

namespace Tests\Unit;

use App\Services\Telegram\EventCaptionBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Значок по теме события — вместо 🎟, который стоял у всех постов одинаково.
 *
 * Подсмотрено у московского агрегатора: значок поддерживает название
 * («Фонтаны на ВДНХ ⛲»). Подбирается без модели: сначала слова в названии,
 * потом тип события.
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
            'экскурсия' => ['Экскурсия в Ошка-парк', '🌳'],
            'лекция' => ['Лекция о городской архитектуре', '💬'],
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
}
