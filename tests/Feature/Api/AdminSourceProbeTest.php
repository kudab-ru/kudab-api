<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Онбординг сайта из админки: заявка на probe → поллинг → создание профиля
 * из результата (всегда enabled=false — карантин) + community + link.
 */
class AdminSourceProbeTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        Sanctum::actingAs($user);

        return $user;
    }

    private function seedCity(): int
    {
        // cities.latitude/longitude — generated из location
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.66, 'active', 'voronezh', now(), now()]
        );

        return (int) DB::table('cities')->where('slug', 'voronezh')->value('id');
    }

    private function seedDoneRequest(array $resultOverrides = []): int
    {
        return (int) DB::table('source_probe_requests')->insertGetId([
            'listing_url' => 'https://dk50.example/afisha',
            'status' => 'done',
            'result' => json_encode(array_merge([
                'origin' => 'https://dk50.example',
                'paths_total' => 20,
                'clusters' => [['template' => '/afisha/{s}', 'count' => 9, 'jsonld' => '2/2']],
                'positive_templates' => ['/afisha/{s}'],
                'suggested_regex' => '~^https\://dk50\.example/afisha/[a-z0-9][a-z0-9-]*$~i',
                'coverage' => 9,
            ], $resultOverrides)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_store_creates_pending_request(): void
    {
        $this->actingAsSuperadmin();

        $this->postJson('/api/admin/sources/profiles/probe-requests', ['listing_url' => 'https://dk50.example/afisha/'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('source_probe_requests', ['listing_url' => 'https://dk50.example/afisha']);
    }

    public function test_store_dedupes_active_and_fresh_requests(): void
    {
        $this->actingAsSuperadmin();

        $first = $this->postJson('/api/admin/sources/profiles/probe-requests', ['listing_url' => 'https://dk50.example/afisha'])
            ->assertCreated()->json('data.id');

        // повторная заявка на тот же URL — возвращается существующая, дубль не создаётся
        $this->postJson('/api/admin/sources/profiles/probe-requests', ['listing_url' => 'https://dk50.example/afisha/'])
            ->assertOk()
            ->assertJsonPath('data.id', $first)
            ->assertJsonPath('data.reused', true);

        $this->assertSame(1, DB::table('source_probe_requests')->count());
    }

    public function test_create_jsonld_profile_quarantined_with_community_and_link(): void
    {
        $this->actingAsSuperadmin();
        $this->seedCity();
        $reqId = $this->seedDoneRequest();

        $this->postJson('/api/admin/sources/profiles/create', [
            'probe_request_id' => $reqId,
            'name' => 'ДК 50-летия',
            'city_slug' => 'voronezh',
            'parse_mode' => 'jsonld',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'dk50-example')
            ->assertJsonPath('data.enabled', false);

        $profile = DB::table('source_profiles')->where('slug', 'dk50-example')->first();
        $this->assertFalse((bool) $profile->enabled, 'карантин: профиль создаётся выключенным');
        $this->assertSame('jsonld', $profile->parse_mode);

        $communityId = DB::table('communities')->where('name', 'ДК 50-летия')->value('id');
        $this->assertNotNull($communityId);
        $this->assertDatabaseHas('community_social_links', [
            'community_id' => $communityId,
            'social_network_id' => 3,
            'external_community_id' => 'dk50-example',
        ]);
    }

    public function test_create_llm_text_profile_builds_regex_from_template(): void
    {
        $this->actingAsSuperadmin();
        $this->seedCity();
        $reqId = $this->seedDoneRequest(['suggested_regex' => null, 'positive_templates' => []]);

        $this->postJson('/api/admin/sources/profiles/create', [
            'probe_request_id' => $reqId,
            'name' => 'Фестиваль X',
            'city_slug' => 'voronezh',
            'parse_mode' => 'llm_text',
            'template' => '/prog/{s}',
            'slug' => 'fest-x',
        ])->assertCreated();

        $regex = DB::table('source_profiles')->where('slug', 'fest-x')->value('event_url_regex');
        $this->assertSame(1, preg_match($regex, 'https://dk50.example/prog/koncert-y'));
        $this->assertSame(0, preg_match($regex, 'https://dk50.example/news/koncert-y'));
    }

    public function test_create_binds_to_existing_community(): void
    {
        $this->actingAsSuperadmin();
        $cityId = $this->seedCity();
        $reqId = $this->seedDoneRequest();
        $communityId = (int) DB::table('communities')->insertGetId([
            'name' => 'Никитинский театр', 'city_id' => $cityId,
            'verification_status' => 'approved', 'is_verified' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/admin/sources/profiles/create', [
            'probe_request_id' => $reqId,
            'name' => 'Сайт Никитинского',
            'city_slug' => 'voronezh',
            'parse_mode' => 'jsonld',
            'community_id' => $communityId,
        ])->assertCreated();

        // линк повешен на СУЩЕСТВУЮЩЕЕ сообщество, дубль не создан
        $this->assertDatabaseHas('community_social_links', [
            'community_id' => $communityId,
            'social_network_id' => 3,
            'external_community_id' => 'dk50-example',
        ]);
        $this->assertSame(0, DB::table('communities')->where('name', 'Сайт Никитинского')->count());
    }

    public function test_create_rejects_jsonld_without_positive_probe(): void
    {
        $this->actingAsSuperadmin();
        $this->seedCity();
        $reqId = $this->seedDoneRequest(['suggested_regex' => null]);

        $this->postJson('/api/admin/sources/profiles/create', [
            'probe_request_id' => $reqId,
            'name' => 'X',
            'city_slug' => 'voronezh',
            'parse_mode' => 'jsonld',
        ])->assertStatus(422);
    }
}
