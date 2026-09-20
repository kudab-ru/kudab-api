<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Площадка внутри площадки: запись связи и её гарды.
 *
 * Связь ставится руками из админки, а всё, что ставится руками, надо уметь
 * снять и нельзя дать завязать в узел. Отсюда четыре проверки: пишется,
 * снимается, не замыкается на себя, не образует кольца и не лезет в чужой город.
 */
class AdminVenuesParentTest extends TestCase
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

    private function seedVenue(int $cityId, string $name, string $slug): int
    {
        return (int) DB::table('venues')->insertGetId([
            'name' => $name, 'slug' => $slug, 'status' => 'active', 'city_id' => $cityId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_parent_is_saved_and_returned_in_list(): void
    {
        $this->actingAsSuperadmin();
        $city = $this->seedCity();
        $park = $this->seedVenue($city, 'Парк Динамо', 'park-dinamo');
        $stage = $this->seedVenue($city, 'Зелёный театр', 'zelenyi-teatr');

        $this->patchJson("/api/admin/venues/{$stage}", ['parent_id' => $park])
            ->assertOk();

        $this->assertSame($park, (int) DB::table('venues')->where('id', $stage)->value('parent_id'));

        $rows = collect($this->getJson('/api/admin/venues')->json('data'))->keyBy('id');
        $this->assertSame($park, $rows[$stage]['parent_id']);
        $this->assertSame('Парк Динамо', $rows[$stage]['parent_name']);
        $this->assertSame(1, $rows[$park]['children_count']);
    }

    /** Снять связь так же легко, как поставить: пустое значение — это «снять». */
    public function test_parent_can_be_cleared(): void
    {
        $this->actingAsSuperadmin();
        $city = $this->seedCity();
        $park = $this->seedVenue($city, 'Парк Динамо', 'park-dinamo');
        $stage = $this->seedVenue($city, 'Зелёный театр', 'zelenyi-teatr');

        $this->patchJson("/api/admin/venues/{$stage}", ['parent_id' => $park])->assertOk();
        $this->patchJson("/api/admin/venues/{$stage}", ['parent_id' => null])->assertOk();

        $this->assertNull(DB::table('venues')->where('id', $stage)->value('parent_id'));
    }

    public function test_venue_cannot_be_its_own_parent(): void
    {
        $this->actingAsSuperadmin();
        $city = $this->seedCity();
        $v = $this->seedVenue($city, 'Парк Динамо', 'park-dinamo');

        $this->patchJson("/api/admin/venues/{$v}", ['parent_id' => $v])
            ->assertStatus(422);

        $this->assertNull(DB::table('venues')->where('id', $v)->value('parent_id'));
    }

    /** Кольцо подвесило бы любой обход дерева — ловим до записи. */
    public function test_cycle_is_rejected(): void
    {
        $this->actingAsSuperadmin();
        $city = $this->seedCity();
        $park = $this->seedVenue($city, 'Парк Динамо', 'park-dinamo');
        $stage = $this->seedVenue($city, 'Зелёный театр', 'zelenyi-teatr');
        $booth = $this->seedVenue($city, 'Ракушка', 'rakushka');

        $this->patchJson("/api/admin/venues/{$stage}", ['parent_id' => $park])->assertOk();
        $this->patchJson("/api/admin/venues/{$booth}", ['parent_id' => $stage])->assertOk();

        // Парк внутрь своей же ракушки — это кольцо из трёх звеньев
        $this->patchJson("/api/admin/venues/{$park}", ['parent_id' => $booth])
            ->assertStatus(422);

        $this->assertNull(DB::table('venues')->where('id', $park)->value('parent_id'));
    }

    public function test_parent_from_another_city_is_rejected(): void
    {
        $this->actingAsSuperadmin();
        $voronezh = $this->seedCity('voronezh');
        $moscow = $this->seedCity('moskva');
        $here = $this->seedVenue($voronezh, 'Зелёный театр', 'zelenyi-teatr');
        $there = $this->seedVenue($moscow, 'Парк Горького', 'park-gorkogo');

        $this->patchJson("/api/admin/venues/{$here}", ['parent_id' => $there])
            ->assertStatus(422);
    }

    public function test_missing_parent_is_rejected(): void
    {
        $this->actingAsSuperadmin();
        $city = $this->seedCity();
        $v = $this->seedVenue($city, 'Зелёный театр', 'zelenyi-teatr');

        $this->patchJson("/api/admin/venues/{$v}", ['parent_id' => 999999])
            ->assertStatus(422);
    }
}
