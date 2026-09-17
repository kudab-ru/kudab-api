<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\User;
use App\Services\Telegram\TelegramChatBroadcastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Пост удалили в канале руками — слот обязан освободиться.
 *
 * Телеграм об удалении сообщения не сообщает никак, поэтому запись
 * продолжала числиться вышедшей и занимала слот сразу тремя способами:
 * держала зазор до следующего поста, закрывала свой день в ленте и держала
 * своё событие в показанных. Освободить это можно было только правкой базы.
 */
class AdminBroadcastWithdrawTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-15 16:00:00', 'UTC'));

        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        Sanctum::actingAs($user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_withdraw_frees_the_gap_to_the_next_post(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makePosted($broadcast, Carbon::now()->subMinutes(5));

        $service = app(TelegramChatBroadcastService::class);
        $this->assertNotNull(
            $service->nextPostAllowedAt($broadcast->id, Carbon::now()),
            'пока пост числится вышедшим, зазор держит канал',
        );

        $this->withdraw($item)->assertOk();

        $this->assertNull(
            $service->nextPostAllowedAt($broadcast->id, Carbon::now()),
            'снятый пост зазор больше не держит',
        );
    }

    /**
     * Событие возвращается в пул: оно так и не вышло к людям.
     *
     * Это главная потеря от удалённого поста — анонс не увидел никто, а
     * событие навсегда записалось в показанные и второго шанса не получало.
     */
    public function test_withdraw_returns_the_event_to_the_pool(): void
    {
        $broadcast = $this->makeChannel();
        $event = $this->futureEvent();
        $item = $this->makePosted($broadcast, Carbon::now()->subDay(), $event->id);

        $this->assertSame(
            1,
            DB::table('telegram.chat_broadcast_item_events')->where('event_id', $event->id)->count(),
            'связь поста с событием на месте — по ней и считаются показанные',
        );

        $this->withdraw($item)->assertOk();

        $fresh = $item->fresh();
        $this->assertNull($fresh->posted_at, 'вышедшим пост больше не считается');
        $this->assertSame(TelegramChatBroadcastItem::STATUS_WITHDRAWN, $fresh->status);

        // Строку связи намеренно оставляем: это история. Отсечка показанных
        // смотрит на posted_at, и пустой posted_at выводит событие из-под неё.
        $this->assertSame(
            1,
            DB::table('telegram.chat_broadcast_item_events')->where('event_id', $event->id)->count(),
            'история связи не стирается',
        );
    }

    /** Снятый пост не уходит в канал заново: он не в очереди, а в истории. */
    public function test_withdrawn_item_is_not_sent_again(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makePosted($broadcast, Carbon::now()->subMinutes(5), $this->futureEvent()->id);

        $this->withdraw($item)->assertOk();

        $tasks = app(TelegramChatBroadcastService::class)->collectDueSingleRuns(Carbon::now());
        $itemIds = array_map(static fn ($t) => $t['item_id'] ?? null, $tasks);

        $this->assertNotContains($item->id, $itemIds, 'снятая запись в отправку не возвращается');
    }

    /** Ссылку на сообщение гасим: в канале его нет, а по ней ходят клики и закреп. */
    public function test_withdraw_forgets_the_message_id(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makePosted($broadcast, Carbon::now()->subHour());
        $item->message_id = 4242;
        $item->save();

        $this->withdraw($item)->assertOk();

        $this->assertNull($item->fresh()->message_id);
    }

    /** След остаётся видимым: пост исчез из канала, но не из ленты админки. */
    public function test_withdrawn_item_stays_visible_in_the_feed(): void
    {
        $broadcast = $this->makeChannel();
        $item = $this->makePosted($broadcast, Carbon::now()->subHour(), $this->futureEvent()->id);

        $this->withdraw($item)->assertOk();

        $res = $this->getJson("/api/admin/broadcast/channels/{$broadcast->id}/feed")->assertOk();
        $row = collect($res->json('data.items'))->firstWhere('id', $item->id);

        $this->assertNotNull($row, 'снятый пост обязан оставить след');
        $this->assertSame('withdrawn', $row['status']);
        $this->assertSame('withdrawn', $row['skip_reason']);
    }

    /** Невышедший пост снимать нечем — для него есть «убрать из ленты». */
    public function test_item_that_never_posted_is_refused(): void
    {
        $broadcast = $this->makeChannel();
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->save();

        $this->withdraw($item)->assertStatus(409);
        $this->assertSame(TelegramChatBroadcastItem::STATUS_PENDING, $item->fresh()->status);
    }

    private function withdraw(TelegramChatBroadcastItem $item): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/admin/broadcast/items/{$item->id}/withdraw");
    }

    private function makePosted(
        TelegramChatBroadcast $broadcast,
        Carbon $postedAt,
        ?int $eventId = null,
    ): TelegramChatBroadcastItem {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcast->id;
        $item->event_id = $eventId;
        $item->status = TelegramChatBroadcastItem::STATUS_POSTED;
        $item->posted_at = $postedAt;
        $item->publish_at = $postedAt;
        $item->save();

        return $item;
    }

    private function makeChannel(): TelegramChatBroadcast
    {
        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1005550001;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->city_id = $this->city()->id;
        $chat->save();

        return TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic'],
        ]);
    }

    private function futureEvent(): Event
    {
        $city = $this->city();
        $community = Community::firstOrCreate(['name' => 'Организатор', 'city_id' => $city->id]);

        $event = new Event;
        $event->community_id = $community->id;
        $event->title = 'Событие '.uniqid();
        $event->status = 'active';
        $event->city_id = $city->id;
        $event->start_time = Carbon::now()->addDays(5);
        $event->start_date = Carbon::now()->addDays(5)->toDateString();
        $event->save();

        return $event;
    }

    private function city(): City
    {
        $existing = City::query()->where('slug', 'voronezh')->first();
        if ($existing) {
            return $existing;
        }

        $now = now();
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2003, 51.6608, 'active', 'voronezh', $now, $now]
        );

        return City::query()->where('slug', 'voronezh')->firstOrFail();
    }
}
