<?php

namespace Tests\Feature\Telegram;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Services\Telegram\PickReason;
use App\Services\Telegram\TelegramChatBroadcastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BroadcastClearFeedTest extends TestCase
{
    use RefreshDatabase;

    private TelegramChatBroadcast $broadcast;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-15 06:00', 'Europe/Moscow')->utc());

        \Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web');
        $user = \App\Models\User::factory()->create();
        $user->assignRole('superadmin');
        \Laravel\Sanctum\Sanctum::actingAs($user);

        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh', now(), now()]
        );
        $city = City::query()->where('slug', 'voronezh')->firstOrFail();

        $owner = TelegramUser::create(['telegram_id' => 9100400]);
        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009100400;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->city_id = $city->id;
        $chat->save();

        $this->broadcast = TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic', 'slots' => [10, 19], 'horizon_days' => 3],
        ]);

        for ($i = 0; $i < 10; $i++) {
            $community = Community::create(['name' => 'Организатор '.$i, 'city_id' => $city->id]);
            $event = new Event;
            $event->community_id = $community->id;
            $event->title = 'Событие '.$i;
            $event->status = 'active';
            $event->city_id = $city->id;
            $event->start_time = now()->addDays(3 + $i)->setTime(19, 0);
            $event->start_date = $event->start_time->toDateString();
            $event->save();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_paused_channel_stays_empty_until_switched_on(): void
    {
        $this->postJson("/api/admin/broadcast/channels/{$this->broadcast->id}/autofill", ['paused' => true])->assertOk();

        $this->artisan('broadcast:fill-feed')->assertExitCode(0);
        $this->assertSame(0, $this->openPosts()->count(), 'на паузе фоновое задание слоты не трогает');

        $filled = $this->postJson("/api/admin/broadcast/channels/{$this->broadcast->id}/autofill", ['paused' => false])
            ->assertOk()
            ->json('data.filled');

        $this->assertGreaterThan(0, $filled);
        $this->assertSame($filled, $this->openPosts()->count());
    }

    /** @return array<string, array{bool, list<string>}> */
    public static function clearScopes(): array
    {
        return [
            'только поставленное автоматом' => [false, ['закреплённый', 'ручной']],
            'всё, кроме вышедшего' => [true, []],
        ];
    }

    /** @param  list<string>  $kept */
    #[DataProvider('clearScopes')]
    public function test_clear_removes_posts_and_pauses_the_automat(bool $all, array $kept): void
    {
        app(TelegramChatBroadcastService::class)->fillFeedDays($this->broadcast->fresh('chat'), now());
        $auto = $this->openPosts()->orderBy('publish_at')->get();
        $this->assertGreaterThanOrEqual(3, $auto->count());

        $pinned = $auto[0];
        $pinned->is_pinned = true;
        $pinned->save();
        $hand = $auto[1];
        $hand->pick_meta = PickReason::meta(PickReason::BY_HAND, 'enqueue');
        $hand->save();
        $labels = [$pinned->id => 'закреплённый', $hand->id => 'ручной'];

        $this->postJson("/api/admin/broadcast/channels/{$this->broadcast->id}/clear", ['all' => $all])->assertOk();

        $left = $this->openPosts()->pluck('id')->map(fn ($id) => $labels[$id] ?? 'автоматический')->sort()->values()->all();
        $this->assertSame($kept, $left);
        $this->assertTrue($this->broadcast->fresh()->autofill_paused);

        $this->artisan('broadcast:fill-feed')->assertExitCode(0);
        $this->assertCount(count($kept), $this->openPosts()->get(), 'очищенное автомат обратно не заполнил');
    }

    private function openPosts(): \Illuminate\Database\Eloquent\Builder
    {
        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $this->broadcast->id)
            ->where('kind', TelegramChatBroadcastItem::KIND_EVENT)
            ->whereIn('status', [TelegramChatBroadcastItem::STATUS_PENDING, TelegramChatBroadcastItem::STATUS_PLANNED])
            ->whereNotNull('publish_at');
    }
}
