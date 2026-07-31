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

    public function test_update_moves_the_point_and_marks_it_manual(): void
    {
        // Резолверы ошибаются целыми классами (тёзка в OSM, улица вместо
        // посёлка), поэтому у человека должен быть способ поставить метку
        // рукой — и с этого момента автоматика её не трогает.
        $this->actingAsSuperadmin();
        $cityId = $this->seedCity();
        $id = $this->seedVenue($cityId, 'Матрёшка', 'matreshka');
        DB::update('UPDATE venues SET location = ST_SetSRID(ST_Point(39.202908, 51.673517), 4326) WHERE id = ?', [$id]);

        $this->patchJson("/api/admin/venues/{$id}", ['lat' => 51.676141, 'lon' => 39.205296])
            ->assertOk();

        $venue = DB::table('venues')->where('id', $id)->first();
        $this->assertEqualsWithDelta(51.676141, (float) $venue->latitude, 1e-6);
        $this->assertEqualsWithDelta(39.205296, (float) $venue->longitude, 1e-6);

        $meta = json_decode((string) $venue->source_meta, true) ?: [];
        $this->assertTrue($meta['manual_point'] ?? false, 'точка помечена как ручная');
    }

    public function test_moving_the_point_takes_pinned_events_along(): void
    {
        $this->actingAsSuperadmin();
        $cityId = $this->seedCity();
        $id = $this->seedVenue($cityId, 'Площадка', 'ploshadka');
        DB::update('UPDATE venues SET location = ST_SetSRID(ST_Point(39.20, 51.67), 4326) WHERE id = ?', [$id]);

        $pinned = $this->seedEventAt($cityId, $id, 51.67, 39.20);
        $ownPoint = $this->seedEventAt($cityId, $id, 51.60, 39.30);

        $this->patchJson("/api/admin/venues/{$id}", ['lat' => 51.68, 'lon' => 39.21])->assertOk();

        $this->assertEqualsWithDelta(51.68, (float) DB::table('events')->where('id', $pinned)->value('latitude'), 1e-6,
            'событие стояло на точке площадки — переехало с ней');
        $this->assertEqualsWithDelta(51.60, (float) DB::table('events')->where('id', $ownPoint)->value('latitude'), 1e-6,
            'событие со своей точкой не тронуто');
    }

    public function test_half_a_point_is_rejected(): void
    {
        $this->actingAsSuperadmin();
        $id = $this->seedVenue($this->seedCity(), 'Площадка', 'ploshadka-2');

        $this->patchJson("/api/admin/venues/{$id}", ['lat' => 51.7])->assertStatus(422);
        $this->patchJson("/api/admin/venues/{$id}", ['lat' => 100, 'lon' => 39.2])->assertStatus(422);
    }

    private function seedEventAt(int $cityId, int $venueId, float $lat, float $lon): int
    {
        $communityId = (int) DB::table('communities')->insertGetId([
            'name' => 'Организатор '.$lat.'-'.$venueId,
            'city_id' => $cityId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = (int) DB::table('events')->insertGetId([
            'community_id' => $communityId,
            'city_id' => $cityId,
            'venue_id' => $venueId,
            'title' => 'Событие '.$lat,
            'status' => 'active',
            'start_time' => now()->addDay(),
            'dedup_key' => 'venue-point-'.$lat.'-'.$lon.'-'.$venueId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::update('UPDATE events SET location = ST_SetSRID(ST_Point(?, ?), 4326) WHERE id = ?', [$lon, $lat, $id]);

        return $id;
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
