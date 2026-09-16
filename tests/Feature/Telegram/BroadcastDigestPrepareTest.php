<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Подборка собирается заранее, а ручной текст не пропадает молча.
 *
 * Два разных долга, которые чинятся вместе:
 *
 * 1. Состав и текст появлялись внутри доставки, и между «состав зафиксирован»
 *    и «пост в канале» проходили секунды (замер по записям 214 и 215: шесть и
 *    двадцать восемь). Посмотреть на состав было некогда.
 * 2. Кнопки «собрать заново» и «написать сейчас» затирали ручную подпись, не
 *    оставляя следа нигде: saveRevision звали только правка карточки и откат.
 */
class BroadcastDigestPrepareTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    private int $interestId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00', 'Europe/Moscow'));

        $this->cityId = $this->city();
        $this->interestId = (int) DB::table('interests')->insertGetId([
            'name' => 'Театр', 'slug' => 'theatre', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ───────────────── шаг 1: состав замерзает заранее ───────────────── */

    public function test_roster_is_frozen_a_day_before_the_slot(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }
        $item = $this->digestItem($broadcast, Carbon::now()->addHours(12));

        $this->artisan('broadcast:prepare-digests')->assertSuccessful();

        $roster = DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)->count();
        $this->assertSame(3, $roster, 'состав записан за 12 часов до слота, а не в момент отправки');
        $this->assertNotNull($item->fresh()->digest_meta, 'тема записана вместе с составом');
    }

    /** До горизонта — не трогаем: подборка, собранная за неделю, знает пятую часть афиши. */
    public function test_digest_beyond_the_horizon_is_left_alone(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }
        $item = $this->digestItem($broadcast, Carbon::now()->addDays(10));

        $this->artisan('broadcast:prepare-digests')->assertSuccessful();

        $this->assertSame(0, DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)->count());
    }

    /** Заявка в полёте — вторую не шлём: парсер ответит на первую. */
    public function test_does_not_ask_for_text_twice(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }
        $item = $this->digestItem($broadcast, Carbon::now()->addHours(12));
        $item->text_requested_at = Carbon::now()->subMinute();
        $item->save();

        $this->artisan('broadcast:prepare-digests')->assertSuccessful();

        $this->assertSame(0, DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)->count(), 'запись с заявкой пропускается целиком');
        $this->assertTrue(
            Carbon::parse($item->fresh()->text_requested_at)->equalTo(Carbon::now()->subMinute()),
            'время заявки не переписано',
        );
    }

    /** Отправленную не готовим — у неё уже всё позади. */
    public function test_posted_digest_is_not_prepared(): void
    {
        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }
        $item = $this->digestItem($broadcast, Carbon::now()->addHours(12));
        $item->posted_at = Carbon::now()->subHour();
        $item->save();

        $this->artisan('broadcast:prepare-digests')->assertSuccessful();

        $this->assertSame(0, DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)->count());
    }

    /* ───────────── шаг 0: ручной текст не исчезает без следа ───────────── */

    public function test_compose_button_keeps_the_manual_text_in_history(): void
    {
        $this->actingAsSuperadmin();

        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }
        $item = $this->digestItem($broadcast, Carbon::now()->addHours(12));
        $item->caption = 'мой текст, писал час';
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_MANUAL;
        $item->save();

        $this->postJson("/api/admin/broadcast/items/{$item->id}/compose")->assertOk();

        $this->assertSame('мой текст, писал час', DB::table('telegram.chat_broadcast_item_revisions')
            ->where('item_id', $item->id)->value('caption'), 'ручной текст ушёл в историю, а не в никуда');
        $this->assertNotSame('мой текст, писал час', $item->fresh()->caption, 'кнопка при этом отработала');
    }

    public function test_describe_button_keeps_the_manual_text_in_history(): void
    {
        $this->actingAsSuperadmin();

        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }
        $item = $this->digestItem($broadcast, Carbon::now()->addHours(12));
        $item->caption = 'мой текст, писал час';
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_MANUAL;
        $item->save();

        $this->postJson("/api/admin/broadcast/items/{$item->id}/describe")->assertOk();

        $this->assertSame('мой текст, писал час', DB::table('telegram.chat_broadcast_item_revisions')
            ->where('item_id', $item->id)->value('caption'));
        $this->assertNull($item->fresh()->caption, 'подпись снята: пустая — сигнал «собрать заново»');
    }

    /** Шаблонную подпись в историю не пишем: она воспроизводится из события. */
    public function test_template_text_does_not_litter_the_history(): void
    {
        $this->actingAsSuperadmin();

        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }
        $item = $this->digestItem($broadcast, Carbon::now()->addHours(12));
        $item->caption = 'собрано машиной';
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_TEMPLATE;
        $item->save();

        $this->postJson("/api/admin/broadcast/items/{$item->id}/compose")->assertOk();

        $this->assertSame(0, DB::table('telegram.chat_broadcast_item_revisions')
            ->where('item_id', $item->id)->count());
    }

    /* ───────────────────────── обстановка ───────────────────────── */

    private function actingAsSuperadmin(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web');
        $user = \App\Models\User::factory()->create();
        $user->assignRole('superadmin');
        \Laravel\Sanctum\Sanctum::actingAs($user);
    }

    private function digestItem(TelegramChatBroadcast $broadcast, Carbon $at): TelegramChatBroadcastItem
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->publish_at = $at;
        $item->save();

        return $item;
    }

    private function themedEvent(string $title, int $n): int
    {
        $community = \App\Models\Community::create([
            'name' => 'Организатор '.uniqid(),
            'city_id' => $this->cityId,
        ]);

        $venueId = DB::table('venues')->insertGetId([
            'city_id' => $this->cityId,
            'name' => 'Площадка '.$n.' '.uniqid(),
            'slug' => 'venue-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = new \App\Models\Event;
        $event->community_id = $community->id;
        $event->title = $title;
        $event->status = 'active';
        $event->city_id = $this->cityId;
        $event->venue_id = $venueId;
        $event->start_time = Carbon::now()->addDays($n)->setTime(19, 0);
        $event->start_date = Carbon::now()->addDays($n)->toDateString();
        $event->end_time = Carbon::now()->addDays($n)->setTime(21, 0);
        $event->description = str_repeat('описание события достаточной длины. ', 4 + $n);
        $event->price_min = 500 * $n;
        $event->save();

        DB::table('event_interest')->insert([
            'event_id' => $event->id,
            'interest_id' => $this->interestId,
            'rank' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $event->id;
    }

    private function makeChannel(): TelegramChatBroadcast
    {
        $owner = TelegramUser::create(['telegram_id' => 8307202077]);
        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999177;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->city_id = $this->cityId;
        $chat->save();

        return TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic'],
        ])->load('chat.city');
    }

    private function city(): int
    {
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh-prepare', now(), now()]
        );

        return (int) DB::table('cities')->where('slug', 'voronezh-prepare')->value('id');
    }
}
