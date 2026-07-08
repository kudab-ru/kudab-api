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

    public function test_reprobe_sets_mark_for_both_modes(): void
    {
        $this->actingAsRole('superadmin');
        $jsonld = $this->seedProfile();
        $llm = $this->seedProfile(['slug' => 'fest-x', 'parse_mode' => 'llm_text']);

        $this->postJson("/api/admin/sources/profiles/{$jsonld}/reprobe")->assertOk();
        $this->postJson("/api/admin/sources/profiles/{$llm}/reprobe")->assertOk();

        $this->assertNotNull(DB::table('source_profiles')->where('id', $jsonld)->value('reprobe_requested_at'));
        $this->assertNotNull(DB::table('source_profiles')->where('id', $llm)->value('reprobe_requested_at'));
    }

    public function test_rebind_moves_link_posts_and_own_events(): void
    {
        $this->actingAsRole('superadmin');
        $id = $this->seedProfile();

        $old = (int) DB::table('communities')->insertGetId([
            'name' => 'Дубль', 'verification_status' => 'approved', 'is_verified' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $target = (int) DB::table('communities')->insertGetId([
            'name' => 'Настоящий организатор', 'verification_status' => 'approved', 'is_verified' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("INSERT INTO social_networks (id, name, slug, icon, url_mask, created_at, updated_at)
            VALUES (3, 'Сайт', 'site', 's', 'x', NOW(), NOW()) ON CONFLICT (id) DO NOTHING");
        $linkId = (int) DB::table('community_social_links')->insertGetId([
            'community_id' => $old, 'social_network_id' => 3,
            'external_community_id' => 'dk50-voronezh', 'url' => 'https://dk50.example',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $postId = (int) DB::table('context_posts')->insertGetId([
            'external_id' => 'afisha/x', 'source' => 'site', 'social_link_id' => $linkId,
            'community_id' => $old, 'status' => 'parsed', 'text' => 't',
            'author_id' => 1, 'author_type' => 'community', 'published_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $eventId = (int) DB::table('events')->insertGetId([
            'title' => 'Событие источника', 'community_id' => $old, 'original_post_id' => $postId,
            'start_time' => now()->addDay(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // чужое событие старого организатора — остаётся на месте
        $foreignEvent = (int) DB::table('events')->insertGetId([
            'title' => 'Чужое', 'community_id' => $old,
            'start_time' => now()->addDay(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson("/api/admin/sources/profiles/{$id}/rebind", ['community_id' => $target])
            ->assertOk()
            ->assertJsonPath('data.moved_events', 1)
            ->assertJsonPath('data.moved_posts', 1);

        $this->assertSame($target, (int) DB::table('community_social_links')->where('id', $linkId)->value('community_id'));
        $this->assertSame($target, (int) DB::table('events')->where('id', $eventId)->value('community_id'));
        $this->assertSame($old, (int) DB::table('events')->where('id', $foreignEvent)->value('community_id'));
        // дубль-организатор жив (ничего не удаляем)
        $this->assertNotNull(DB::table('communities')->where('id', $old)->whereNull('deleted_at')->first());

        // отказ: у цели уже есть сайт-источник (только что переехал)
        $this->postJson("/api/admin/sources/profiles/{$id}/rebind", ['community_id' => $target])
            ->assertStatus(422);
    }
}
