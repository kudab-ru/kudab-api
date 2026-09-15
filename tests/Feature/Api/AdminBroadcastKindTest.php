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

    /**
     * Событие, названное постом-подборкой, нельзя поставить вторым постом.
     *
     * Ради этого и заводилась связь «пост → события». Своей строки очереди у
     * такого события нет, поэтому ни UNIQUE, ни прежние проверки по колонке
     * записи его не видят: до связи оно осталось бы кандидатом ленты и вышло
     * бы в канал дважды.
     */
    public function test_event_named_by_a_digest_cannot_be_queued_again(): void
    {
        $broadcast = $this->makeChannel();
        $event = $this->freeEvent();

        // Так его положит композитор подборки: запись без ведущего события,
        // состав — строками связи.
        $digest = $this->makeItem($broadcast->id, self::FUTURE_KIND);
        $digest->publish_at = now()->addDays(2)->setTime(19, 0);
        $digest->save();
        DB::table('telegram.chat_broadcast_item_events')->insert([
            'item_id' => $digest->id,
            'event_id' => $event->id,
            'position' => 1,
            'created_at' => now(),
        ]);

        $res = $this->postJson("/api/admin/broadcast/channels/{$broadcast->id}/enqueue", [
            'event_id' => $event->id,
        ]);

        $res->assertStatus(409);
        $this->assertStringContainsString(
            'подборка',
            (string) $res->json('error'),
            'отказ обязан называть, что именно заняло событие',
        );
    }

    /** И в предложениях его тоже нет: пул спрашивает ту же связь. */
    public function test_event_named_by_a_digest_is_not_suggested(): void
    {
        $broadcast = $this->makeChannel();
        $event = $this->freeEvent();
        $digest = $this->makeItem($broadcast->id, self::FUTURE_KIND);
        DB::table('telegram.chat_broadcast_item_events')->insert([
            'item_id' => $digest->id,
            'event_id' => $event->id,
            'position' => 1,
            'created_at' => now(),
        ]);

        $res = $this->getJson("/api/admin/broadcast/channels/{$broadcast->id}/suggestions");

        $res->assertOk();
        $this->assertNotContains(
            $event->id,
            collect($res->json('data'))->pluck('event_id')->all(),
            'событие уже в подборке — предлагать его снова незачем',
        );
    }

    /**
     * Снятая подборка остаётся видимой в ленте.
     *
     * Своего события у неё нет, и прежний отсев выбрасывал из ленты всё, у
     * чего событие не найдено: рубрика исчезала из недельной сетки без причины
     * и без следа — при том что причина у неё говорящая.
     */
    public function test_skipped_digest_stays_visible_in_the_feed(): void
    {
        $broadcast = $this->makeChannel();
        $digest = $this->makeItem($broadcast->id, self::FUTURE_KIND);
        $digest->status = TelegramChatBroadcastItem::STATUS_SKIPPED;
        $digest->error_message = 'подборка: на этой неделе не набралось темы';
        $digest->save();

        $res = $this->getJson("/api/admin/broadcast/channels/{$broadcast->id}/feed");

        $res->assertOk();
        $row = collect($res->json('data.items'))->firstWhere('id', $digest->id);
        $this->assertNotNull($row, 'снятая рубрика обязана оставить след');
        $this->assertStringContainsString('не набралось темы', (string) $row['error_message']);
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

    /**
     * Через модель, а не через DB::table: на сохранении записи висит обсервер,
     * который держит связь «пост → события». Вставка мимо модели обсервер не
     * зовёт, и фикстура молча осталась бы без строки связи — а этот файл
     * копируют как образец.
     */
    private function makeItem(int $broadcastId, string $kind): TelegramChatBroadcastItem
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcastId;
        $item->kind = $kind;
        $item->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $item->caption = 'текст';
        $item->publish_at = now()->addDay()->setTime(10, 0);
        $item->save();

        return $item;
    }
}
