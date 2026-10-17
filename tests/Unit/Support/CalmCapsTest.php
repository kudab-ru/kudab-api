<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CalmCaps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CalmCapsTest extends TestCase
{
    #[Test]
    #[DataProvider('titles')]
    public function a_shouting_title_calms_down_and_short_capitals_stay(string $title, string $expected): void
    {
        $this->assertSame($expected, CalmCaps::title($title));
    }

    public static function titles(): array
    {
        return [
            'фраза в кавычках' => ['Квиз "ХЭЛЛОУИН. ПРАЗДНИЧНАЯ ИГРА!"', 'Квиз "Хэллоуин. Праздничная игра!"'],
            'одно слово' => ['[HALLOWEEN] VRN #5', '[Halloween] VRN #5'],
            'после двоеточия' => ['ДК «Строитель»: ЁЛКА ДЛЯ ДЕТЕЙ', 'ДК «Строитель»: Ёлка для детей'],
            'сокращение и группа' => ['ABBA трибьют в МТС Live Холл', 'ABBA трибьют в МТС Live Холл'],
        ];
    }
}
