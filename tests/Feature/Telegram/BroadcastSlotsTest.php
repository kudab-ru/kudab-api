<?php

namespace Tests\Feature\Telegram;

use App\Models\City;
use App\Models\Community;
use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\TelegramChatBroadcastItem;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramChatBroadcastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Несколько постов в один день: слоты.
 *
 * Слоты — это список часов в настройках канала. Пустой список означает
 * прежнее поведение: один пост в день, час из расписания. Поэтому все тесты
 * здесь идут парами — «без слотов как раньше» и «со слотами по-новому».
 */
class BroadcastSlotsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TelegramChatBroadcastService
    {
        return app(TelegramChatBroadcastService::class);
    }

    public function test_filler_puts_one_post_per_day_without_slots(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC')); // 06:00 МСК

        [$broadcast] = $this->channelWithEvents(6);

        $this->service()->fillFeedDays($broadcast, now());

        $hours = $this->publishHours($broadcast->id);
        $this->assertSame([10], array_values(array_unique($hours)), 'час один — из расписания');
        $this->assertSame(count($hours), count(array_unique($this->publishDays($broadcast->id))), 'по одному посту на день');

        Carbon::setTestNow();
    }

    public function test_filler_fills_both_slots_of_a_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC')); // 06:00 МСК

        [$broadcast] = $this->channelWithEvents(6);
        $broadcast->slots = [10, 19];
        $broadcast->horizon_days = 2;
        $broadcast->save();

        $this->service()->fillFeedDays($broadcast->fresh(), now());

        $hours = $this->publishHours($broadcast->id);
        sort($hours);

        $this->assertSame([10, 10, 19, 19], $hours, 'два дня по два слота');

        Carbon::setTestNow();
    }

    /**
     * Второй слот дня уходит, хотя суточное окно закрыто первым постом.
     *
     * Это и есть смысл правки гейта: запись с назначенным моментом идёт по
     * нему, а не по окну расписания, иначе второй слот не открылся бы никогда.
     */
    public function test_second_slot_of_the_day_is_delivered(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 16:30:00', 'UTC')); // 19:30 МСК

        [$broadcast, $events] = $this->channelWithEvents(2);
        $broadcast->slots = [10, 19];
        $broadcast->save();

        // Утренний пост уже ушёл: окно расписания на сегодня закрыто.
        $morning = $this->makeItem($broadcast->id, $events[0]->id, TelegramChatBroadcastItem::STATUS_POSTED);
        $morning->publish_at = Carbon::parse('2026-09-15 07:00:00', 'UTC');
        $morning->posted_at = Carbon::parse('2026-09-15 07:00:30', 'UTC');
        $morning->save();
        $broadcast->last_run_at = Carbon::parse('2026-09-15 07:00:30', 'UTC');
        $broadcast->save();

        $evening = $this->makeItem($broadcast->id, $events[1]->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $evening->publish_at = Carbon::parse('2026-09-15 16:00:00', 'UTC'); // 19:00 МСК
        $evening->save();

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks, 'вечерний слот открылся');
        $this->assertSame($evening->id, $tasks[0]['item_id']);

        Carbon::setTestNow();
    }

    /** Без слотов запись с днём по-прежнему ждёт своего окна. */
    public function test_without_slots_second_post_of_the_day_waits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 16:30:00', 'UTC')); // 19:30 МСК

        [$broadcast, $events] = $this->channelWithEvents(2);

        $morning = $this->makeItem($broadcast->id, $events[0]->id, TelegramChatBroadcastItem::STATUS_POSTED);
        $morning->publish_at = Carbon::parse('2026-09-15 07:00:00', 'UTC');
        $morning->posted_at = Carbon::parse('2026-09-15 07:00:30', 'UTC');
        $morning->save();
        $broadcast->last_run_at = Carbon::parse('2026-09-15 07:00:30', 'UTC');
        $broadcast->save();

        // Второй пост дня без слотов взяться неоткуда, но если он есть —
        // назначенный момент его всё равно выпускает: это осознанно, запись с
        // днём идёт по своему дню. Проверяем обратное: записи БЕЗ дня ждут окна.
        $waiting = $this->makeItem($broadcast->id, $events[1]->id, TelegramChatBroadcastItem::STATUS_PENDING);

        $this->assertCount(0, $this->service()->collectDueSingleRuns(now()), 'окно на сегодня закрыто');
        $this->assertNull($waiting->fresh()->publish_at);

        Carbon::setTestNow();
    }

    /**
     * Порог повтора площадки растёт вместе с плотностью.
     *
     * Слой был «была/не была»: при одном посте в день доля площадки — одна
     * седьмая, и это верно. При двух слотах постов четырнадцать, и запрет на
     * повтор вырезал бы почти весь пул; слой мягкий и молча взял бы
     * неотфильтрованное — то есть выключился бы сам.
     */
    public function test_venue_repeat_cap_follows_density(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC')); // 06:00 МСК

        [$broadcast, $city] = $this->channelWithVenueEvents();
        $broadcast->slots = [10, 19];
        $broadcast->horizon_days = 7;
        $broadcast->save();

        $this->service()->fillFeedDays($broadcast->fresh(), now());

        $this->assertSame(2, $this->countFromVenue($broadcast->id, $city['crowded']),
            'при двух слотах площадка может выйти дважды за неделю, но не чаще');

        Carbon::setTestNow();
    }

    /** Без слотов порог прежний: одна площадка — один раз в неделю. */
    public function test_venue_repeat_cap_is_one_without_slots(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC'));

        [$broadcast, $city] = $this->channelWithVenueEvents();

        $this->service()->fillFeedDays($broadcast->fresh(), now());

        $this->assertSame(1, $this->countFromVenue($broadcast->id, $city['crowded']));

        Carbon::setTestNow();
    }

    /** Зазор берётся из настроек канала, а не из константы. */
    public function test_gap_comes_from_channel_settings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 16:30:00', 'UTC'));

        [$broadcast, $events] = $this->channelWithEvents(2);
        $broadcast->min_gap_minutes = 0; // тестовый канал: ждать незачем
        $broadcast->save();

        $posted = $this->makeItem($broadcast->id, $events[0]->id, TelegramChatBroadcastItem::STATUS_POSTED);
        $posted->posted_at = now()->subMinutes(2);
        $posted->save();

        $next = $this->makeItem($broadcast->id, $events[1]->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $next->publish_at = now()->subMinute();
        $next->save();

        $tasks = $this->service()->collectDueSingleRuns(now());

        $this->assertCount(1, $tasks, 'с нулевым зазором пост уходит сразу');
        $this->assertSame($next->id, $tasks[0]['item_id']);

        Carbon::setTestNow();
    }

    /** Окно простоя делится на число слотов: два поста в день — окно вдвое короче. */
    public function test_idle_window_is_divided_by_slots(): void
    {
        [$broadcast] = $this->channelWithEvents(1);

        $this->assertSame(24, $this->service()->periodWindowHours($broadcast));

        $broadcast->slots = [10, 19];
        $broadcast->save();

        $this->assertSame(12, $this->service()->periodWindowHours($broadcast->fresh()));
    }

    private function countFromVenue(int $broadcastId, int $venueId): int
    {
        return TelegramChatBroadcastItem::query()
            ->from('telegram.chat_broadcast_items as i')
            ->join('events as e', 'e.id', '=', 'i.event_id')
            ->where('i.broadcast_id', $broadcastId)
            ->whereNotNull('i.publish_at')
            ->where('e.venue_id', $venueId)
            ->count();
    }

    /**
     * Канал, где одна площадка даёт шесть событий, а ещё двенадцать площадок —
     * по одному: пула хватает, чтобы фильтр не выродился.
     *
     * @return array{0: TelegramChatBroadcast, 1: array{crowded: int}}
     */
    private function channelWithVenueEvents(): array
    {
        [$broadcast, $events] = $this->channelWithEvents(0);
        $cityId = (int) $broadcast->chat->city_id;

        // События «Матрёшки» скоринг любит сильнее всех: описание, точное
        // время, билеты в продаже. Иначе тест ничего не проверял бы — при
        // равном скоринге площадку могли просто не выбрать, и ноль повторов
        // прошёл бы любую проверку «не больше N».
        $crowded = $this->insertVenue($cityId, 'Матрёшка', 'matreshka');
        for ($i = 0; $i < 6; $i++) {
            $this->insertEvent($cityId, $crowded, 'Вечер у Матрёшки '.$i, 3 + $i, attractive: true);
        }
        // Альтернатив заведомо больше, чем слотов: иначе сработал бы мягкий
        // откат «лучше повторить площадку, чем не запостить вовсе», и тест
        // мерил бы исчерпание пула, а не порог.
        for ($i = 0; $i < 20; $i++) {
            $venue = $this->insertVenue($cityId, 'Площадка '.$i, 'venue-'.$i);
            $this->insertEvent($cityId, $venue, 'Событие площадки '.$i, 3 + ($i % 6));
        }

        return [$broadcast, ['crowded' => $crowded]];
    }

    private function insertVenue(int $cityId, string $name, string $slug): int
    {
        return (int) DB::table('venues')->insertGetId([
            'city_id' => $cityId,
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEvent(int $cityId, int $venueId, string $title, int $inDays, bool $attractive = false): void
    {
        $community = Community::create(['name' => $title.' орг', 'city_id' => $cityId]);

        $event = new Event;
        $event->community_id = $community->id;
        $event->title = $title;
        $event->status = 'active';
        $event->city_id = $cityId;
        $event->venue_id = $venueId;
        $event->start_time = now()->addDays($inDays)->setTime(19, 0);
        $event->start_date = $event->start_time->toDateString();
        if ($attractive) {
            $event->description = str_repeat('Подробное описание вечера. ', 12);
            $event->tickets_status = 'available';
            $event->time_precision = 'datetime';
        }
        $event->save();
    }

    /**
     * Ждущие дня разбираются первыми, а не лежат вечно.
     *
     * Наполнитель каждый раз брал новое событие из пула, и очередь ожидания не
     * рассасывалась никогда — при том что в интерфейсе написано «дождитесь,
     * пока день освободится».
     */
    public function test_waiting_items_take_free_slots_first(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC')); // 06:00 МСК

        [$broadcast, $events] = $this->channelWithEvents(3);

        // Запись без дня: ждёт свободного слота.
        $waiting = $this->makeItem($broadcast->id, $events[0]->id, TelegramChatBroadcastItem::STATUS_PENDING);

        $this->service()->fillFeedDays($broadcast->fresh(), now());

        $fresh = $waiting->fresh();
        $this->assertNotNull($fresh->publish_at, 'ждавшая запись получила день');
        $this->assertSame(
            '2026-09-15 10',
            Carbon::parse($fresh->publish_at)->setTimezone('Europe/Moscow')->format('Y-m-d H'),
            'и именно ближайший свободный слот',
        );

        Carbon::setTestNow();
    }

    /** @return list<int> */
    private function publishHours(int $broadcastId): array
    {
        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->whereNotNull('publish_at')
            ->pluck('publish_at')
            ->map(fn ($d) => (int) Carbon::parse($d)->setTimezone('Europe/Moscow')->format('H'))
            ->all();
    }

    /** @return list<string> */
    private function publishDays(int $broadcastId): array
    {
        return TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcastId)
            ->whereNotNull('publish_at')
            ->pluck('publish_at')
            ->map(fn ($d) => Carbon::parse($d)->setTimezone('Europe/Moscow')->toDateString())
            ->all();
    }

    /** @return array{0: TelegramChatBroadcast, 1: list<Event>} */
    /**
     * Дальний слот выбирает из СВОЕГО горизонта, а не из сегодняшнего.
     *
     * Верхняя граница окна кандидатов считалась от «сейчас», нижняя — от слота:
     * чем дальше слот, тем уже окно, и в конце недели оно схлопывалось в ноль.
     * Замер по живому каналу: на слот через 13 дней кандидатов с суточной форой
     * было НОЛЬ против 59 при окне от слота.
     */
    public function test_far_slot_sees_events_within_its_own_horizon(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC'));

        [$broadcast] = $this->channelWithEvents(0);
        $broadcast->settings = array_merge((array) $broadcast->settings, [
            'slots' => [10],
            'horizon_days' => 14,
        ]);
        $broadcast->save();

        $city = City::query()->where('slug', 'voronezh')->firstOrFail();
        $venue = $this->insertVenue($city->id, 'Дальняя площадка', 'far-venue');
        // Событие через 20 дней: из окна «сегодня + 14» оно не видно ни одному
        // слоту, из окна «слот + 14» — видно слотам начиная с шестого дня.
        $this->insertEvent($city->id, $venue, 'Событие дальнего дня', 20, attractive: true);

        $this->service()->fillFeedDays($broadcast->fresh(), now());

        $event = Event::query()->where('title', 'Событие дальнего дня')->firstOrFail();

        $this->assertSame(1, TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('event_id', $event->id)
            ->count(), 'событие дальнего дня обязано попасть в ленту');
    }

    /**
     * Пост не встаёт впритык к началу события и тем более после него.
     *
     * Прежнее условие пускало однодневку по `end_time >= момент публикации`:
     * мастер-класс 13:00–15:00 стоял в слоте 15:00, то есть пост уходил в
     * минуту окончания. Из 27 записей живой ленты 5 были про уже начавшееся.
     */
    public function test_event_starting_right_after_the_slot_is_not_scheduled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC')); // 06:00 МСК

        [$broadcast] = $this->channelWithEvents(0);
        $broadcast->settings = array_merge((array) $broadcast->settings, [
            'slots' => [10],
            'horizon_days' => 2,
        ]);
        $broadcast->save();

        $city = City::query()->where('slug', 'voronezh')->firstOrFail();
        $venue = $this->insertVenue($city->id, 'Площадка впритык', 'tight-venue');

        // Сегодня в 12:00 МСК — через два часа после слота 10:00.
        $community = Community::create(['name' => 'Орг впритык', 'city_id' => $city->id]);
        $tight = new Event;
        $tight->community_id = $community->id;
        $tight->title = 'Событие через два часа';
        $tight->status = 'active';
        $tight->city_id = $city->id;
        $tight->venue_id = $venue;
        $tight->start_time = Carbon::parse('2026-09-15 12:00', 'Europe/Moscow');
        $tight->end_time = Carbon::parse('2026-09-15 14:00', 'Europe/Moscow');
        $tight->start_date = $tight->start_time->toDateString();
        $tight->description = str_repeat('Подробное описание вечера. ', 12);
        $tight->tickets_status = 'available';
        $tight->save();

        $this->service()->fillFeedDays($broadcast->fresh(), now());

        $scheduled = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('event_id', $tight->id)
            ->first();

        $this->assertNull($scheduled, 'пост за два часа до начала ленте не нужен');
    }

    /**
     * Поздний слот держится свободным до своей границы.
     *
     * Половина афиши объявляется поздно (медиана форы анонса — трое суток), и
     * слот, занятый за две недели, закрыт для всего, что появится потом.
     * Первый слот дня при этом остаётся плановым: неделю надо видеть заранее.
     */
    public function test_late_slot_waits_for_its_own_lead(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC')); // 06:00 МСК

        [$broadcast] = $this->channelWithEvents(12);
        $broadcast->settings = array_merge((array) $broadcast->settings, [
            'slots' => [10, 19],
            'horizon_days' => 5,
            'fill_lead_days' => 1,
        ]);
        $broadcast->save();

        $this->service()->fillFeedDays($broadcast->fresh(), now());

        $byDay = [];
        foreach (TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereNotNull('publish_at')
            ->pluck('publish_at') as $at) {
            $msk = Carbon::parse($at)->setTimezone('Europe/Moscow');
            $byDay[$msk->toDateString()][] = (int) $msk->format('H');
        }
        foreach ($byDay as &$hours) {
            sort($hours);
        }
        unset($hours);

        $this->assertSame(
            [
                '2026-09-15' => [10, 19],
                '2026-09-16' => [10],
                '2026-09-17' => [10],
                '2026-09-18' => [10],
                '2026-09-19' => [10],
            ],
            $byDay,
            'утро собирается на весь горизонт, вечер — только в пределах своих суток',
        );
    }

    /** Без настройки оба слота заполняются как раньше — на весь горизонт. */
    public function test_without_the_setting_both_slots_fill_the_horizon(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC'));

        [$broadcast] = $this->channelWithEvents(12);
        $broadcast->settings = array_merge((array) $broadcast->settings, [
            'slots' => [10, 19],
            'horizon_days' => 3,
        ]);
        $broadcast->save();

        $this->service()->fillFeedDays($broadcast->fresh(), now());

        $this->assertSame(
            [10, 10, 10, 19, 19, 19],
            collect($this->publishHours($broadcast->id))->sort()->values()->all(),
            'три дня по два слота',
        );
    }

    /**
     * Лента заполняется по расписанию, а не только по кнопке.
     *
     * Наполнитель звали ТОЛЬКО две кнопки админки, а единственный автомат
     * (`broadcast:enqueue-due`) дня не назначает и выключается собственным
     * капом на любой собранной ленте. То есть событие, объявленное в среду, в
     * неделю попасть не могло вовсе.
     */
    public function test_scheduled_command_fills_free_slots(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC'));

        [$broadcast] = $this->channelWithEvents(4);
        $broadcast->settings = array_merge((array) $broadcast->settings, [
            'slots' => [10],
            'horizon_days' => 3,
        ]);
        $broadcast->save();

        $this->artisan('broadcast:fill-feed')->assertExitCode(0);

        $this->assertSame(3, TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereNotNull('publish_at')
            ->count(), 'три свободных слота горизонта закрыты без единого нажатия');
    }

    /**
     * Пост нельзя поставить позже начала события — ни одной дверью.
     *
     * Правило разошлось по дверям: подбор требовал фору, перетаскивание
     * пускало пост до КОНЦА события. Из-за этого в ленте появлялись записи,
     * стоящие в день события, но позже него: доставка такую снимает, а слот
     * сгорает молча. Владелец заметил это раньше тестов.
     */
    public function test_post_cannot_be_moved_past_the_event_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC')); // 06:00 МСК
        $this->actAsSuperadmin();

        [$broadcast] = $this->channelWithEvents(0);
        $broadcast->slots = [10, 19];
        $broadcast->save();

        $city = City::query()->where('slug', 'voronezh')->firstOrFail();
        $community = Community::create(['name' => 'Орг вечернего', 'city_id' => $city->id]);

        // Концерт сегодня в 13:00, идёт два часа.
        $event = new Event;
        $event->community_id = $community->id;
        $event->title = 'Концерт в обед';
        $event->status = 'active';
        $event->city_id = $city->id;
        $event->start_time = Carbon::parse('2026-09-15 13:00', 'Europe/Moscow')->utc();
        $event->end_time = Carbon::parse('2026-09-15 15:00', 'Europe/Moscow')->utc();
        $event->start_date = $event->start_time->toDateString();
        $event->save();

        $item = $this->makeItem($broadcast->id, $event->id, TelegramChatBroadcastItem::STATUS_PENDING);
        $item->publish_at = Carbon::parse('2026-09-15 10:00', 'Europe/Moscow')->utc();
        $item->save();

        // 14:00 — концерт УЖЕ ИДЁТ. Прежнее правило пускало пост до конца
        // события, то есть анонс мог уехать в антракте.
        $this->postJson("/api/admin/broadcast/channels/{$broadcast->id}/move", [
            'item_id' => $item->id,
            'publish_at' => Carbon::parse('2026-09-15 14:00', 'Europe/Moscow')->format('Y-m-d\TH:i:sP'),
        ])->assertStatus(422);

        // И тем более вечером того же дня.
        $this->postJson("/api/admin/broadcast/channels/{$broadcast->id}/move", [
            'item_id' => $item->id,
            'publish_at' => Carbon::parse('2026-09-15 19:00', 'Europe/Moscow')->format('Y-m-d\TH:i:sP'),
        ])->assertStatus(422);

        $this->assertSame(
            '2026-09-15 10:00',
            Carbon::parse($item->fresh()->publish_at)->setTimezone('Europe/Moscow')->format('Y-m-d H:i'),
            'пост остался на своём слоте',
        );
    }

    /**
     * Многодневку можно анонсировать, пока она идёт.
     *
     * У выставки «успеть» — это успеть до закрытия, а не до открытия. Правило
     * «не позже начала» на неё не распространяется, иначе из ленты вылетели бы
     * все выставки и прокаты.
     */
    public function test_running_exhibition_can_still_be_scheduled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC'));
        $this->actAsSuperadmin();

        [$broadcast] = $this->channelWithEvents(0);
        $broadcast->slots = [10];
        $broadcast->save();

        $city = City::query()->where('slug', 'voronezh')->firstOrFail();
        $community = Community::create(['name' => 'Музей', 'city_id' => $city->id]);

        $event = new Event;
        $event->community_id = $community->id;
        $event->title = 'Выставка стекла';
        $event->status = 'active';
        $event->city_id = $city->id;
        $event->start_time = Carbon::parse('2026-09-01 11:00', 'Europe/Moscow')->utc(); // уже открылась
        $event->end_time = Carbon::parse('2026-10-01 20:00', 'Europe/Moscow')->utc();
        $event->start_date = $event->start_time->toDateString();
        $event->save();

        $item = $this->makeItem($broadcast->id, $event->id, TelegramChatBroadcastItem::STATUS_PENDING);

        $this->postJson("/api/admin/broadcast/channels/{$broadcast->id}/move", [
            'item_id' => $item->id,
            'publish_at' => Carbon::parse('2026-09-18 10:00', 'Europe/Moscow')->format('Y-m-d\TH:i:sP'),
        ])->assertOk();
    }

    /**
     * Возврат снятого не ставит пост в слот, до которого событие уже пройдёт.
     *
     * Планировщик ищет пустую ячейку и про сроки не знает. Возврат от этого не
     * отменяем — запись встаёт без дня и ждёт подходящего слота.
     */
    public function test_restored_post_waits_when_the_free_slot_is_too_late(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC'));
        $this->actAsSuperadmin();

        [$broadcast] = $this->channelWithEvents(0);
        $broadcast->slots = [19];
        $broadcast->settings = array_merge((array) $broadcast->settings, ['horizon_days' => 7]);
        $broadcast->save();

        $city = City::query()->where('slug', 'voronezh')->firstOrFail();
        $community = Community::create(['name' => 'Орг сегодняшнего', 'city_id' => $city->id]);

        // Событие сегодня в 13:00 — ближайший свободный слот (сегодня 19:00)
        // уже позже него.
        $event = new Event;
        $event->community_id = $community->id;
        $event->title = 'Сегодняшний концерт';
        $event->status = 'active';
        $event->city_id = $city->id;
        $event->start_time = Carbon::parse('2026-09-15 13:00', 'Europe/Moscow')->utc();
        $event->end_time = Carbon::parse('2026-09-15 15:00', 'Europe/Moscow')->utc();
        $event->start_date = $event->start_time->toDateString();
        $event->save();

        $item = $this->makeItem($broadcast->id, $event->id, TelegramChatBroadcastItem::STATUS_SKIPPED);

        $this->postJson("/api/admin/broadcast/items/{$item->id}/restore")->assertOk();

        $this->assertNull($item->fresh()->publish_at,
            'слот позже события не занимаем — запись ждёт подходящего');
    }

    private function actAsSuperadmin(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web');
        $user = \App\Models\User::factory()->create();
        $user->assignRole('superadmin');
        \Laravel\Sanctum\Sanctum::actingAs($user);
    }

    private function channelWithEvents(int $count): array
    {
        $city = $this->insertCity();
        $owner = TelegramUser::create(['telegram_id' => 9100200]);

        $chat = new TelegramChat;
        $chat->telegram_chat_id = -1009100200;
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

        $events = [];
        for ($i = 0; $i < $count; $i++) {
            $community = Community::create(['name' => 'Организатор '.$i, 'city_id' => $city->id]);
            $event = new Event;
            $event->community_id = $community->id;
            $event->title = 'Событие '.$i;
            $event->status = 'active';
            $event->city_id = $city->id;
            $event->start_time = now()->addDays(3 + $i)->setTime(19, 0);
            $event->start_date = $event->start_time->toDateString();
            $event->save();
            $events[] = $event;
        }

        return [$broadcast, $events];
    }

    private function makeItem(int $broadcastId, int $eventId, string $status): TelegramChatBroadcastItem
    {
        $item = new TelegramChatBroadcastItem;
        $item->broadcast_id = $broadcastId;
        $item->event_id = $eventId;
        $item->status = $status;
        $item->save();

        return $item;
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
    /**
     * Перетаскивание в вечерний слот не меняется местами с утренним.
     *
     * Место в ленте — это слот, а не день: со сравнением по дню перенос в
     * свободный вечер находил «занявшим» утренний пост того же дня и менялся
     * местами с ним. Человек двигал пост в пустое место, а получал обмен с
     * чужим и отказ «второй пост уехал бы за своё событие».
     */
    public function test_moving_into_the_evening_slot_ignores_the_morning_post(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:00:00', 'UTC'));

        \Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web');
        $user = \App\Models\User::factory()->create();
        $user->assignRole('superadmin');
        \Laravel\Sanctum\Sanctum::actingAs($user);

        [$broadcast] = $this->channelWithEvents(6);
        $broadcast->slots = [10, 19];
        $broadcast->save();

        $this->service()->fillFeedDays($broadcast->fresh(), now());

        $items = TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereNotNull('publish_at')
            ->orderBy('publish_at')
            ->get();

        $morning = $items->first(fn ($i) => Carbon::parse($i->publish_at)
            ->setTimezone('Europe/Moscow')->hour === 10);
        $evening = $items->first(fn ($i) => Carbon::parse($i->publish_at)
            ->setTimezone('Europe/Moscow')->hour === 19 && $i->id !== $morning?->id);

        $this->assertNotNull($morning);
        $this->assertNotNull($evening);

        // Освобождаем вечер того дня, где стоит утренний пост, и переносим туда.
        $target = Carbon::parse($morning->publish_at)->setTimezone('Europe/Moscow')->setTime(19, 0);
        TelegramChatBroadcastItem::query()
            ->where('broadcast_id', $broadcast->id)
            ->whereNotNull('publish_at')
            ->get()
            ->filter(fn ($i) => $i->id !== $morning->id
                && Carbon::parse($i->publish_at)->setTimezone('Europe/Moscow')->format('Y-m-d H') === $target->format('Y-m-d H'))
            ->each(fn ($i) => $i->forceFill(['publish_at' => null])->save());

        $morningAt = $morning->publish_at;

        $this->postJson("/api/admin/broadcast/channels/{$broadcast->id}/move", [
            'item_id' => $evening->id,
            'publish_at' => $target->format('Y-m-d\TH:i:sP'),
        ])->assertOk();

        $this->assertSame(
            Carbon::parse($morningAt)->toIso8601String(),
            Carbon::parse($morning->fresh()->publish_at)->toIso8601String(),
            'утренний пост остался на своём месте — его никто не двигал',
        );

        Carbon::setTestNow();
    }
}
