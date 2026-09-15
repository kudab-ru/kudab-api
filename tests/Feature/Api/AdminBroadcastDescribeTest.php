<?php

namespace Tests\Feature\Api;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Текст анонса пишется по заявке, а не пачкой на всё подряд.
 *
 * Раньше анонсы писались ночью всем событиям горизонта — платили за два
 * порядка лишнего: в канал уходит десяток постов в неделю. Теперь текст
 * появляется перед самой публикацией, а человек может попросить написать его
 * прямо сейчас и приложить пожелание. Здесь проверяется сторона kudab-api:
 * заявка, её видимость в ленте и сборка подписи после ответа парсера.
 */
class AdminBroadcastDescribeTest extends TestCase
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

    public function test_describe_registers_request_with_hint(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makeItem($broadcast->id);

        $res = $this->postJson("/api/admin/broadcast/items/{$item->id}/describe", [
            'hint' => 'упомяни бесплатный вход',
        ]);

        $res->assertOk();
        $this->assertTrue($res->json('data.text_pending'), 'лента должна показать, что текст пишется');

        $item->refresh();
        $this->assertNotNull($item->text_requested_at, 'заявка должна быть видна парсеру');
        $this->assertSame('упомяни бесплатный вход', $item->text_hint);
    }

    /** Пустое пожелание — не пожелание: пустую строку в промпт слать незачем. */
    public function test_empty_hint_is_stored_as_null(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makeItem($broadcast->id);

        $this->postJson("/api/admin/broadcast/items/{$item->id}/describe", ['hint' => '   '])
            ->assertOk();

        $this->assertNull($item->fresh()->text_hint);
    }

    /** У портрета площадки текста от модели нет — его правят руками. */
    public function test_venue_portrait_cannot_be_described(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makeItem($broadcast->id);
        $item->kind = TelegramChatBroadcastItem::KIND_VENUE;
        $item->event_id = null;
        $item->save();

        $this->postJson("/api/admin/broadcast/items/{$item->id}/describe")
            ->assertStatus(422);

        $this->assertNull($item->fresh()->text_requested_at);
    }

    /** Опубликованному посту текст переписывать поздно. */
    public function test_posted_item_cannot_be_described(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makeItem($broadcast->id);
        $item->status = TelegramChatBroadcastItem::STATUS_POSTED;
        $item->posted_at = now();
        $item->save();

        $this->postJson("/api/admin/broadcast/items/{$item->id}/describe")
            ->assertStatus(409);
    }

    /**
     * Лента честно говорит, написан анонс или ещё нет.
     *
     * Без этого пост в очереди выглядит так, будто текст к нему уже плохой, —
     * хотя он просто ещё не написан и напишется перед публикацией.
     */
    public function test_feed_tells_whether_ai_text_is_written(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makeItem($broadcast->id);

        $row = $this->feedRow($broadcast->id, $item->id);
        $this->assertFalse($row['has_ai_text'], 'анонса ещё нет');

        DB::table('events')->where('id', $item->event_id)->update(['tg_description' => 'Свежий анонс']);

        $row = $this->feedRow($broadcast->id, $item->id);
        $this->assertTrue($row['has_ai_text'], 'анонс написан');
    }

    /**
     * Парсер снял устаревшую подпись — лента собирает новую при чтении.
     *
     * Подпись собирают шаблоны kudab-api, парсеру их не достать: всё, что он
     * может, — снять текст, собранный со старым описанием. Если api не соберёт
     * подпись заново, админка покажет пустой пост.
     */
    public function test_feed_rebuilds_caption_dropped_by_parser(): void
    {
        $this->seedTemplate();

        $broadcast = $this->makeChannel();
        $item = $this->makeItem($broadcast->id);

        // Ровно то, что делает parser:tg:describe-due после удачной генерации.
        DB::table('events')->where('id', $item->event_id)->update(['tg_description' => 'Свежий анонс от парсера']);
        DB::table('telegram.chat_broadcast_items')->where('id', $item->id)
            ->update(['caption' => null, 'caption_source' => null]);

        $row = $this->feedRow($broadcast->id, $item->id);

        $this->assertStringContainsString('Свежий анонс от парсера', (string) $row['caption']);
        $this->assertSame('template', $row['caption_source']);
    }

    /**
     * Выключатель анонсов ИИ: по умолчанию включено, выключается настройкой.
     *
     * Умолчание важно: до появления настройки анонсы писались всем каналам, и
     * выкатка не должна молча выключить их там, где на них рассчитывают.
     */
    public function test_ai_text_is_on_by_default_and_can_be_turned_off(): void
    {
        $broadcast = $this->makeChannel();

        $res = $this->getJson('/api/admin/broadcast/channels');
        $res->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $broadcast->id);
        $this->assertTrue($row['ai_text'], 'по умолчанию анонсы пишутся');
        $this->assertGreaterThan(0, $row['text_lead_minutes'], 'лента показывает, за сколько до слота');

        $this->patchJson("/api/admin/broadcast/channels/{$broadcast->id}", ['ai_text' => false])
            ->assertOk();

        $this->assertFalse($broadcast->fresh()->ai_text);
    }

    /** Заявку из админки выключатель не отменяет: её подаёт человек. */
    public function test_manual_request_works_even_with_ai_text_off(): void
    {
        $broadcast = $this->makeChannel();
        $broadcast->ai_text = false;
        $broadcast->save();

        $item = $this->makeItem($broadcast->id);

        $this->postJson("/api/admin/broadcast/items/{$item->id}/describe")->assertOk();

        $this->assertNotNull($item->fresh()->text_requested_at);
    }

    /**
     * «Отправить сейчас» по посту без анонса даёт время его написать.
     *
     * Иначе пост уходит через считаные секунды, писать текст некогда, и в канал
     * уезжает сырое описание из парсера — а человек нажимал кнопку именно
     * потому, что хотел опубликовать этот пост.
     */
    public function test_publish_now_waits_for_the_text_when_there_is_none(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makeItem($broadcast->id);

        $res = $this->postJson("/api/admin/broadcast/items/{$item->id}/publish-now");

        $res->assertOk();
        $this->assertTrue($res->json('data.waiting_for_text'));

        $item->refresh();
        $this->assertNotNull($item->text_requested_at, 'парсер должен увидеть заявку');
        $this->assertNotNull($item->planned_at, 'пост придержан на время генерации');
        $this->assertTrue($item->planned_at->isFuture());
    }

    /** Анонс уже есть — ждать нечего, пост уходит сразу. */
    public function test_publish_now_does_not_wait_when_text_is_ready(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makeItem($broadcast->id);
        DB::table('events')->where('id', $item->event_id)->update(['tg_description' => 'Готовый анонс']);

        $res = $this->postJson("/api/admin/broadcast/items/{$item->id}/publish-now");

        $res->assertOk();
        $this->assertFalse($res->json('data.waiting_for_text'));
        $this->assertNull($item->fresh()->planned_at);
    }

    /** Канал отказался от анонсов — ждать тем более нечего. */
    public function test_publish_now_does_not_wait_when_ai_text_is_off(): void
    {
        $broadcast = $this->makeChannel();
        $broadcast->ai_text = false;
        $broadcast->save();
        $item = $this->makeItem($broadcast->id);

        $res = $this->postJson("/api/admin/broadcast/items/{$item->id}/publish-now");

        $res->assertOk();
        $this->assertFalse($res->json('data.waiting_for_text'));
        $this->assertNull($item->fresh()->planned_at);
    }

    /**
     * Посты, ушедшие без анонса, становятся видимой проблемой канала.
     *
     * Молчащая генерация раньше никак себя не проявляла: пост уходил с сырым
     * описанием, и понять это можно было только по тому, что пометка «написан
     * ИИ» так и не появилась.
     */
    public function test_channel_complains_when_posts_go_out_without_ai_text(): void
    {
        $broadcast = $this->makeChannel();
        foreach ([1, 2] as $n) {
            $item = $this->makeItem($broadcast->id);
            $item->status = TelegramChatBroadcastItem::STATUS_POSTED;
            $item->posted_at = now()->subDays($n);
            $item->save();
        }

        $res = $this->getJson("/api/admin/broadcast/channels/{$broadcast->id}/feed");

        $res->assertOk();
        $texts = collect($res->json('data.channel.problems'))->pluck('text')->implode(' | ');
        $this->assertStringContainsString('без анонса ИИ', $texts);
    }

    /** Один анонс на все даты спектакля — это надо показать до клика. */
    public function test_feed_shows_how_many_repeats_share_the_text(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makeItem($broadcast->id);

        $groupId = DB::table('event_groups')->insertGetId([
            'community_id' => DB::table('events')->where('id', $item->event_id)->value('community_id'),
            'city_id' => $this->city()->id,
            'group_key' => 'grp-'.uniqid(),
            'title_norm' => 'повтор',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('events')->where('id', $item->event_id)->update(['event_group_id' => $groupId]);
        // второй показ того же спектакля — он делит анонс с первым
        $twin = $this->makeItem($broadcast->id);
        DB::table('events')->where('id', $twin->event_id)->update(['event_group_id' => $groupId]);

        $row = $this->feedRow($broadcast->id, $item->id);

        $this->assertSame(2, $row['text_repeats']);
    }

    /** @return array<string, mixed> */
    private function feedRow(int $broadcastId, int $itemId): array
    {
        $res = $this->getJson("/api/admin/broadcast/channels/{$broadcastId}/feed");
        $res->assertOk();

        $row = collect($res->json('data.items'))->firstWhere('id', $itemId);
        $this->assertNotNull($row, 'пост должен быть в ленте');

        return $row;
    }

    private function seedTemplate(): void
    {
        DB::table('telegram.message_templates')->insert([
            'code' => 'basic',
            'locale' => 'ru',
            'name' => 'Базовый',
            'body' => "🎟 <b>{title}</b>\n{description}",
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeChannel(): TelegramChatBroadcast
    {
        $owner = TelegramUser::create(['telegram_id' => 8307201746]);

        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999003;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->save();

        return TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic'],
        ]);
    }

    private function makeItem(int $broadcastId): TelegramChatBroadcastItem
    {
        $city = $this->city();
        $community = \App\Models\Community::create([
            'name' => 'Организатор '.uniqid(),
            'city_id' => $city->id,
        ]);

        $event = new \App\Models\Event;
        $event->community_id = $community->id;
        $event->title = 'Событие '.uniqid();
        $event->status = 'active';
        $event->city_id = $city->id;
        $event->start_time = now()->addDays(2);
        $event->start_date = now()->addDays(2)->toDateString();
        $event->end_time = now()->addDays(2)->addHours(2);
        $event->description = 'сырое описание из парсера';
        $event->save();

        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcastId;
        $item->kind = TelegramChatBroadcastItem::KIND_EVENT;
        $item->event_id = $event->id;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->caption = 'текст';
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_TEMPLATE;
        $item->publish_at = now()->addDay()->setTime(10, 0);
        $item->save();

        return $item;
    }

    private function city(): \App\Models\City
    {
        $existing = \App\Models\City::query()->where('slug', 'voronezh-test')->first();
        if ($existing) {
            return $existing;
        }

        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh-test', now(), now()]
        );

        return \App\Models\City::query()->where('slug', 'voronezh-test')->firstOrFail();
    }
}
