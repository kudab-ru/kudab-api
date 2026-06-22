<?php

namespace Tests\Feature\Api;

use App\Models\SourceConfig;
use App\Models\SourceRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PR2 (фундамент админ-управления источником Я.Афиши): миграции
 * source_configs / source_runs + модели. Проверяет, что таблицы создаются
 * и касты (jsonb sections, boolean, integer, datetime) round-trip'ятся.
 * Над этими таблицами строятся admin-эндпоинты (PR4) и чтение парсером (PR3).
 */
class SourceConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_config_round_trips_casts(): void
    {
        $cfg = SourceConfig::create([
            'source_slug' => 'yandex_afisha',
            'city_slug' => 'voronezh',
            'enabled' => true,
            'json_ld_bypass_enabled' => true,
            'listing_limit_per_run' => 200,
            'listing_limit_per_section' => 40,
            'sections' => [
                ['slug' => 'concert', 'enabled' => true],
                ['slug' => 'art', 'content_kind' => 'culture', 'enabled' => true],
            ],
        ]);

        $fresh = SourceConfig::findOrFail($cfg->id);

        $this->assertTrue($fresh->enabled);
        $this->assertTrue($fresh->json_ld_bypass_enabled);
        $this->assertSame(40, $fresh->listing_limit_per_section);
        $this->assertIsArray($fresh->sections);
        $this->assertSame('art', $fresh->sections[1]['slug']);
        $this->assertNull($fresh->run_requested_at);
    }

    public function test_source_config_unique_per_source_and_city(): void
    {
        SourceConfig::create(['source_slug' => 'yandex_afisha', 'city_slug' => 'voronezh']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        SourceConfig::create(['source_slug' => 'yandex_afisha', 'city_slug' => 'voronezh']);
    }

    public function test_source_run_round_trips(): void
    {
        $run = SourceRun::create([
            'source_slug' => 'yandex_afisha',
            'city_slug' => 'voronezh',
            'started_at' => now(),
            'status' => 'ok',
            'urls_total' => 50,
            'posts_ok' => 48,
            'posts_failed' => 2,
        ]);

        $fresh = SourceRun::findOrFail($run->id);

        $this->assertSame('ok', $fresh->status);
        $this->assertSame(48, $fresh->posts_ok);
        $this->assertNotNull($fresh->started_at);
        $this->assertNull($fresh->finished_at);
    }
}
