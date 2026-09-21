<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Горизонт пула предложений отсчитывается от момента публикации.
 *
 * Регресс прода 21.09.2026: владелец открыл дальний слот и увидел «Что можно
 * поставить» из двух карточек. Горизонт был прибит к «сейчас»
 * (start_time <= now()+14d), а нижняя граница ехала вместе со слотом, поэтому
 * окно [слот … сегодня+14] закрывалось само собой. Замер по живой базе:
 * слот сегодня — 177 кандидатов, через неделю — 28, через тринадцать дней — 5,
 * через четырнадцать — РОВНО НОЛЬ.
 *
 * Лента при этом наполняется на две недели вперёд, то есть пул умирал ровно
 * там, где он и нужен.
 */
class AdminBroadcastSuggestionsHorizonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-15 06:00:00', 'UTC'));

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

    /** Слот на тринадцатый день видит событие, которое идёт через двадцать. */
    public function test_far_slot_sees_events_beyond_two_weeks_from_today(): void
    {
        $broadcast = $this->makeChannel();
        $this->event('Событие через 20 дней', 20);

        $slot = Carbon::now('Europe/Moscow')->addDays(13)->format('Y-m-d');
        $titles = $this->suggest($broadcast->id, $slot);

        $this->assertContains('Событие через 20 дней', $titles);
    }

    /** Ближний слот видит ближние события — прежнее поведение не сломано. */
    public function test_near_slot_still_sees_near_events(): void
    {
        $broadcast = $this->makeChannel();
        $this->event('Событие через 2 дня', 2);

        $slot = Carbon::now('Europe/Moscow')->addDay()->format('Y-m-d');

        $this->assertContains('Событие через 2 дня', $this->suggest($broadcast->id, $slot));
    }

    /** Горизонт не бесконечный: дальше двух недель ОТ СЛОТА по-прежнему не зовём. */
    public function test_horizon_still_bounded_relative_to_the_slot(): void
    {
        $broadcast = $this->makeChannel();
        $this->event('Событие через 40 дней', 40);

        $slot = Carbon::now('Europe/Moscow')->addDays(13)->format('Y-m-d');

        $this->assertNotContains('Событие через 40 дней', $this->suggest($broadcast->id, $slot));
    }

    /** Без даты слота горизонт считается от «сейчас» — как и раньше. */
    public function test_without_date_horizon_is_counted_from_now(): void
    {
        $broadcast = $this->makeChannel();
        $this->event('Событие через 5 дней', 5);
        $this->event('Событие через 30 дней', 30);

        $titles = $this->suggest($broadcast->id, null);

        $this->assertContains('Событие через 5 дней', $titles);
        $this->assertNotContains('Событие через 30 дней', $titles);
    }

    /** @return list<string> */
    private function suggest(int $broadcastId, ?string $date): array
    {
        $url = "/api/admin/broadcast/channels/{$broadcastId}/suggestions";
        if ($date !== null) {
            $url .= '?date='.$date;
        }

        return collect($this->getJson($url)->assertOk()->json('data'))
            ->pluck('title')->all();
    }

    private function city(): City
    {
        $city = City::query()->where('slug', 'voronezh-horizon')->first();
        if ($city) {
            return $city;
        }

        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.6, 'active', 'voronezh-horizon', now(), now()]
        );

        return City::query()->where('slug', 'voronezh-horizon')->firstOrFail();
    }

    private function makeChannel(): TelegramChatBroadcast
    {
        $owner = TelegramUser::create(['telegram_id' => 8307201889]);

        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999056;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        $chat->city_id = $this->city()->id;
        $chat->save();

        return TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
        ]);
    }

    private function event(string $title, int $inDays): Event
    {
        $city = $this->city();
        $community = Community::create(['name' => $title.' орг', 'city_id' => $city->id]);

        $event = new Event;
        $event->community_id = $community->id;
        $event->title = $title;
        $event->status = 'active';
        $event->city_id = $city->id;
        $event->start_time = Carbon::now()->addDays($inDays)->setTime(16, 0);
        $event->start_date = $event->start_time->toDateString();
        $event->save();

        return $event;
    }
}
