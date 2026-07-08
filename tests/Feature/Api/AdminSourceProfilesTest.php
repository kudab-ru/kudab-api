<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Админка профильных сайтов-источников: суперадмин-гейт, список с health по
 * ранам, PATCH enabled/settings (merge), метка re-probe (jsonld-only).
 */
class AdminSourceProfilesTest extends TestCase
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

    private function seedProfile(array $overrides = []): int
    {
        return (int) DB::table('source_profiles')->insertGetId(array_merge([
            'slug' => 'dk50-voronezh',
            'name' => 'ДК 50-летия Октября',
            'listing_url' => 'https://dk50.example/afisha',
            'event_url_regex' => '~^x$~',
            'city_slug' => 'voronezh',
            'enabled' => true,
            'parse_mode' => 'jsonld',
            'settings' => json_encode(['delay_ms' => 2000, 'user_agent' => 'UA']),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_gate(): void
    {
        $this->getJson('/api/admin/sources/profiles')->assertStatus(401);

        $this->actingAsRole('admin');
        $this->getJson('/api/admin/sources/profiles')->assertStatus(403);
    }

    public function test_index_with_health_from_runs(): void
    {
        $this->actingAsRole('superadmin');
        $this->seedProfile();
        foreach ([0, 0, 0] as $ok) {
            DB::table('source_runs')->insert([
                'source_slug' => 'dk50-voronezh',
                'city_slug' => 'voronezh',
                'started_at' => now(),
                'finished_at' => now(),
                'status' => 'ok',
                'urls_total' => 5,
                'posts_ok' => $ok,
                'posts_failed' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->getJson('/api/admin/sources/profiles')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'dk50-voronezh')
            ->assertJsonPath('data.0.health', 'red')
            ->assertJsonPath('data.0.parse_mode', 'jsonld')
            ->assertJsonCount(3, 'data.0.recent_runs');
    }

    public function test_patch_merges_settings_and_toggles(): void
    {
        $this->actingAsRole('superadmin');
        $id = $this->seedProfile();

        $this->patchJson("/api/admin/sources/profiles/{$id}", [
            'enabled' => false,
            'settings' => ['listing_limit' => 10],
        ])->assertOk();

        $row = DB::table('source_profiles')->where('id', $id)->first();
        $this->assertFalse((bool) $row->enabled);
        $s = json_decode($row->settings, true);
        $this->assertSame(10, $s['listing_limit']);
        // merge: старые ключи не потеряны
        $this->assertSame('UA', $s['user_agent']);
        $this->assertSame(2000, $s['delay_ms']);
    }

    public function test_reprobe_sets_mark_and_rejects_llm_text(): void
    {
        $this->actingAsRole('superadmin');
        $jsonld = $this->seedProfile();
        $llm = $this->seedProfile(['slug' => 'fest-x', 'parse_mode' => 'llm_text']);

        $this->postJson("/api/admin/sources/profiles/{$jsonld}/reprobe")->assertOk();
        $this->assertNotNull(DB::table('source_profiles')->where('id', $jsonld)->value('reprobe_requested_at'));

        $this->postJson("/api/admin/sources/profiles/{$llm}/reprobe")->assertStatus(422);
    }
}
