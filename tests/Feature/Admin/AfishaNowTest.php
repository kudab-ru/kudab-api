<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Interest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AfishaNowTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    private int $communityId;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        DB::insert(
            "INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES ('Воронеж','RU', ST_SetSRID(ST_Point(39.2,51.66),4326),'active','voronezh', now(), now())"
        );
        $this->cityId = (int) DB::table('cities')->where('slug', 'voronezh')->value('id');
        $this->communityId = (int) DB::table('communities')->insertGetId([
            'name' => 'Паблик', 'city_id' => $this->cityId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_snapshot_is_kept_until_refresh_and_genre_counts_into_its_theme(): void
    {
        $music = Interest::create(['name' => 'Музыка', 'slug' => 'music']);
        $rock = Interest::create(['name' => 'Рок', 'slug' => 'rock', 'parent_id' => $music->id]);
        // событие висит только на жанре, в теме «Музыка» его всё равно надо посчитать
        DB::table('event_interest')->insert(['event_id' => $this->futureEvent('Рок-концерт'), 'interest_id' => $rock->id]);

        $this->getJson('/api/admin/afisha-now')
            ->assertOk()
            ->assertJsonPath('data.events', 1)
            ->assertJsonPath('data.themes.0.slug', 'music')
            ->assertJsonPath('data.themes.0.count', 1);

        $this->futureEvent('Спектакль');

        $this->getJson('/api/admin/afisha-now')->assertJsonPath('data.events', 1);
        $this->postJson('/api/admin/afisha-now/refresh')->assertOk()->assertJsonPath('data.events', 2);
    }

    public function test_daily_series_counts_once_and_kids_events_are_counted(): void
    {
        $groupId = (int) DB::table('event_groups')->insertGetId([
            'community_id' => $this->communityId, 'city_id' => $this->cityId,
            'group_key' => 'vystavka', 'title_norm' => 'выставка', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (range(3, 9) as $day) {
            $this->futureEvent('Выставка', $day, ['event_group_id' => $groupId]);
        }
        $this->futureEvent('Концерт');
        // детское лента сайта прячет из общей выдачи, а в афише оно есть
        $this->futureEvent('Кукольный спектакль', 4, ['audience' => 'kids']);

        $this->postJson('/api/admin/afisha-now/refresh')
            ->assertOk()
            ->assertJsonPath('data.events', 3)
            ->assertJsonPath('data.sessions', 9)
            ->assertJsonPath('data.shapes.daily', 1)
            ->assertJsonPath('data.shapes.once', 2);
    }

    private function futureEvent(string $title, int $inDays = 10, array $extra = []): int
    {
        return (int) DB::table('events')->insertGetId($extra + [
            'community_id' => $this->communityId,
            'city_id' => $this->cityId,
            'title' => $title,
            'status' => 'active',
            'start_time' => DB::raw("now() + interval '{$inDays} days'"),
            'start_date' => DB::raw("(now() + interval '{$inDays} days')::date"),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
