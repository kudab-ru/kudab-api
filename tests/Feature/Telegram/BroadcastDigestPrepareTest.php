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

    /* ──────────── шаг 2: чем можно заменить позицию ──────────── */

    public function test_candidates_never_repeat_the_current_roster(): void
    {
        $this->actingAsSuperadmin();

        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }
        $item = $this->digestItem($broadcast, Carbon::now()->addHours(12));
        $this->artisan('broadcast:prepare-digests')->assertSuccessful();

        $out = $this->getJson("/api/admin/broadcast/items/{$item->id}/digest-candidates")
            ->assertOk()->json('data');

        $named = array_column($out['named'], 'id');
        $candidates = array_column($out['candidates'], 'id');

        $this->assertCount(3, $named);
        $this->assertNotEmpty($candidates, 'шесть событий темы минус три названных — есть из чего выбирать');
        $this->assertSame([], array_intersect($named, $candidates), 'названное не предлагаем заменой самому себе');
        $this->assertSame([1, 2, 3], array_column($out['named'], 'line'), 'строки пронумерованы так, как стоят в посте');
    }

    /**
     * Спорный кандидат виден, но помечен и лежит внизу.
     *
     * Правила «одна площадка — одна строка» и «один день — одна строка»
     * действуют только на автосборку; состав, записанный человеком, их не
     * проверяет никто. Прятать кандидата поэтому нельзя — иногда два спектакля
     * в один день лучше, чем один хороший и один никакой, — но и молчать о
     * споре тоже.
     */
    public function test_candidate_sharing_a_venue_is_marked_and_sunk(): void
    {
        $this->actingAsSuperadmin();

        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }
        $item = $this->digestItem($broadcast, Carbon::now()->addHours(12));
        $this->artisan('broadcast:prepare-digests')->assertSuccessful();

        $named = $this->getJson("/api/admin/broadcast/items/{$item->id}/digest-candidates")
            ->json('data.named');
        $busyVenue = (int) DB::table('events')->where('id', $named[0]['id'])->value('venue_id');

        // Тот же зал, но другой день: спор ровно один, и его должно быть видно.
        $twinId = $this->themedEvent('Премьера в том же зале', 7, $busyVenue);

        $rows = $this->getJson("/api/admin/broadcast/items/{$item->id}/digest-candidates")
            ->assertOk()->json('data.candidates');

        $twin = collect($rows)->firstWhere('id', $twinId);
        $this->assertNotNull($twin, 'кандидата со спорной площадкой не прячем');
        $this->assertContains('та же площадка, что в строке 1', $twin['notes']);
        $this->assertTrue($twin['clash']);
        $this->assertNotSame($twinId, (int) $rows[0]['id'], 'спорный лежит ниже бесспорных');
    }

    /** Без состава кандидатов не бывает: не с чем сравнивать и нечего заменять. */
    public function test_candidates_require_a_composed_roster(): void
    {
        $this->actingAsSuperadmin();

        $broadcast = $this->makeChannel();
        $item = $this->digestItem($broadcast, Carbon::now()->addHours(12));

        $this->getJson("/api/admin/broadcast/items/{$item->id}/digest-candidates")
            ->assertStatus(422);
    }

    /* ──────────── шаг 3: замена, обмен, остывание ──────────── */

    public function test_replace_swaps_one_line_and_keeps_the_rest(): void
    {
        [$item, $named, $candidate] = $this->composedDigestWithSpare();

        $out = $named[1]['id'];

        $this->postJson("/api/admin/broadcast/items/{$item->id}/digest-events/replace", [
            'out' => $out,
            'in' => $candidate,
        ])->assertOk();

        $roster = DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)->pluck('event_id')->map(fn ($v) => (int) $v)->all();

        $this->assertNotContains($out, $roster, 'выброшенного в составе нет');
        $this->assertContains($candidate, $roster, 'новое встало на его место');
        $this->assertContains($named[0]['id'], $roster, 'соседние строки не тронуты');
        $this->assertContains($named[2]['id'], $roster);
        $this->assertCount(3, $roster);
    }

    /** Подпись пересобирается в той же транзакции: иначе альбом разойдётся с текстом. */
    public function test_replace_rebuilds_the_caption(): void
    {
        [$item, $named, $candidate] = $this->composedDigestWithSpare();

        $before = $item->fresh()->caption;
        $title = (string) DB::table('events')->where('id', $candidate)->value('title');

        $this->postJson("/api/admin/broadcast/items/{$item->id}/digest-events/replace", [
            'out' => $named[1]['id'],
            'in' => $candidate,
        ])->assertOk();

        $after = $item->fresh()->caption;
        $this->assertNotSame($before, $after);
        $this->assertStringContainsString($title, $after, 'новое событие названо в тексте');
        $this->assertStringNotContainsString($named[1]['title'], $after, 'выброшенного в тексте больше нет');
    }

    /** Флаг cool ставит то же тридцатидневное «не предлагать», что кнопка отказа. */
    public function test_cooled_event_stops_coming_back(): void
    {
        [$item, $named, $candidate] = $this->composedDigestWithSpare();
        $out = $named[1]['id'];

        $this->postJson("/api/admin/broadcast/items/{$item->id}/digest-events/replace", [
            'out' => $out, 'in' => $candidate, 'cool' => true,
        ])->assertOk();

        $this->assertSame(TelegramChatBroadcastItem::STATUS_REJECTED, DB::table('telegram.chat_broadcast_items')
            ->where('broadcast_id', $item->broadcast_id)->where('event_id', $out)->value('status'));

        $rows = $this->getJson("/api/admin/broadcast/items/{$item->id}/digest-candidates")
            ->assertOk()->json('data.candidates');

        $this->assertNotContains($out, array_column($rows, 'id'),
            'остывшее событие рубрика больше не предлагает');
    }

    /** Без cool событие возвращается в пул — это осознанный выбор, а не забывчивость. */
    public function test_without_cooling_the_event_returns_to_the_pool(): void
    {
        [$item, $named, $candidate] = $this->composedDigestWithSpare();
        $out = $named[1]['id'];

        $this->postJson("/api/admin/broadcast/items/{$item->id}/digest-events/replace", [
            'out' => $out, 'in' => $candidate,
        ])->assertOk();

        $rows = $this->getJson("/api/admin/broadcast/items/{$item->id}/digest-candidates")
            ->assertOk()->json('data.candidates');

        $this->assertContains($out, array_column($rows, 'id'));
    }

    /**
     * Обмен с лентой — только с прямого согласия.
     *
     * Событие, стоящее своим постом, в обычные кандидаты не попадает вовсе: это
     * был бы дубль. Но обмен осмыслен, и тогда пост обязан сняться.
     */
    public function test_taking_an_event_from_the_feed_requires_swap_and_drops_the_post(): void
    {
        [$item, $named] = $this->composedDigestWithSpare();

        // Свободного кандидата ставим в ленту отдельным постом.
        $spare = $this->getJson("/api/admin/broadcast/items/{$item->id}/digest-candidates")
            ->json('data.candidates.0.id');

        $post = new TelegramChatBroadcastItem;
        $post->broadcast_id = $item->broadcast_id;
        $post->kind = TelegramChatBroadcastItem::KIND_EVENT;
        $post->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $post->event_id = $spare;
        $post->publish_at = Carbon::now()->addDays(2)->setTime(10, 0);
        $post->save();

        $body = ['out' => $named[1]['id'], 'in' => $spare];

        $this->postJson("/api/admin/broadcast/items/{$item->id}/digest-events/replace", $body)
            ->assertStatus(409)
            ->assertJsonPath('data.needs_swap', true);

        $this->postJson("/api/admin/broadcast/items/{$item->id}/digest-events/replace", $body + ['swap' => true])
            ->assertOk();

        $this->assertSame(TelegramChatBroadcastItem::STATUS_SKIPPED, $post->fresh()->status,
            'отдельный пост снят — иначе канал показал бы событие дважды');
        $this->assertNull($post->fresh()->publish_at, 'и день освободился');

        $inFeed = $this->getJson("/api/admin/broadcast/items/{$item->id}/digest-candidates")
            ->json('data.in_feed');
        $this->assertIsArray($inFeed, 'список «уже в ленте» отдаётся отдельно от обычных кандидатов');
    }

    /** Ручной текст пересобрать нельзя, не потеряв его, — отказываем. */
    public function test_manual_caption_blocks_the_replacement(): void
    {
        [$item, $named, $candidate] = $this->composedDigestWithSpare();
        $item->caption_source = TelegramChatBroadcastItem::CAPTION_MANUAL;
        $item->save();

        $this->postJson("/api/admin/broadcast/items/{$item->id}/digest-events/replace", [
            'out' => $named[1]['id'], 'in' => $candidate,
        ])->assertStatus(409);
    }

    /** Заявка на текст в полёте: парсер затрёт мету по своему составу. */
    public function test_pending_text_request_blocks_the_replacement(): void
    {
        [$item, $named, $candidate] = $this->composedDigestWithSpare();
        $item->text_requested_at = Carbon::now();
        $item->save();

        $this->postJson("/api/admin/broadcast/items/{$item->id}/digest-events/replace", [
            'out' => $named[1]['id'], 'in' => $candidate,
        ])->assertStatus(409);
    }

    /** Заклеймленную не трогаем: подпись уже уехала боту задачей. */
    public function test_claimed_item_blocks_the_replacement(): void
    {
        [$item, $named, $candidate] = $this->composedDigestWithSpare();
        $item->claimed_at = Carbon::now();
        $item->claim_token = 'x';
        $item->save();

        $this->postJson("/api/admin/broadcast/items/{$item->id}/digest-events/replace", [
            'out' => $named[1]['id'], 'in' => $candidate,
        ])->assertStatus(409);
    }

    /**
     * Провал пересборки откатывает ВСЁ.
     *
     * Событие из состава снимают с публикации, пока панель открыта. Состав
     * после этого не собирается, и замена обязана не оставить следов: раньше
     * `return null` из замыкания транзакции Laravel считал нормальным выходом
     * и коммитил подменённую связь под ответом «подборка осталась как была».
     */
    public function test_failed_recompose_rolls_everything_back(): void
    {
        [$item, $named, $candidate] = $this->composedDigestWithSpare();

        DB::table('events')->where('id', $named[0]['id'])->update(['deleted_at' => now()]);

        $before = DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)->orderBy('event_id')->pluck('event_id')->all();

        $this->postJson("/api/admin/broadcast/items/{$item->id}/digest-events/replace", [
            'out' => $named[1]['id'], 'in' => $candidate,
        ])->assertStatus(409);

        $this->assertSame($before, DB::table('telegram.chat_broadcast_item_events')
            ->where('item_id', $item->id)->orderBy('event_id')->pluck('event_id')->all(),
            'состав остался как был — иначе ответ врёт');
    }

    /** Закреплённый пост одной кнопкой не снимаем: закрепление — решение человека. */
    public function test_swap_refuses_to_drop_a_pinned_post(): void
    {
        [$item, $named] = $this->composedDigestWithSpare();

        $spare = $this->getJson("/api/admin/broadcast/items/{$item->id}/digest-candidates")
            ->json('data.candidates.0.id');

        $post = new TelegramChatBroadcastItem;
        $post->broadcast_id = $item->broadcast_id;
        $post->kind = TelegramChatBroadcastItem::KIND_EVENT;
        $post->status = TelegramChatBroadcastItem::STATUS_PENDING;
        $post->event_id = $spare;
        $post->publish_at = Carbon::now()->addDays(2)->setTime(10, 0);
        $post->is_pinned = true;
        $post->save();

        $this->postJson("/api/admin/broadcast/items/{$item->id}/digest-events/replace", [
            'out' => $named[1]['id'], 'in' => $spare, 'swap' => true,
        ])->assertStatus(409);

        $this->assertSame(TelegramChatBroadcastItem::STATUS_PENDING, $post->fresh()->status);
    }

    /** Отказ от ПОДБОРКИ остужает все три названных события, а не ноль. */
    public function test_rejected_digest_cools_down_the_events_it_named(): void
    {
        [$item] = $this->composedDigestWithSpare();

        $rows = $this->getJson("/api/admin/broadcast/items/{$item->id}/digest-candidates")
            ->json('data.candidates');
        $victim = (int) $rows[0]['id'];

        // Другая подборка того же канала, названная этим событием, отклонена.
        $other = new TelegramChatBroadcastItem;
        $other->broadcast_id = $item->broadcast_id;
        $other->kind = TelegramChatBroadcastItem::KIND_DIGEST;
        $other->status = TelegramChatBroadcastItem::STATUS_REJECTED;
        $other->save();
        DB::table('telegram.chat_broadcast_item_events')->insert([
            'item_id' => $other->id, 'event_id' => $victim, 'position' => 1, 'created_at' => now(),
        ]);

        $after = $this->getJson("/api/admin/broadcast/items/{$item->id}/digest-candidates")
            ->json('data.candidates');

        $this->assertNotContains($victim, array_column($after, 'id'),
            'у подборки event_id пуст — остывание обязано читаться связью');
    }

    /** @return array{0: TelegramChatBroadcastItem, 1: array<int, array<string, mixed>>, 2: int} */
    private function composedDigestWithSpare(): array
    {
        $this->actingAsSuperadmin();

        $broadcast = $this->makeChannel();
        foreach (range(1, 6) as $n) {
            $this->themedEvent("Спектакль {$n}", $n);
        }
        $item = $this->digestItem($broadcast, Carbon::now()->addHours(12));
        $this->artisan('broadcast:prepare-digests')->assertSuccessful();

        // Сборка заодно заказывает текст, и на время заявки состав заперт —
        // парсер перезаписывает digest_meta по своему снимку. В жизни заявка
        // закрывается за полминуты (TgDescribeDueCommand обнуляет
        // text_requested_at), здесь парсера нет, поэтому закрываем руками.
        $item->text_requested_at = null;
        $item->planned_at = null;
        $item->save();

        $data = $this->getJson("/api/admin/broadcast/items/{$item->id}/digest-candidates")
            ->assertOk()->json('data');

        return [$item, $data['named'], (int) $data['candidates'][0]['id']];
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

    private function themedEvent(string $title, int $n, ?int $venueId = null): int
    {
        $community = \App\Models\Community::create([
            'name' => 'Организатор '.uniqid(),
            'city_id' => $this->cityId,
        ]);

        $venueId ??= DB::table('venues')->insertGetId([
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
