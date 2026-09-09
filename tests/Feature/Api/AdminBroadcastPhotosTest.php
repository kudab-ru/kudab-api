<?php

namespace Tests\Feature\Api;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ручной состав картинок в посте.
 *
 * Состав альбома был неуправляем: брались первые три картинки события и
 * отсеивались только точные повторы адреса. Один и тот же кадр, приехавший с
 * парсинга под разными URL, уходил в канал дважды, и поправить это можно было
 * лишь через данные самого события.
 *
 * NULL = «собрать автоматически», массив = ровно эти картинки. Пустой массив
 * — осознанное «без картинок», и это не то же самое, что NULL.
 */
class AdminBroadcastPhotosTest extends TestCase
{
    use RefreshDatabase;

    private TelegramChatBroadcastItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        Sanctum::actingAs($user);

        $chat = new TelegramChat;
        $chat->telegram_chat_id = -100777;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->save();

        $broadcast = new TelegramChatBroadcast;
        $broadcast->chat_id = $chat->id;
        $broadcast->enabled = true;
        $broadcast->settings = ['period' => 'daily_10', 'template_code' => 'basic'];
        $broadcast->save();

        // Событие без картинок: список кандидатов пуст, и это ровно тот
        // случай, в котором любая присланная ссылка должна быть отвергнута.
        $this->item = new TelegramChatBroadcastItem;
        $this->item->broadcast_id = $broadcast->id;
        $this->item->event_id = null;
        $this->item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $this->item->save();
    }

    private function url(): string
    {
        return '/api/admin/broadcast/items/'.$this->item->id;
    }

    public function test_rejects_urls_that_are_not_event_photos(): void
    {
        // Без этой проверки ручкой можно было бы отправить в канал любую
        // чужую картинку — ошибка всплыла бы уже при публикации.
        $res = $this->patchJson($this->url(), [
            'photo_urls' => ['https://example.com/чужая.jpg'],
        ]);

        $res->assertStatus(422);
        $this->assertNull($this->item->fresh()->photo_urls);
    }

    public function test_empty_array_is_a_deliberate_choice_without_photos(): void
    {
        $res = $this->patchJson($this->url(), ['photo_urls' => []]);

        $res->assertOk();
        $res->assertJsonPath('data.photos_manual', true);
        $res->assertJsonPath('data.photos', []);

        // Именно массив, а не NULL: «без картинок» должно пережить пересборку.
        $this->assertSame([], $this->item->fresh()->photo_urls);
    }

    public function test_null_returns_to_automatic_selection(): void
    {
        $this->item->photo_urls = [];
        $this->item->save();

        $res = $this->patchJson($this->url(), ['photo_urls' => null]);

        $res->assertOk();
        $res->assertJsonPath('data.photos_manual', false);
        $this->assertNull($this->item->fresh()->photo_urls);
    }

    public function test_leaves_photos_alone_when_field_is_absent(): void
    {
        $this->item->photo_urls = [];
        $this->item->save();

        $this->patchJson($this->url(), ['caption' => 'Просто правка текста'])->assertOk();

        // Правка текста не должна ронять ручной выбор картинок.
        $this->assertSame([], $this->item->fresh()->photo_urls);
    }

    public function test_refuses_to_edit_already_posted_item(): void
    {
        $this->item->posted_at = now();
        $this->item->save();

        $this->patchJson($this->url(), ['photo_urls' => []])->assertStatus(409);
        $this->assertNull($this->item->fresh()->photo_urls);
    }
}
