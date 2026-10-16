<?php

namespace Tests\Feature\Telegram;

use App\Models\Community;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BroadcastEventPickerTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    private TelegramChatBroadcast $broadcast;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 09:00', 'Europe/Moscow'));

        \Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web');
        $user = \App\Models\User::factory()->create();
        $user->assignRole('superadmin');
        \Laravel\Sanctum\Sanctum::actingAs($user);

        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh', now(), now()]
        );
        $this->cityId = (int) DB::table('cities')->where('slug', 'voronezh')->value('id');

        $owner = TelegramUser::create(['telegram_id' => 9100300]);
        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009100300;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->city_id = $this->cityId;
        $chat->save();

        $this->broadcast = TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic', 'slots' => [15, 19]],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('queries')]
    public function test_search_finds_what_the_automat_skips_and_says_why(string $query): void
    {
        $slot = Carbon::parse('2026-10-16 15:00', 'Europe/Moscow');
        $concert = $this->event('Ундервуд', $slot->copy()->setTime(20, 0));

        $row = collect($this->slotPicker($slot, str_replace('{id}', (string) $concert->id, $query)))
            ->firstWhere('id', $concert->id);

        $this->assertNotNull($row, 'до начала пять часов: в варианты автомата такое не попадает, а поиск находит');
        $this->assertSame('place', $row['action']);
        $this->assertStringContainsString('автомат такое не берёт', (string) $row['note']);
    }

    public static function queries(): array
    {
        return [
            'слово' => ['ундер'],
            'ссылка с сайта' => ['https://kudab.ru/events/{id}'],
        ];
    }

    public function test_events_already_in_the_channel_are_listed_without_a_button(): void
    {
        $slot = Carbon::parse('2026-10-16 15:00', 'Europe/Moscow');
        $posted = $this->event('Вышедший', $slot->copy()->addDay()->setTime(19, 0));
        $queued = $this->event('В ленте', $slot->copy()->addDay()->setTime(20, 0));
        $free = $this->event('Свободный', $slot->copy()->addDay()->setTime(21, 0));
        $this->channelPost($posted, Carbon::now()->subDay(), posted: true);
        $this->channelPost($queued, $slot->copy()->addDay()->setTime(10, 0));

        $rows = collect($this->slotPicker($slot))->keyBy('id');

        $this->assertNull($rows[$posted->id]['action']);
        $this->assertNull($rows[$queued->id]['action']);
        $this->assertSame('place', $rows[$free->id]['action']);
        $this->postJson("/api/admin/broadcast/channels/{$this->broadcast->id}/enqueue", [
            'event_id' => $queued->id,
            'publish_at' => $slot->format('Y-m-d\TH:i:sP'),
        ])->assertStatus(409);
    }

    public function test_digest_offers_swap_for_an_event_with_its_own_post(): void
    {
        $theatre = (int) DB::table('interests')->insertGetId([
            'name' => 'Театр', 'slug' => 'theatre', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $at = Carbon::parse('2026-10-17 12:00', 'Europe/Moscow');
        $first = $this->event('Спектакль первый', $at->copy()->setTime(19, 0), $theatre);
        $second = $this->event('Спектакль второй', $at->copy()->addDay()->setTime(19, 0), $theatre);
        $own = $this->event('Спектакль со своим постом', $at->copy()->addDay()->setTime(20, 0), $theatre);
        $this->channelPost($own, $at->copy()->addDay()->setTime(10, 0));

        $digest = new TelegramChatBroadcastItem;
        $digest->broadcast_id = $this->broadcast->id;
        $digest->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $digest->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $digest->publish_at = $at->copy()->utc();
        $digest->digest_meta = ['theme' => 'spektakli'];
        $digest->save();
        foreach ([$first, $second] as $position => $event) {
            DB::table('telegram.chat_broadcast_item_events')->insert([
                'item_id' => $digest->id, 'event_id' => $event->id, 'position' => $position, 'created_at' => now(),
            ]);
        }

        $rows = collect($this->getJson("/api/admin/broadcast/items/{$digest->id}/picker?"
            .http_build_query(['q' => 'спектакль', 'out' => $first->id]))->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('swap', $rows[$own->id]['action']);
        $this->assertNull($rows[$second->id]['action'], 'уже названное в подборке второй раз не ставится');
    }

    /** @return list<array<string, mixed>> */
    private function slotPicker(Carbon $slot, string $query = ''): array
    {
        return $this->getJson("/api/admin/broadcast/channels/{$this->broadcast->id}/picker?"
            .http_build_query(['at' => $slot->format('Y-m-d\TH:i:sP'), 'q' => $query]))
            ->assertOk()
            ->json('data');
    }

    private function event(string $title, Carbon $start, ?int $interestId = null): Event
    {
        $community = Community::create(['name' => $title.' орг', 'city_id' => $this->cityId]);

        $event = new Event;
        $event->community_id = $community->id;
        $event->title = $title;
        $event->status = 'active';
        $event->city_id = $this->cityId;
        $event->start_time = $start->copy()->utc();
        $event->start_date = $start->toDateString();
        $event->save();

        if ($interestId !== null) {
            DB::table('event_interest')->insert([
                'event_id' => $event->id, 'interest_id' => $interestId, 'rank' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $event;
    }

    private function channelPost(Event $event, Carbon $at, bool $posted = false): void
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $this->broadcast->id;
        $item->event_id = $event->id;
        $item->status = $posted ? TelegramChatBroadcastItem::STATUS_POSTED : TelegramChatBroadcastItem::STATUS_PENDING;
        $item->publish_at = $at->copy()->utc();
        $item->posted_at = $posted ? $at->copy()->utc() : null;
        $item->save();
    }
}
