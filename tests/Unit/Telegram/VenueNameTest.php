<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Support\Telegram\VenueName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VenueNameTest extends TestCase
{
    public function test_escaped_quotes_from_site_markup_become_russian_quotes(): void
    {
        $this->assertSame('Бар «Цензура»', VenueName::label('Бар \\"Цензура\\"'));
    }

    /** @return array<string, array{string, string}> */
    public static function cityNames(): array
    {
        return [
            'прилагательное города' => ['Воронежский камерный театр', 'Камерный театр'],
            'город в конце' => ['Музей И.А. Бунина в Воронеже', 'Музей И.А. Бунина'],
            'прежнее имя и город' => ['МТС Live Холл Воронеж (ex. Event Hall)', 'МТС Live Холл'],
            'город посередине' => ['Hobby Games — Воронеж — Настольные игры', 'Hobby Games — Настольные игры'],
            'другой город в скобках' => ['Культурно-досуговый центр (г. Нововоронеж)', 'Культурно-досуговый центр (г. Нововоронеж)'],
            'город — само имя' => ['Кинотеатр Воронеж', 'Кинотеатр Воронеж'],
        ];
    }

    #[DataProvider('cityNames')]
    public function test_venue_drops_the_city_the_channel_already_names(string $raw, string $short): void
    {
        $this->assertSame($short, VenueName::withoutCity($raw, 'Воронеж', 'Воронеже'));
    }
}
