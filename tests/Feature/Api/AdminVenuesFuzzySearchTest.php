<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Нестрогий поиск по каталогу площадок.
 *
 * Строгий ILIKE не прощал ни опечатки, ни другого падежа, а имена в каталоге
 * приходят из чужих текстов и написаны как попало. Проверяем ровно то, ради
 * чего поиск переделан: находит с опечаткой и по части слова, не находит
 * постороннее, и буквальное попадание стоит выше похожего.
 */
class AdminVenuesFuzzySearchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperadmin(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        Sanctum::actingAs($user);
    }

    private function seedCity(): int
    {
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.66, 'active', 'voronezh', now(), now()]
        );

        return (int) DB::table('cities')->where('slug', 'voronezh')->value('id');
    }

    private function seedVenue(int $cityId, string $name, string $slug, ?string $address = null): int
    {
        return (int) DB::table('venues')->insertGetId([
            'name' => $name, 'slug' => $slug, 'status' => 'active', 'city_id' => $cityId,
            'address' => $address, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function search(string $q): array
    {
        return collect($this->getJson('/api/admin/venues?q='.urlencode($q))->json('data'))
            ->pluck('name')->all();
    }

    private function seedCatalog(): void
    {
        $city = $this->seedCity();
        $this->seedVenue($city, 'Музей-заповедник «Дивногорье»', 'divnogorye');
        $this->seedVenue($city, 'Никитинский театр', 'nikitinsky', 'ул. Бакунина, 2');
        $this->seedVenue($city, 'Парк Динамо', 'park-dinamo');
    }

    public function test_exact_substring_still_works(): void
    {
        $this->actingAsSuperadmin();
        $this->seedCatalog();

        $this->assertContains('Никитинский театр', $this->search('никитин'));
    }

    /** Ради этого всё и затевалось: одна перепутанная буква не должна обнулять поиск. */
    public function test_typo_is_forgiven(): void
    {
        $this->actingAsSuperadmin();
        $this->seedCatalog();

        $this->assertContains('Музей-заповедник «Дивногорье»', $this->search('дивнагорье'));
    }

    /** Другой падеж — тот же случай: «никитинског» строгому ILIKE не по зубам. */
    public function test_other_word_form_is_found(): void
    {
        $this->actingAsSuperadmin();
        $this->seedCatalog();

        $this->assertContains('Никитинский театр', $this->search('никитинског'));
    }

    public function test_unrelated_query_finds_nothing(): void
    {
        $this->actingAsSuperadmin();
        $this->seedCatalog();

        $this->assertSame([], $this->search('филармония'));
    }

    /** Буквальное попадание должно стоять выше похожего, иначе сосед лезет вперёд. */
    public function test_literal_match_outranks_similar(): void
    {
        $this->actingAsSuperadmin();
        $city = $this->seedCity();
        $this->seedVenue($city, 'Театр драмы', 'teatr-dramy');
        $this->seedVenue($city, 'Театральная площадь', 'teatralnaya');

        $names = $this->search('театральная');

        $this->assertNotEmpty($names);
        $this->assertSame('Театральная площадь', $names[0]);
    }

    public function test_address_is_searched_too(): void
    {
        $this->actingAsSuperadmin();
        $this->seedCatalog();

        $this->assertContains('Никитинский театр', $this->search('бакунина'));
    }

    /** Служебные символы LIKE не должны превращать запрос в «найти всё». */
    public function test_percent_sign_does_not_match_everything(): void
    {
        $this->actingAsSuperadmin();
        $this->seedCatalog();

        $this->assertSame([], $this->search('%'));
    }
}
