<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Support\Telegram\VenueName;
use PHPUnit\Framework\TestCase;

class VenueNameTest extends TestCase
{
    public function test_escaped_quotes_from_site_markup_become_russian_quotes(): void
    {
        $this->assertSame('Бар «Цензура»', VenueName::label('Бар \\"Цензура\\"'));
    }
}
