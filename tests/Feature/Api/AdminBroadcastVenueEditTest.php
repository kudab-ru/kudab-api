<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Правка портрета площадки.
 *
 * Она была выключена в интерфейсе не из осторожности: вся сборка текста шла
 * через событие, которого у портрета нет. Пустая правка сохраняла NULL, бот на
 * пустом тексте бросал задачу, ничего не помечая, и «один портрет в полёте»
 * после этого закрывал постановку следующего навсегда.
 */
class AdminBroadcastVenueEditTest extends TestCase
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

    public function test_empty_caption_rebuilds_portrait_text(): void
    {
        [$item] = $this->portraitItem();
        $item->caption = 'мой текст';
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_MANUAL;
        $item->save();

        $this->patchJson("/api/admin/broadcast/items/{$item->id}", ['caption' => ''])
            ->assertOk();

        $fresh = $item->fresh();
        $this->assertNotNull($fresh->caption, 'текст не обнулён');
        $this->assertStringContainsString('Зелёный театр', (string) $fresh->caption);
        $this->assertSame(TelegramChatBroadcastItem::CAPTION_TEMPLATE, $fresh->caption_source);
    }

    public function test_moving_portrait_day_keeps_text(): void
    {
        [$item] = $this->portraitItem();

        $this->patchJson("/api/admin/broadcast/items/{$item->id}", [
            'publish_at' => now()->addDays(3)->setTime(10, 0)->toIso8601String(),
        ])->assertOk();

        $fresh = $item->fresh();
        $this->assertNotNull($fresh->publish_at);
        $this->assertNotNull($fresh->caption, 'перенос дня не должен обнулять текст портрета');
    }

    public function test_portrait_photos_can_be_chosen(): void
    {
        [$item, $venue] = $this->portraitItem();

        $res = $this->getJson("/api/admin/broadcast/channels/{$item->broadcast_id}/feed");
        $res->assertOk();
        $row = collect($res->json('data.items'))->firstWhere('id', $item->id);

        // Белый список для портрета раньше был пуст по определению — любой
        // выбор состава упирался в 422.
        $this->assertIsArray($row['photo_candidates']);
    }

    public function test_portrait_is_offered_among_suggestions(): void
    {
        [$item, $venue] = $this->portraitItem();

        // Площадка, чей портрет уже стоит в ленте, из ротации исключена —
        // предлагаться должна другая.
        $other = new Venue;
        $other->city_id = $venue->city_id;
        $other->name = 'Книжный клуб';
        $other->slug = 'knizhny-klub';
        $other->status = 'active';
        $other->tg_portrait = 'Второй этаж, кофе и лекции.';
        $other->save();
        // Площадка без единой фотографии в ротацию не идёт: портрет без
        // картинки — это абзац прозы. Фикстуре фото нужно, иначе тест проверял
        // бы отсев, а не то, ради чего написан.
        $this->givePhoto($other);

        $res = $this->getJson("/api/admin/broadcast/channels/{$item->broadcast_id}/suggestions");

        $res->assertOk();
        $portrait = collect($res->json('data'))->firstWhere('kind', 'venue');

        $this->assertNotNull($portrait, 'портрет — такой же кандидат на пустой слот');
        $this->assertSame($other->id, $portrait['venue_id'], 'та, что уже в ленте, не предлагается');
        $this->assertNull($portrait['event_id'], 'у портрета нет события');
        $this->assertStringContainsString('Книжный клуб', (string) $portrait['title']);
        $this->assertNotEmpty($portrait['reasons']);
    }

    public function test_portrait_can_be_enqueued_from_admin(): void
    {
        [$item, $venue] = $this->portraitItem();
        $day = now()->addDays(3)->setTime(10, 0);

        $other = new Venue;
        $other->city_id = $venue->city_id;
        $other->name = 'Книжный клуб';
        $other->slug = 'knizhny-klub';
        $other->status = 'active';
        $other->tg_portrait = 'Второй этаж, кофе и лекции.';
        $other->save();
        // Площадка без единой фотографии в ротацию не идёт: портрет без
        // картинки — это абзац прозы. Фикстуре фото нужно, иначе тест проверял
        // бы отсев, а не то, ради чего написан.
        $this->givePhoto($other);

        $res = $this->postJson("/api/admin/broadcast/channels/{$item->broadcast_id}/enqueue-venue", [
            'venue_id' => $other->id,
            'publish_at' => $day->toIso8601String(),
        ]);

        $res->assertOk();
        $this->assertSame('venue', $res->json('data.kind'));
        $this->assertNotNull($res->json('data.publish_at'));
        $this->assertStringContainsString('Книжный клуб', (string) $res->json('data.caption'));
    }

    /** Второй портрет той же площадки в ленту не ставится. */
    public function test_second_portrait_of_same_venue_is_refused(): void
    {
        [$item, $venue] = $this->portraitItem();

        $res = $this->postJson("/api/admin/broadcast/channels/{$item->broadcast_id}/enqueue-venue", [
            'venue_id' => $venue->id,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('уже стоит в ленте', (string) $res->json('error'));
        $this->assertSame(1, TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $item->broadcast_id)
            ->where('kind', TelegramChatBroadcastItem::KIND_VENUE)
            ->count());
    }

    /** Без дня постановка сама берёт ближайший свободный слот. */
    public function test_portrait_without_day_gets_a_slot(): void
    {
        [$item, $venue] = $this->portraitItem();

        $other = new Venue;
        $other->city_id = $venue->city_id;
        $other->name = 'Книжный клуб';
        $other->slug = 'knizhny-klub';
        $other->status = 'active';
        $other->tg_portrait = 'Второй этаж, кофе и лекции.';
        $other->save();
        // Площадка без единой фотографии в ротацию не идёт: портрет без
        // картинки — это абзац прозы. Фикстуре фото нужно, иначе тест проверял
        // бы отсев, а не то, ради чего написан.
        $this->givePhoto($other);

        $res = $this->postJson("/api/admin/broadcast/channels/{$item->broadcast_id}/enqueue-venue", [
            'venue_id' => $other->id,
        ]);

        $res->assertOk();
        // Без момента портрет уезжал бы первым же тиком в произвольный час.
        $this->assertNotNull($res->json('data.publish_at'));
    }

    /**
     * Подпись длиннее лимита Telegram не принимается.
     *
     * Пост длиннее не падает: альбом молча отбивается, и в канал уходит голый
     * текст. Отказать честнее, чем отправить пост без картинок.
     */
    public function test_caption_over_telegram_limit_is_refused(): void
    {
        [$item] = $this->portraitItem();

        $this->patchJson("/api/admin/broadcast/items/{$item->id}", [
            'caption' => str_repeat('а', 1025),
        ])->assertStatus(422);

        $this->assertSame('исходный текст портрета', (string) $item->fresh()->caption);
    }

    /** @return array{0: TelegramChatBroadcastItem, 1: Venue} */
    private function portraitItem(): array
    {
        $city = $this->insertCity();

        $venue = new Venue;
        $venue->city_id = $city->id;
        $venue->name = 'Зелёный театр';
        $venue->slug = 'zeleny-teatr';
        $venue->status = 'active';
        $venue->tg_portrait = 'Открытая летняя сцена: концерты под небом.';
        $venue->save();

        $owner = TelegramUser::create(['telegram_id' => 8307201745]);

        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999005;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->city_id = $city->id;
        $chat->save();

        $broadcast = TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic'],
        ]);

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_VENUE;
        $item->venue_id = $venue->id;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->caption = 'исходный текст портрета';
        $item->publish_at = now()->addDay()->setTime(10, 0);
        $item->save();

        return [$item, $venue];
    }

    private function insertCity(): City
    {
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh', now(), now()]
        );

        return City::query()->where('slug', 'voronezh')->firstOrFail();
    }

    /** Фотографии портрет берёт у событий площадки — другого источника нет. */
    private function givePhoto(Venue $venue): void
    {
        $community = \App\Models\Community::create([
            'name' => 'Фотоисточник '.uniqid(),
            'city_id' => $venue->city_id,
        ]);

        $event = new \App\Models\Event;
        $event->community_id = $community->id;
        $event->title = 'Прошлое событие '.uniqid();
        $event->status = 'active';
        $event->city_id = $venue->city_id;
        $event->venue_id = $venue->id;
        $event->start_time = now()->subDays(30);
        $event->start_date = $event->start_time->toDateString();
        $event->save();

        $networkId = DB::table('social_networks')->value('id')
            ?? DB::table('social_networks')->insertGetId([
                'name' => 'VK', 'slug' => 'vk', 'created_at' => now(), 'updated_at' => now(),
            ]);
        $linkId = DB::table('community_social_links')->insertGetId([
            'community_id' => $community->id,
            'social_network_id' => $networkId,
            'url' => 'https://vk.com/'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('event_sources')->insert([
            'event_id' => $event->id,
            'social_link_id' => $linkId,
            'source' => 'vk',
            'post_external_id' => 'post-'.uniqid(),
            'images' => json_encode(['https://example.test/venue-'.$venue->id.'.jpg']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
