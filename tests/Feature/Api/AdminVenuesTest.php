<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Админ-каталог площадок: список со счётчиками, правка, склейка дублей
 * (события/организаторы переезжают, дубль в архив, гейт города).
 */
class AdminVenuesTest extends TestCase
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

    private function seedCity(string $slug = 'voronezh'): int
    {
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            [ucfirst($slug), 'RU', 39.2, 51.66, 'active', $slug, now(), now()]
        );

        return (int) DB::table('cities')->where('slug', $slug)->value('id');
    }

    private function seedCommunity(int $cityId): int
    {
        return (int) DB::table('communities')->insertGetId([
            'name' => 'Организатор '.uniqid(), 'city_id' => $cityId,
            'verification_status' => 'approved', 'is_verified' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedVenue(int $cityId, string $name, string $slug): int
    {
        return (int) DB::table('venues')->insertGetId([
            'name' => $name, 'slug' => $slug, 'status' => 'active', 'city_id' => $cityId,
            'source_meta' => json_encode(['origin' => 'cold_resolve', 'resolved_via' => 'osm_poi']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_index_with_counters_and_search(): void
    {
        $this->actingAsSuperadmin();
        $cityId = $this->seedCity();
        $parkId = $this->seedVenue($cityId, 'Парк Алые паруса', 'park-alye');
        $this->seedVenue($cityId, 'Зелёный театр', 'zel-teatr');
        DB::table('events')->insert([
            'title' => 'Событие в парке', 'venue_id' => $parkId, 'city_id' => $cityId,
            'community_id' => $this->seedCommunity($cityId),
            'start_time' => now()->addDay(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/api/admin/venues?q=парус')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Парк Алые паруса')
            ->assertJsonPath('data.0.events_total', 1)
            ->assertJsonPath('data.0.via', 'osm_poi');
    }

    public function test_update_name_and_address(): void
    {
        $this->actingAsSuperadmin();
        $id = $this->seedVenue($this->seedCity(), 'Старое имя', 'old');

        $this->patchJson("/api/admin/venues/{$id}", ['name' => 'Новое имя', 'address' => 'ул. Правильная, 1'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Новое имя');

        $this->assertDatabaseHas('venues', ['id' => $id, 'address' => 'ул. Правильная, 1']);
    }

    public function test_merge_moves_events_and_archives_duplicate(): void
    {
        $this->actingAsSuperadmin();
        $cityId = $this->seedCity();
        $main = $this->seedVenue($cityId, 'Парк Алые паруса', 'park-main');
        $dup = $this->seedVenue($cityId, 'Парк «Алые паруса»', 'park-dup');
        DB::table('events')->insert([
            'title' => 'На дубле', 'venue_id' => $dup, 'city_id' => $cityId,
            'community_id' => $this->seedCommunity($cityId),
            'start_time' => now()->addDay(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('communities')->insert([
            'name' => 'ДК', 'venue_id' => $dup, 'city_id' => $cityId,
            'verification_status' => 'approved', 'is_verified' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson("/api/admin/venues/{$dup}/merge", ['into_id' => $main])
            ->assertOk()
            ->assertJsonPath('data.moved_events', 1)
            ->assertJsonPath('data.moved_communities', 1);

        $this->assertSame($main, (int) DB::table('events')->where('title', 'На дубле')->value('venue_id'));
        $this->assertSame($main, (int) DB::table('communities')->where('name', 'ДК')->value('venue_id'));
        $this->assertNotNull(DB::table('venues')->where('id', $dup)->value('deleted_at'));
    }

    public function test_merge_rejects_cross_city(): void
    {
        $this->actingAsSuperadmin();
        $a = $this->seedVenue($this->seedCity('voronezh'), 'А', 'a');
        $b = $this->seedVenue($this->seedCity('lipetsk'), 'Б', 'b');

        $this->postJson("/api/admin/venues/{$a}/merge", ['into_id' => $b])->assertStatus(422);
    }
}
