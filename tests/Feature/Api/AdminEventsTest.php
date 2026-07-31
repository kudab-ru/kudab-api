<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use Carbon\Carbon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminEventsTest extends TestCase
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
        $this->postJson('/api/admin/events', [])->assertStatus(401);
        $this->getJson('/api/admin/events')->assertStatus(401);
    }

    public function test_admin_events_store_validates_required_title(): void
    {
        $this->actingAsAdmin();
        $response = $this->postJson('/api/admin/events', []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_admin_events_store_creates_event_with_minimal_valid_payload(): void
    {
        $this->actingAsAdmin();
        $city = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);

        $community = Community::create([
            'name' => 'Организатор Москва',
            'city_id' => $city->id,
        ]);

        $payload = [
            'title' => 'Новый концерт',
            'community_id' => $community->id,
            'city_id' => $city->id,
            'status' => 'active',
            'start_time' => now()->addDay()->toIso8601String(),
            'start_date' => now()->addDay()->toDateString(),
        ];

        $response = $this->postJson('/api/admin/events', $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'Новый концерт')
            ->assertJsonPath('data.community_id', $community->id)
            ->assertJsonPath('data.city_id', $city->id);

        $this->assertDatabaseHas('events', [
            'title' => 'Новый концерт',
            'community_id' => $community->id,
            'city_id' => $city->id,
            'status' => 'active',
        ]);
    }

    public function test_admin_events_update_changes_fields_and_derives_start_date_from_start_time(): void
    {
        $this->actingAsAdmin();
        $city = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);

        $community = Community::create([
            'name' => 'Организатор Москва',
            'city_id' => $city->id,
        ]);

        $event = new Event();
        $event->community_id = $community->id;
        $event->city_id = $city->id;
        $event->title = 'Старое название';
        $event->status = 'draft';
        $event->start_time = Carbon::create(2026, 3, 24, 18, 0, 0, 'Europe/Moscow');
        $event->start_date = '2026-03-24';
        $event->save();

        $newStartTime = Carbon::create(2026, 3, 28, 20, 30, 0, 'Europe/Moscow');

        $response = $this->patchJson('/api/admin/events/' . $event->id, [
            'title' => 'Обновленное название',
            'status' => 'active',
            'start_time' => $newStartTime->toIso8601String(),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $event->id)
            ->assertJsonPath('data.title', 'Обновленное название')
            ->assertJsonPath('data.status', 'active');

        $event->refresh();

        $this->assertSame('Обновленное название', $event->title);
        $this->assertSame('active', $event->status);
        $this->assertSame('2026-03-28', $event->start_date?->format('Y-m-d'));
    }

    public function test_admin_events_destroy_soft_deletes_event(): void
    {
        $this->actingAsAdmin();
        $city = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);

        $community = Community::create([
            'name' => 'Организатор Москва',
            'city_id' => $city->id,
        ]);

        $event = new Event();
        $event->community_id = $community->id;
        $event->city_id = $city->id;
        $event->title = 'Удаляемое событие';
        $event->status = 'active';
        $event->start_time = now()->addDay();
        $event->start_date = now()->addDay()->toDateString();
        $event->save();

        $response = $this->deleteJson('/api/admin/events/' . $event->id);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSoftDeleted('events', [
            'id' => $event->id,
            'title' => 'Удаляемое событие',
        ]);
    }

    public function test_admin_events_restore_recovers_soft_deleted_event(): void
    {
        $this->actingAsAdmin();
        $city = $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);

        $community = Community::create([
            'name' => 'Организатор Москва',
            'city_id' => $city->id,
        ]);

        $event = new Event();
        $event->community_id = $community->id;
        $event->city_id = $city->id;
        $event->title = 'Восстанавливаемое событие';
        $event->status = 'active';
        $event->start_time = now()->addDay();
        $event->start_date = now()->addDay()->toDateString();
        $event->save();

        $event->delete();

        $this->assertSoftDeleted('events', [
            'id' => $event->id,
        ]);

        $response = $this->postJson('/api/admin/events/' . $event->id . '/restore');

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $event->id)
            ->assertJsonPath('data.title', 'Восстанавливаемое событие');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'title' => 'Восстанавливаемое событие',
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
