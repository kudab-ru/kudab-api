<?php

namespace Tests\Feature\Api;

use App\Models\Community;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BotEventRemindersTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bot.shared_token' => 'test-bot-token', 'services.bot.reminder_test_minutes' => 0]);
        DB::insert(
            'INSERT INTO cities (name, country_code, location, status, slug, created_at, updated_at)
             VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, ?, ?, ?)',
            ['Воронеж', 'RU', 39.2, 51.66, 'active', 'voronezh', now(), now()]
        );
        $this->cityId = (int) DB::table('cities')->where('slug', 'voronezh')->value('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function times(): array
    {
        return [
            'накануне вечером' => ['2026-11-03 12:00', '2026-11-07 19:00', null, 'scheduled', '2026-11-06 18:00'],
            'вечер прошёл, событие днём' => ['2026-11-06 21:00', '2026-11-07 19:00', null, 'scheduled', '2026-11-07 10:00'],
            'вечер прошёл, событие утром' => ['2026-11-06 21:00', '2026-11-07 08:45', null, 'scheduled', '2026-11-07 06:45'],
            'уже не успеть' => ['2026-11-07 18:00', '2026-11-07 19:00', null, 'soon', null],
            'идущая выставка' => ['2026-11-03 12:00', '2026-10-01 10:00', '2026-11-30 18:00', 'scheduled', '2026-11-29 18:00'],
        ];
    }

    #[Test]
    #[DataProvider('times')]
    public function the_reminder_time_follows_the_event(string $now, string $start, ?string $end, string $status, ?string $at): void
    {
        Carbon::setTestNow(Carbon::parse($now, 'Europe/Moscow'));
        $event = $this->event($start, $end);

        $data = $this->remind($event)->json('data');

        $this->assertSame($status, $data['status']);
        $this->assertSame($at, isset($data['remind_at']) ? Carbon::parse($data['remind_at'])->setTimezone('Europe/Moscow')->format('Y-m-d H:i') : null);
    }

    #[Test]
    public function a_due_reminder_goes_out_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00', 'Europe/Moscow'));
        $this->remind($this->event('2026-11-07 19:00'));

        Carbon::setTestNow(Carbon::parse('2026-11-06 18:01', 'Europe/Moscow'));
        $due = $this->bot('/api/bot/reminders/due')->json('data');
        $again = $this->bot('/api/bot/reminders/due')->json('data');

        $this->assertCount(1, $due);
        $this->assertSame('завтра', $due[0]['day']);
        $this->assertSame([], $again);

        $this->bot('/api/bot/reminders/mark', ['id' => $due[0]['id'], 'ok' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-06 19:00', 'Europe/Moscow'));
        $this->assertSame([], $this->bot('/api/bot/reminders/due')->json('data'));
    }

    #[Test]
    public function the_extra_reminder_comes_three_hours_before_and_one_tap_cancels_both(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00', 'Europe/Moscow'));
        $id = $this->remind($this->event('2026-11-07 20:00'))->json('data.id');

        $soon = $this->bot('/api/bot/reminders/soon', ['telegram_id' => 555, 'id' => $id])->json('data');
        $this->assertSame('2026-11-07 17:00', Carbon::parse($soon['remind_at'])->setTimezone('Europe/Moscow')->format('Y-m-d H:i'));

        $this->bot('/api/bot/reminders/cancel', ['telegram_id' => 555, 'id' => $id]);
        Carbon::setTestNow(Carbon::parse('2026-11-07 17:30', 'Europe/Moscow'));
        $this->assertSame([], $this->bot('/api/bot/reminders/due')->json('data'));
    }

    #[Test]
    public function a_moved_event_is_reminded_on_its_new_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00', 'Europe/Moscow'));
        $old = $this->event('2026-11-07 19:00');
        $new = $this->event('2026-11-21 19:00');
        $this->remind($old);
        Event::query()->whereKey($old)->update(['status' => 'moved']);
        DB::table('event_changes')->insert(['event_id' => $old, 'kind' => 'moved', 'new_event_id' => $new, 'created_at' => now(), 'updated_at' => now()]);

        Carbon::setTestNow(Carbon::parse('2026-11-06 18:01', 'Europe/Moscow'));
        $due = $this->bot('/api/bot/reminders/due')->json('data');

        $this->assertSame('moved', $due[0]['kind']);
        $this->assertSame($new, $due[0]['moved_to']['id']);
        $this->assertSame(
            '2026-11-20 18:00',
            Carbon::parse(DB::table('telegram.event_reminders')->where('event_id', $new)->value('remind_at'))->setTimezone('Europe/Moscow')->format('Y-m-d H:i'),
        );
    }

    #[Test]
    public function a_shifted_date_waits_for_its_new_evening(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00', 'Europe/Moscow'));
        $event = $this->event('2026-11-07 19:00');
        $this->remind($event);
        Event::query()->whereKey($event)->update(['start_time' => Carbon::parse('2026-11-14 19:00', 'Europe/Moscow')->utc(), 'start_date' => '2026-11-14']);

        Carbon::setTestNow(Carbon::parse('2026-11-06 18:01', 'Europe/Moscow'));

        $this->assertSame([], $this->bot('/api/bot/reminders/due')->json('data'));
        $this->assertSame(
            '2026-11-13 18:00',
            Carbon::parse(DB::table('telegram.event_reminders')->where('event_id', $event)->value('remind_at'))->setTimezone('Europe/Moscow')->format('Y-m-d H:i'),
        );
    }

    private function remind(int $eventId)
    {
        return $this->bot('/api/bot/reminders', ['telegram_id' => 555, 'chat_id' => 555, 'event_id' => $eventId]);
    }

    private function bot(string $url, array $body = [])
    {
        return $this->withHeader('Authorization', 'Bearer test-bot-token')->postJson($url, $body)->assertOk();
    }

    private function event(string $start, ?string $end = null): int
    {
        $at = Carbon::parse($start, 'Europe/Moscow');
        $event = new Event;
        $event->community_id = Community::create(['name' => 'Организатор '.uniqid(), 'city_id' => $this->cityId])->id;
        $event->city_id = $this->cityId;
        $event->venue_id = DB::table('venues')->insertGetId([
            'city_id' => $this->cityId, 'name' => 'Площадка '.uniqid(), 'slug' => 'venue-'.uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $event->title = 'Концерт';
        $event->status = 'active';
        $event->start_time = $at->copy()->utc();
        $event->start_date = $at->toDateString();
        $event->time_precision = 'datetime';
        $event->end_time = $end !== null ? Carbon::parse($end, 'Europe/Moscow')->utc() : null;
        $event->description = str_repeat('описание события достаточной длины. ', 5);
        $event->save();

        return (int) $event->id;
    }
}
