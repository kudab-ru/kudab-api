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
 * Запись НЕсобытийного типа не должна считаться событием.
 *
 * До 2026-09-15 «событийность» была задана отрицанием — `kind IS NULL OR kind
 * <> 'venue'` — в пяти местах. Такое условие молча зачисляет в события любую
 * новую рубрику: подборка недели съела бы ячейку feed_limit, попала под
 * «Разбавить» и была бы снесена кнопкой «Пересобрать неделю». Причём ничего не
 * падает — просто считается неверно.
 *
 * Тест сторожит именно это: берём запись с типом, которого код ещё не знает, и
 * проверяем, что событийные механизмы её не трогают.
 */
class AdminBroadcastKindTest extends TestCase
{
    use RefreshDatabase;

    /** Тип будущей рубрики. Нарочно не константа: её в коде ещё нет. */
    private const FUTURE_KIND = 'digest';

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');
        Sanctum::actingAs($user);
    }

    public function test_unknown_kind_is_not_counted_as_an_event_in_the_feed(): void
    {
        $broadcast = $this->makeChannel();
        $this->makeItem($broadcast->id, self::FUTURE_KIND);

        $res = $this->getJson("/api/admin/broadcast/channels/{$broadcast->id}/feed");

        $res->assertOk();
        $this->assertSame(0, $res->json('data.channel.in_feed'), 'рубрика не занимает место события');
    }

    public function test_rebuild_does_not_sweep_an_unknown_kind(): void
    {
        $broadcast = $this->makeChannel();
        $rubric = $this->makeItem($broadcast->id, self::FUTURE_KIND);
        $event = $this->makeItem($broadcast->id, TelegramChatBroadcastItem::KIND_EVENT);
        $event->event_id = $this->freeEvent()->id;
        $event->save();
        // Пересборке нужно, чем заполнять: иначе она откатывается целиком и
        // тест проходит, даже когда рубрику сносит.
        foreach (range(1, 3) as $n) {
            $this->freeEvent();
        }

        $res = $this->postJson("/api/admin/broadcast/channels/{$broadcast->id}/rebuild");

        $res->assertOk();
        // Пересборка должна была реально снять событийную запись — иначе тест
        // проверяет пустоту. Саму запись потом оживляет заполнение: событие
        // снова подходит каналу, и enqueue возвращает ту же строку.
        $this->assertSame(1, $res->json('data.dropped'), 'снята ровно одна запись — событийная');
        $this->assertNotSame(
            TelegramChatBroadcastItem::STATUS_SKIPPED,
            $rubric->fresh()->status,
            'рубрика живёт по своему каденсу, пересборка ленты ей не указ',
        );
    }

    /** Событие, которое ещё не стоит в очереди — корм для пересборки. */
    private function freeEvent(): \App\Models\Event
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
        $event->start_time = now()->addDays(3);
        $event->start_date = now()->addDays(3)->toDateString();
        $event->end_time = now()->addDays(3)->addHours(2);
        $event->description = 'описание события длиннее ста двадцати символов, чтобы отбор считал карточку полной и событие вообще попадало в подбор канала';
        $event->save();

        return $event;
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

    private function makeChannel(): TelegramChatBroadcast
    {
        $owner = TelegramUser::create(['telegram_id' => 8307201799]);

        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009999044;
        $chat->chat_type = 'channel';
        $chat->is_active = true;
        $chat->telegram_user_id = $owner->id;
        // Без города канал ничего не подбирает, и пересборке нечем заполнять:
        // она откатывается целиком и ничего не проверяет.
        $chat->city_id = $this->city()->id;
        $chat->save();

        return TelegramChatBroadcast::create([
            'chat_id' => $chat->id,
            'enabled' => true,
            'settings' => ['period' => 'daily_10', 'template_code' => 'basic'],
        ]);
    }

    private function makeItem(int $broadcastId, string $kind): TelegramChatBroadcastItem
    {
        $id = DB::table('telegram.chat_broadcast_items')->insertGetId([
            'broadcast_id' => $broadcastId,
            'kind' => $kind,
            'status' => TelegramChatBroadcastItem::STATUS_PENDING,
            'caption' => 'текст',
            'publish_at' => now()->addDay()->setTime(10, 0),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return TelegramChatBroadcastItem::query()->findOrFail($id);
    }
}
