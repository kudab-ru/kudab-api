<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueManager;
use App\Services\Analytics\MetrikaClient;
use App\Services\Vk\VkCommunityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class KulisyVenueTest extends TestCase
{
    use RefreshDatabase;

    private Venue $venue;

    private Venue $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kulisy.enabled' => true]);
        Role::findOrCreate('organizer', 'web');

        DB::insert("INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at) VALUES ('Воронеж', 'RU', ST_SetSRID(ST_Point(39.2, 51.66), 4326), 'active', 'voronezh', now(), now())");
        $city = City::where('slug', 'voronezh')->firstOrFail();
        DB::insert("INSERT INTO venues (city_id, name, slug, status, location, created_at, updated_at) VALUES (?, 'Рестобар «Литера»', 'litera', 'active', ST_SetSRID(ST_Point(39.2, 51.66), 4326), now(), now())", [$city->id]);
        DB::insert("INSERT INTO venues (city_id, name, slug, status, location, created_at, updated_at) VALUES (?, 'Ангар', 'angar', 'active', ST_SetSRID(ST_Point(39.2, 51.66), 4326), now(), now())", [$city->id]);
        $this->venue = Venue::where('slug', 'litera')->firstOrFail();
        $this->elsewhere = Venue::where('slug', 'angar')->firstOrFail();
    }

    private function group(string $url, int $here, int $elsewhere): void
    {
        $community = Community::create(['name' => $url, 'city_id' => $this->venue->city_id]);
        $vk = DB::table('social_networks')->where('slug', 'vk')->value('id')
            ?? DB::table('social_networks')->insertGetId(['name' => 'vk', 'slug' => 'vk', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('community_social_links')->insert(['community_id' => $community->id, 'social_network_id' => $vk, 'url' => $url, 'created_at' => now(), 'updated_at' => now()]);

        $venues = [...array_fill(0, $here, $this->venue->id), ...array_fill(0, $elsewhere, $this->elsewhere->id)];
        foreach ($venues as $i => $venueId) {
            (new Event)->forceFill(['community_id' => $community->id, 'venue_id' => $venueId, 'title' => 'Вечеринка '.$i, 'status' => 'active', 'city_id' => $this->venue->city_id, 'start_date' => now()->addDays(2)->toDateString()])->save();
        }
    }

    private function organizer(): User
    {
        $user = User::factory()->create();
        $user->assignRole('organizer');
        Sanctum::actingAs($user);

        return $user;
    }

    private function vkDescription(string $text): void
    {
        $this->app->instance(VkCommunityResolver::class, new class($text)
        {
            public function __construct(private string $text) {}

            public function resolve(string $url): array
            {
                return ['description' => $this->text];
            }
        });
    }

    public function test_code_in_group_description_confirms_the_venue(): void
    {
        $this->group('https://vk.com/club135849070', 1, 0);
        $this->organizer();
        $claim = $this->postJson("/api/kulisy/venues/{$this->venue->id}/claim")->assertOk()
            ->assertJsonPath('data.vk_url', 'https://vk.com/club135849070')->json('data');

        $this->vkDescription('Рестобар на Революции');
        $this->postJson("/api/kulisy/venues/{$this->venue->id}/claim/check")->assertStatus(422)->assertJsonPath('code', 'not_found');

        $this->vkDescription('Рестобар на Революции '.$claim['code']);
        $this->postJson("/api/kulisy/venues/{$this->venue->id}/claim/check")->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->app->instance(MetrikaClient::class, new class
        {
            public function visits(array $params): array
            {
                return ['totals' => [str_contains($params['date1'], now('Europe/Moscow')->subDays(29)->toDateString()) ? 245 : 88]];
            }
        });
        $this->getJson('/api/kulisy/cabinet')->assertOk()
            ->assertJsonPath('data.venue.name', 'Рестобар «Литера»')
            ->assertJsonPath('data.stats.month', 245)
            ->assertJsonPath('data.stats.week', 88)
            ->assertJsonPath('data.stats.upcoming', 1)
            ->assertJsonPath('data.stats.with_tickets', 0);
    }

    public function test_city_wide_group_cannot_confirm_the_venue(): void
    {
        $this->group('https://vk.com/afisha_vrn', 1, 2);
        $this->organizer();
        $claim = $this->postJson("/api/kulisy/venues/{$this->venue->id}/claim")->assertOk()
            ->assertJsonPath('data.vk_url', null)->json('data');

        $this->vkDescription('Вся афиша Воронежа '.$claim['code']);
        $this->postJson("/api/kulisy/venues/{$this->venue->id}/claim/check")->assertStatus(422)->assertJsonPath('code', 'no_group');
    }

    public function test_venue_confirmed_by_someone_else_cannot_be_claimed(): void
    {
        $other = User::factory()->create();
        VenueManager::create(['venue_id' => $this->venue->id, 'user_id' => $other->id, 'status' => 'confirmed', 'code' => 'kudab-AAAA']);
        $this->organizer();

        $this->postJson("/api/kulisy/venues/{$this->venue->id}/claim")->assertStatus(409)->assertJsonPath('code', 'taken');
    }

    public function test_manual_request_confirmed_in_admin_gives_the_venue(): void
    {
        $user = $this->organizer();
        $this->postJson("/api/kulisy/venues/{$this->venue->id}/claim")->assertOk();
        $this->postJson("/api/kulisy/venues/{$this->venue->id}/claim/manual")->assertOk()->assertJsonPath('data.status', 'manual');

        $lead = DB::table('organizer_leads')->first();
        $this->assertStringContainsString('Рестобар «Литера»', $lead->comment);

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('superadmin', 'web'));
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/organizer-leads/{$lead->id}/resolve", ['resolution' => 'connected'])->assertOk();

        $this->assertSame('confirmed', VenueManager::where('user_id', $user->id)->value('status'));
    }
}
