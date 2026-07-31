<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\Community;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCommunitiesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Админские роуты закрыты `auth:sanctum` + `role:admin|superadmin`
     * (routes/api.php), поэтому тест обязан прийти под ролью — иначе он
     * проверяет не контроллер, а сам факт защиты, отвечая 401 на всё.
     */
    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Сторож самой защиты. В феврале middleware сняли с группы мимоходом,
     * в коммите про статусы городов, и админский API простоял открытым
     * два с половиной месяца — ни один тест не покраснел, потому что
     * проверять было нечем.
     */
    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/admin/communities', [])->assertStatus(401);
        $this->getJson('/api/admin/communities')->assertStatus(401);
    }

    public function test_admin_communities_store_validates_required_name(): void
    {
        $this->actingAsAdmin();
        $response = $this->postJson('/api/admin/communities', []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_admin_communities_store_creates_community_with_minimal_valid_payload(): void
    {
        $this->actingAsAdmin();
        $city = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);

        $payload = [
            'name' => 'Новый организатор',
            'city_id' => $city->id,
        ];

        $response = $this->postJson('/api/admin/communities', $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Новый организатор')
            ->assertJsonPath('data.city_id', $city->id);

        $this->assertDatabaseHas('communities', [
            'name' => 'Новый организатор',
            'city_id' => $city->id,
        ]);
    }

    public function test_admin_communities_update_changes_fields(): void
    {
        $this->actingAsAdmin();
        $city = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $otherCity = $this->insertCity('Санкт-Петербург', 'spb', 'active', 30.3351, 59.9343);

        $community = Community::create([
            'name' => 'Старый организатор',
            'city_id' => $city->id,
            'verification_status' => 'pending',
        ]);

        $response = $this->patchJson('/api/admin/communities/' . $community->id, [
            'name' => 'Обновленный организатор',
            'city_id' => $otherCity->id,
            'verification_status' => 'approved',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $community->id)
            ->assertJsonPath('data.name', 'Обновленный организатор')
            ->assertJsonPath('data.city_id', $otherCity->id)
            ->assertJsonPath('data.verification_status', 'approved');

        $community->refresh();

        $this->assertSame('Обновленный организатор', $community->name);
        $this->assertSame($otherCity->id, $community->city_id);
        $this->assertSame('approved', $community->verification_status);
    }

    public function test_admin_communities_destroy_soft_deletes_community(): void
    {
        $this->actingAsAdmin();
        $city = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);

        $community = Community::create([
            'name' => 'Удаляемый организатор',
            'city_id' => $city->id,
        ]);

        $response = $this->deleteJson('/api/admin/communities/' . $community->id);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSoftDeleted('communities', [
            'id' => $community->id,
            'name' => 'Удаляемый организатор',
        ]);
    }

    public function test_admin_communities_restore_recovers_soft_deleted_community(): void
    {
        $this->actingAsAdmin();
        $city = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);

        $community = Community::create([
            'name' => 'Восстанавливаемый организатор',
            'city_id' => $city->id,
        ]);

        $community->delete();

        $this->assertSoftDeleted('communities', [
            'id' => $community->id,
        ]);

        $response = $this->postJson('/api/admin/communities/' . $community->id . '/restore');

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $community->id)
            ->assertJsonPath('data.name', 'Восстанавливаемый организатор')
            ->assertJsonPath('data.city_id', $city->id);

        $this->assertDatabaseHas('communities', [
            'id' => $community->id,
            'name' => 'Восстанавливаемый организатор',
            'deleted_at' => null,
        ]);
    }

    private function insertCity(string $name, string $slug, string $status, float $lng, float $lat): City
    {
        $now = now();

        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            [$name, 'RU', $lng, $lat, $status, $slug, $now, $now]
        );

        return City::query()->where('slug', $slug)->firstOrFail();
    }
}
