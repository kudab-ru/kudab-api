<?php

namespace Tests\Feature\Api;

use App\Models\SourceConfig;
use App\Models\SourceRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PR4: admin-эндпоинты управления источником Я.Афиша под суперадмин-гейтом.
 * Проверяет: гейт (обычный admin → 403, аноним → 401, суперадмин → 200),
 * config GET/PUT round-trip, валидацию неизвестного slug, status.
 */
class AdminYandexAfishaTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/api/admin/sources/yandex-afisha/config')->assertStatus(401);
    }

    public function test_regular_admin_is_forbidden(): void
    {
        $this->actingAsRole('admin');

        $this->getJson('/api/admin/sources/yandex-afisha/config')->assertStatus(403);
        $this->putJson('/api/admin/sources/yandex-afisha/config', ['city_slug' => 'voronezh'])
            ->assertStatus(403);
    }

    public function test_superadmin_can_read_config(): void
    {
        $this->actingAsRole('superadmin');

        $this->getJson('/api/admin/sources/yandex-afisha/config')
            ->assertOk()
            ->assertJsonPath('data.source_slug', 'yandex_afisha')
            ->assertJsonPath('data.cities', [])
            ->assertJsonFragment(['known_sections' => array_values(\App\Http\Requests\Admin\Sources\UpdateYandexAfishaConfigRequest::KNOWN_SECTIONS)]);
    }

    public function test_superadmin_can_update_config(): void
    {
        $this->actingAsRole('superadmin');

        $response = $this->putJson('/api/admin/sources/yandex-afisha/config', [
            'city_slug' => 'voronezh',
            'enabled' => true,
            'json_ld_bypass_enabled' => true,
            'listing_limit_per_run' => 150,
            'listing_limit_per_section' => 30,
            'sections' => [
                ['slug' => 'concert', 'enabled' => true],
                ['slug' => 'art', 'enabled' => false],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.city_slug', 'voronezh')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.listing_limit_per_section', 30)
            ->assertJsonPath('data.sections.1.slug', 'art')
            ->assertJsonPath('data.sections.1.enabled', false);

        $row = SourceConfig::where('source_slug', 'yandex_afisha')->where('city_slug', 'voronezh')->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->enabled);
        $this->assertSame(['concert', 'art'], array_column($row->sections, 'slug'));
    }

    public function test_update_is_idempotent_upsert(): void
    {
        $this->actingAsRole('superadmin');

        $this->putJson('/api/admin/sources/yandex-afisha/config', ['city_slug' => 'voronezh', 'enabled' => false])->assertOk();
        $this->putJson('/api/admin/sources/yandex-afisha/config', ['city_slug' => 'voronezh', 'enabled' => true])->assertOk();

        $this->assertSame(
            1,
            SourceConfig::where('source_slug', 'yandex_afisha')->where('city_slug', 'voronezh')->count(),
        );
    }

    public function test_put_rejects_malformed_section_slug(): void
    {
        $this->actingAsRole('superadmin');

        // Слеш/верхний регистр — path-traversal / инъекция в URL афиши.
        $this->putJson('/api/admin/sources/yandex-afisha/config', [
            'city_slug' => 'voronezh',
            'sections' => [['slug' => 'bad/Slug', 'enabled' => true]],
        ])->assertStatus(422)->assertJsonValidationErrors(['sections.0.slug']);
    }

    public function test_put_accepts_custom_valid_slug(): void
    {
        $this->actingAsRole('superadmin');

        // Кастомный slug вне KNOWN_SECTIONS, но корректного формата — разрешён
        // (суперадмин может добавить новый раздел Я.Афиши «на всякий случай»).
        $this->putJson('/api/admin/sources/yandex-afisha/config', [
            'city_slug' => 'voronezh',
            'sections' => [['slug' => 'opera', 'enabled' => true]],
        ])->assertOk()->assertJsonPath('data.sections.0.slug', 'opera');

        $row = SourceConfig::where('source_slug', 'yandex_afisha')->where('city_slug', 'voronezh')->first();
        $this->assertSame(['opera'], array_column($row->sections, 'slug'));
    }

    public function test_status_returns_recent_runs(): void
    {
        $this->actingAsRole('superadmin');

        SourceRun::create([
            'source_slug' => 'yandex_afisha',
            'city_slug' => 'voronezh',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
            'status' => 'ok',
            'urls_total' => 40,
            'posts_ok' => 38,
            'posts_failed' => 2,
        ]);

        $this->getJson('/api/admin/sources/yandex-afisha/status')
            ->assertOk()
            ->assertJsonPath('data.last_run.status', 'ok')
            ->assertJsonPath('data.last_run.posts_ok', 38)
            ->assertJsonPath('data.posts_48h', 0);
    }
}
