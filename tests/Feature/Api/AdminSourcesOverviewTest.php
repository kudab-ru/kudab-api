<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Единый список источников для админки.
 *
 * Два свойства, ради которых страницу переделывали:
 *  - считаются КАРТОЧКИ, а не строки событий (пять сеансов одного спектакля —
 *    одна карточка). Замер на живой базе: у Я.Афиши 278 строк против 111
 *    карточек; из первого числа владелец сделает вывод «сайты не нужны»,
 *    из второго — обратный;
 *  - выключенный источник не показывается здоровым. Прежний светофор смотрел
 *    только на последние заходы и не смотрел на тумблер, поэтому выключенный
 *    сайт со старыми удачными заходами горел зелёным.
 */
class AdminSourcesOverviewTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        Sanctum::actingAs($user);

        DB::insert(
            "INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES ('Воронеж','RU', ST_SetSRID(ST_Point(39.2,51.66),4326),'active','voronezh', now(), now())"
        );
        $this->cityId = (int) DB::table('cities')->where('slug', 'voronezh')->value('id');

        foreach ([[3, 'site'], [1, 'vk']] as [$id, $slug]) {
            DB::table('social_networks')->updateOrInsert(['id' => $id], ['slug' => $slug, 'name' => $slug]);
        }
    }

    private function siteSource(string $slug, string $name, bool $enabled): int
    {
        DB::table('source_profiles')->insert([
            'slug' => $slug, 'name' => $name, 'listing_url' => "https://{$slug}.ru/afisha",
            'event_url_regex' => '~^x$~', 'enabled' => $enabled,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $communityId = (int) DB::table('communities')->insertGetId([
            'name' => $name, 'city_id' => $this->cityId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('community_social_links')->insertGetId([
            'community_id' => $communityId, 'social_network_id' => 3,
            'external_community_id' => $slug, 'status' => 'active',
            'url' => "https://{$slug}.ru/afisha",
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Событие с группой: несколько сеансов одной постановки. */
    private function event(int $linkId, string $title, ?int $groupId, int $inDays): void
    {
        $communityId = (int) DB::table('community_social_links')->where('id', $linkId)->value('community_id');

        $eventId = (int) DB::table('events')->insertGetId([
            'community_id' => $communityId, 'title' => $title, 'status' => 'active',
            'city_id' => $this->cityId, 'event_group_id' => $groupId,
            'start_time' => now()->addDays($inDays), 'start_date' => now()->addDays($inDays)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('event_sources')->insert([
            'event_id' => $eventId, 'social_link_id' => $linkId, 'source' => 'site',
            'post_external_id' => 'p'.$eventId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function row(string $name): ?array
    {
        $data = $this->getJson('/api/admin/sources/overview')->assertOk()->json('data');

        foreach ($data as $r) {
            if ($r['name'] === $name) {
                return $r;
            }
        }

        return null;
    }

    /** Ради этого всё: пять сеансов одной постановки — одна карточка. */
    public function test_sessions_of_one_show_count_as_one_card(): void
    {
        $link = $this->siteSource('teatr', 'Театр', true);
        $communityId = (int) DB::table('community_social_links')->where('id', $link)->value('community_id');
        $groupId = (int) DB::table('event_groups')->insertGetId([
            'group_key' => 'g1', 'community_id' => $communityId, 'title_norm' => 'tartyuf',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([3, 4, 5, 6, 7] as $d) {
            $this->event($link, 'Тартюф', $groupId, $d);
        }

        $this->assertSame(1, $this->row('Театр')['cards_ahead']);
    }

    /** События без группы считаются по отдельности. */
    public function test_ungrouped_events_count_separately(): void
    {
        $link = $this->siteSource('kassir', 'Кассир', true);
        $this->event($link, 'Пикник', null, 3);
        $this->event($link, 'Гагарина', null, 5);

        $this->assertSame(2, $this->row('Кассир')['cards_ahead']);
    }

    /** Выключенный источник не бывает «работает», даже с событиями. */
    public function test_disabled_source_is_never_working(): void
    {
        $link = $this->siteSource('spyashiy', 'Спящий', false);
        $this->event($link, 'Концерт', null, 3);

        $row = $this->row('Спящий');

        $this->assertSame('off', $row['state']);
        $this->assertStringContainsString('Выключен', $row['state_note']);
    }

    /** Источник, который ни разу не собирал, честно так и говорит. */
    public function test_never_collected_source_says_so(): void
    {
        $this->siteSource('novyi', 'Новый', true);

        $row = $this->row('Новый');

        $this->assertSame('new', $row['state']);
        $this->assertSame(0, $row['cards_ahead']);
    }

    /** Прошедшие события в «впереди» не попадают. */
    public function test_past_events_are_not_ahead(): void
    {
        $link = $this->siteSource('proshloe', 'Прошлое', true);
        $this->event($link, 'Вчерашний концерт', null, -5);

        $this->assertSame(0, $this->row('Прошлое')['cards_ahead']);
    }

    public function test_requires_superadmin(): void
    {
        Role::findOrCreate('admin', 'sanctum');
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('admin', 'sanctum'));
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/sources/overview')->assertForbidden();
    }
}
