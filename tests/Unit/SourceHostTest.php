<?php

namespace Tests\Unit;

use App\Support\SourceHost;
use PHPUnit\Framework\TestCase;

class SourceHostTest extends TestCase
{
    /* ====== host(): канонический origin-host ====== */

    public function test_lowercases_and_strips_scheme_path_query(): void
    {
        $this->assertSame('site.ru', SourceHost::host('https://Site.RU/afisha?x=1#top'));
    }

    public function test_strips_leading_www(): void
    {
        $this->assertSame('site.ru', SourceHost::host('https://www.site.ru/afisha'));
    }

    public function test_strips_numbered_www(): void
    {
        $this->assertSame('site.ru', SourceHost::host('https://www2.site.ru/'));
    }

    public function test_strips_port(): void
    {
        $this->assertSame('site.ru', SourceHost::host('https://site.ru:8443/afisha'));
    }

    public function test_adds_scheme_when_missing(): void
    {
        $this->assertSame('site.ru', SourceHost::host('site.ru/afisha'));
    }

    /**
     * Регресс на баг ltrim($host, 'w.') — трактовал 'w.' как НАБОР символов и
     * резал ВСЕ ведущие w/точки, ломая хосты на 'w'. Канонизатор режет только
     * префикс www[\d]*., не трогая осмысленные w в начале имени.
     */
    public function test_does_not_mangle_hosts_starting_with_w(): void
    {
        $this->assertSame('weekend.ru', SourceHost::host('https://weekend.ru/afisha'));
        $this->assertSame('weburg.net', SourceHost::host('https://weburg.net/'));
        $this->assertSame('wildberries.ru', SourceHost::host('https://www.wildberries.ru/'));
    }

    public function test_keeps_subdomains_other_than_www(): void
    {
        $this->assertSame('afisha.site.ru', SourceHost::host('https://afisha.site.ru/events'));
    }

    /* ====== canonical(): ключ дедупа по хосту ====== */

    public function test_canonical_collapses_sections_of_same_host(): void
    {
        // владелец выбрал дедуп по origin-host: разные разделы одного сайта =
        // один источник → одинаковый канон
        $this->assertSame(
            SourceHost::canonical('https://site.ru/afisha'),
            SourceHost::canonical('http://www.site.ru/concerts?utm=1'),
        );
    }

    public function test_canonical_is_https_host_only(): void
    {
        $this->assertSame('https://site.ru', SourceHost::canonical('http://www.site.ru/afisha/'));
    }

    public function test_canonical_distinguishes_different_hosts(): void
    {
        $this->assertNotSame(
            SourceHost::canonical('https://site.ru/afisha'),
            SourceHost::canonical('https://other.ru/afisha'),
        );
    }
}
