<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_events_store_validates_required_title(): void
    {
        $response = $this->postJson('/api/admin/events', []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_admin_events_store_creates_event_with_minimal_valid_payload(): void
    {
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
