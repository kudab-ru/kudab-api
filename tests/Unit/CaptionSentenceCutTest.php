<?php

namespace Tests\Unit;

use App\Support\Telegram\CaptionTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Срез текста поста по границе фразы.
 *
 * `slice:0..400` рубил по счётчику символов и попадал в середину слова: из 193
 * предстоящих событий с текстом длиннее 400 знаков так обрывались 187 (97%).
 * В ленте это читалось как «объединил более 2000 школьников и 428 ком…» —
 * главный признак машины.
 */
class CaptionSentenceCutTest extends TestCase
{
    /** Короткий текст не трогаем вовсе. */
    public function test_short_text_is_untouched(): void
    {
        $this->assertSame('Всего две фразы. И обе короткие.',
            CaptionTemplate::sentence('Всего две фразы. И обе короткие.', 400));
    }

    /** Режем по последнему концу предложения, который влезает. */
    public function test_cuts_at_the_last_full_sentence(): void
    {
        $text = 'Первая фраза. Вторая фраза. Третья фраза, которая уже не влезает в лимит.';

        $this->assertSame('Первая фраза. Вторая фраза.', CaptionTemplate::sentence($text, 30));
    }

    /**
     * Многоточие НЕ дописывается: текст кончается законченной мыслью, а что он
     * не весь — говорит ссылка «Подробнее» под постом.
     */
    public function test_no_ellipsis_after_a_full_sentence(): void
    {
        $cut = CaptionTemplate::sentence('Первая фраза. Вторая фраза не влезает целиком в лимит.', 20);

        $this->assertSame('Первая фраза.', $cut);
        $this->assertStringNotContainsString('…', $cut);
    }

    /** Восклицание и вопрос — тоже концы фразы. */
    public function test_exclamation_and_question_end_a_sentence(): void
    {
        $this->assertSame('Вы бывали здесь?', CaptionTemplate::sentence('Вы бывали здесь? А стоило бы побывать непременно.', 22));
        $this->assertSame('Приходите!', CaptionTemplate::sentence('Приходите! Начало ровно в семь часов вечера.', 18));
    }

    /**
     * Точка без пробела после неё — это сокращение или адрес сайта, а не конец
     * фразы. Иначе «ул.Мира» и «kudab.ru» рвали бы текст посреди слова.
     */
    public function test_abbreviation_is_not_a_sentence_end(): void
    {
        $cut = CaptionTemplate::sentence('Встречаемся на ул.Мира у входа в парк культуры и отдыха.', 25);

        // Сокращение за конец фразы не принято: «Мира» осталась при улице,
        // и текст не оборван сразу после точки.
        $this->assertStringContainsString('ул.Мира', $cut);
        $this->assertStringEndsNotWith('ул.', rtrim($cut, '…'));
    }

    /** Точки в лимите нет вовсе — отступаем к границе слова и ставим многоточие. */
    public function test_falls_back_to_word_boundary_with_ellipsis(): void
    {
        $cut = CaptionTemplate::sentence('Очень длинное предложение совершенно без единой точки внутри лимита', 30);

        $this->assertStringEndsWith('…', $cut);
        $this->assertStringNotContainsString('совершенн…', $cut);
        $this->assertLessThanOrEqual(31, mb_strlen($cut));
    }

    /** Кириллица режется по символам, а не по байтам. */
    public function test_cuts_by_characters_not_bytes(): void
    {
        $text = 'Ёжик. Ёжик в тумане шёл и считал звёзды над рекою.';

        $this->assertSame('Ёжик.', CaptionTemplate::sentence($text, 10));
    }

    /** Ноль и отрицательный лимит текст не портят. */
    public function test_zero_limit_is_harmless(): void
    {
        $this->assertSame('Текст.', CaptionTemplate::sentence('Текст.', 0));
    }
}
