<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Support\Telegram\DigestTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DigestTitleTest extends TestCase
{
    /** @return array<string, array{string, ?string, list<string>, string}> */
    public static function titles(): array
    {
        return [
            'дата и площадка через дефис' => ['04.11 - Суперкозлы - Котельная', 'КОТЕЛЬНАЯ', [], 'Суперкозлы'],
            'дата и площадка через косую' => ['1 ноября / Halloween gig / Diesel bar', 'Diesel', [], 'Halloween gig'],
            'пояснение после тире' => ['Большой открытый микрофон — комики проверяют свои самые свежие шутки', 'Стендап клуб', [], 'Большой открытый микрофон'],
            'город в конце' => ['Гаражка издательства МИФ в Воронеже', 'Арена', [], 'Гаражка издательства МИФ'],
            'дата в конце' => ['Дима Вагин концерт в Воронеже 28.09', 'Клуб 12', [], 'Дима Вагин концерт'],
            'короткое имя площадки внутри слова' => ['Арт-рок вечер', 'Арт', [], 'Арт-рок вечер'],
            'в рамках акции' => ['«Сценокардия» в рамках Всероссийской акции «Ночь искусств»', 'Театр драмы', ['ночь искусств'], '«Сценокардия»'],
            'название — один повод' => ['Ночь искусств', 'Костёнки', ['ночь искусств'], ''],
            'повод и место' => ['Ночь искусств – 2026 в Литературном музее', 'Музей Бунина', ['ночь искусств'], ''],
            'название, а не повод' => ['Шабаш — Diesel Bar', 'Diesel', ['хэллоуин'], 'Шабаш'],
        ];
    }

    /** @param list<string> $occasion */
    #[DataProvider('titles')]
    public function test_title_keeps_only_the_event_name(string $raw, ?string $venue, array $occasion, string $clean): void
    {
        $this->assertSame($clean, DigestTitle::clean($raw, $venue, 'Воронеж', 'Воронеже', $occasion));
    }
}
