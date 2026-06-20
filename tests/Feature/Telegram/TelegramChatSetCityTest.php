<?php

namespace Tests\Feature\Telegram;

use App\Models\City;
use App\Models\TelegramChat;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Сеттер города каналу автопостинга (TelegramChatService::setChatCity / forceSetChatCity).
 */
class TelegramChatSetCityTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TelegramChatService
    {
        return app(TelegramChatService::class);
    }

    public function test_owner_sets_city_by_slug(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh');
        $chat = $this->ownedChat(700, -100700);

        $result = $this->service()->setChatCity(700, -100700, 'voronezh');

        $this->assertSame($city->id, $result->city_id);
        $this->assertSame($city->id, $chat->fresh()->city_id);
    }

    public function test_owner_sets_city_by_id(): void
    {
        $city = $this->insertCity('Москва', 'moskva');
        $this->ownedChat(701, -100701);

        $result = $this->service()->setChatCity(701, -100701, (string) $city->id);

        $this->assertSame($city->id, $result->city_id);
    }

    public function test_unknown_city_throws(): void
    {
        $this->ownedChat(702, -100702);

        $this->expectException(\RuntimeException::class);
        $this->service()->setChatCity(702, -100702, 'atlantida');
    }

    public function test_non_owner_throws(): void
    {
        $this->insertCity('Воронеж', 'voronezh');
        $this->ownedChat(703, -100703);     // владелец 703
        $this->boundTelegramUser(999);      // другой пользователь (привязан, роль user)

        $this->expectException(\RuntimeException::class);
        $this->service()->setChatCity(999, -100703, 'voronezh');
    }

    public function test_force_set_ignores_ownership(): void
    {
        $city = $this->insertCity('Воронеж', 'voronezh');
        $chat = $this->ownedChat(704, -100704);

        $result = $this->service()->forceSetChatCity($chat, 'voronezh');

        $this->assertSame($city->id, $result->city_id);
    }

    // ----------------------------------------------------------------

    private function insertCity(string $name, string $slug): City
    {
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            [$name, 'RU', 39.2, 51.6, 'active', $slug, now(), now()]
        );

        return City::query()->where('slug', $slug)->firstOrFail();
    }

    private function boundTelegramUser(int $telegramId): TelegramUser
    {
        $webUser = User::create([
            'name'     => 'U' . $telegramId,
            'email'    => 'u' . $telegramId . '@example.test',
            'password' => bcrypt('secret-' . $telegramId),
        ]);

        $tu = new TelegramUser();
        $tu->telegram_id = $telegramId;
        $tu->user()->associate($webUser);
        $tu->save();

        return $tu;
    }

    private function ownedChat(int $ownerTelegramId, int $telegramChatId): TelegramChat
    {
        $owner = $this->boundTelegramUser($ownerTelegramId);

        $chat = new TelegramChat();
        $chat->telegram_chat_id = $telegramChatId;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->save();

        return $chat;
    }
}
