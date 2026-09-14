<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Город канала задаётся из админки.
 *
 * До этого задать его можно было только выключенной панелью бота или CLI,
 * а канал без города молчит: подбор событий пропускает его со skipped_no_city.
 * Две ловушки, ради которых здесь тесты: city_id вне $fillable у модели чата
 * (наивный update() вернул бы 200 и не изменил ничего), и резолв города берёт
 * только активные — отключённый в списке быть не должен.
 */
class AdminBroadcastCityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        Sanctum::actingAs($user);
    }

    public function test_channels_meta_lists_only_active_cities(): void
    {
        $active = $this->insertCity('Воронеж', 'voronezh', 'active');
        $this->insertCity('Тамбов', 'tambov', 'disabled');
        $this->makeChannel($active->id);

        $res = $this->getJson('/api/admin/broadcast/channels');

        $res->assertOk();
        $names = collect($res->json('meta.cities'))->pluck('name')->all();
        $this->assertContains('Воронеж', $names);
        $this->assertNotContains('Тамбов', $names, 'отключённый город запись не примет');
    }

    public function test_city_is_visible_and_can_be_changed(): void
    {
        $voronezh = $this->insertCity('Воронеж', 'voronezh', 'active');
        $moscow = $this->insertCity('Москва', 'moscow', 'active');
        $broadcast = $this->makeChannel($voronezh->id);

        $this->assertSame('Воронеж', $this->getJson('/api/admin/broadcast/channels')
            ->json('data.0.city_name'));

        $res = $this->patchJson("/api/admin/broadcast/channels/{$broadcast->id}", [
            'city_id' => $moscow->id,
        ]);

        $res->assertOk();
        $this->assertSame($moscow->id, $res->json('data.city_id'));
        // Главное: город действительно записался. city_id вне $fillable, и
        // наивный update() отдал бы тот же 200, ничего не изменив.
        $this->assertSame($moscow->id, (int) $broadcast->fresh()->chat->city_id);
    }

    public function test_inactive_city_is_refused(): void
    {
        $voronezh = $this->insertCity('Воронеж', 'voronezh', 'active');
        $off = $this->insertCity('Тамбов', 'tambov', 'disabled');
        $broadcast = $this->makeChannel($voronezh->id);

        $this->patchJson("/api/admin/broadcast/channels/{$broadcast->id}", [
            'city_id' => $off->id,
        ])->assertStatus(422);

        $this->assertSame($voronezh->id, (int) $broadcast->fresh()->chat->city_id);
    }

    public function test_city_cannot_be_cleared(): void
    {
        $voronezh = $this->insertCity('Воронеж', 'voronezh', 'active');
        $broadcast = $this->makeChannel($voronezh->id);

        $this->patchJson("/api/admin/broadcast/channels/{$broadcast->id}", [
            'city_id' => null,
        ])->assertStatus(422);

        $this->assertSame($voronezh->id, (int) $broadcast->fresh()->chat->city_id);
    }

    private function insertCity(string $name, string $slug, string $status): City
    {
        $now = now();

        // location — PostGIS NOT NULL без дефолта, через City::create город не
        // создать.
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            [$name, 'RU', 39.2, 51.6, $status, $slug, $now, $now]
        );

        return City::query()->where('slug', $slug)->firstOrFail();
    }

    private function makeChannel(?int $cityId): TelegramChatBroadcast
    {
        $owner = TelegramUser::create(['telegram_id' => 8307201745]);

        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999003;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->city_id = $cityId;
        $chat->save();

        return TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic'],
        ]);
    }
}
