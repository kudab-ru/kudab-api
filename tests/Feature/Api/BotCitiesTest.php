<?php

namespace Tests\Feature\Api;

use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BotCitiesTest extends TestCase
{
    use RefreshDatabase;

    private string $botToken = 'test-bot-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bot.shared_token' => $this->botToken,
        ]);
    }

    public function test_bot_cities_requires_bearer_token(): void
    {
        $response = $this->getJson('/api/bot/cities');

        $response
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized');
    }

    public function test_bot_cities_returns_only_active_cities_for_valid_token(): void
    {
        $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $this->insertCity('Воронеж', 'voronezh', 'disabled', 39.2003, 51.6608);

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $this->botToken)
            ->getJson('/api/bot/cities');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'moskva')
            ->assertJsonPath('data.0.status', 'active');

        $response->assertJsonMissing([
            'slug' => 'voronezh',
        ]);
    }

    public function test_bot_cities_can_be_filtered_by_q(): void
    {
        $this->insertCity('Москва', 'moskva', 'active', 37.6176, 55.7558);
        $this->insertCity('Санкт-Петербург', 'spb', 'active', 30.3351, 59.9343);

        $response = $this
            ->withHeader('Authorization', 'Bearer ' . $this->botToken)
            ->getJson('/api/bot/cities?q=моск');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Москва')
            ->assertJsonPath('data.0.slug', 'moskva');

        $response->assertJsonMissing([
            'slug' => 'spb',
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
