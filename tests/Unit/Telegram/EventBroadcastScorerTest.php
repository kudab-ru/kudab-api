<?php

namespace Tests\Unit\Telegram;

use App\Models\Event;
use App\Services\Telegram\Scoring\EventAccess;
use App\Services\Telegram\Scoring\EventBroadcastScorer;
use App\Services\Telegram\Scoring\EventInterestSignals;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventBroadcastScorerTest extends TestCase
{
    private EventBroadcastScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 3, 22, 12, 0, 0, 'Europe/Moscow'));
        $this->scorer = new EventBroadcastScorer(new EventInterestSignals);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function betterThan(): array
    {
        return [
            'концерт выше мастер-класса' => [['slug' => 'music'], ['slug' => 'workshops', 'parent' => 'education']],
            'разовое выше еженедельного' => [['slug' => 'theatre'], ['slug' => 'theatre', 'repeats' => 5]],
            'выставка выше квест-комнаты' => [
                ['slug' => 'exhibitions'],
                ['slug' => 'quiz-games', 'repeats' => 20, 'venue_kind' => 'Квесты'],
            ],
            'касса и оценки поднимают спектакль' => [['slug' => 'theatre', 'ticketed' => true, 'rating' => 200], ['slug' => 'theatre']],
            'с афишей выше, чем без' => [['slug' => 'music'], ['slug' => 'music', 'photo' => false]],
        ];
    }

    /**
     * @param  array<string, mixed>  $better
     * @param  array<string, mixed>  $worse
     */
    #[DataProvider('betterThan')]
    public function test_the_more_interesting_event_wins(array $better, array $worse): void
    {
        // при ничьей победило бы худшее: у него меньше номер
        $low = $this->event(1, $worse, now()->addDay());
        $high = $this->event(2, $better, now()->addDay());

        $this->assertSame($high, $this->scorer->pickBest([$low, $high], now()));
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>, string}> */
    public static function accessCases(): array
    {
        return [
            'спектакль с темой вечеринки' => [['slug' => 'parties'], ['title' => 'Спектакль «Имя»'], EventAccess::STAGE],
            'рок-концерт в зале' => [['slug' => 'rock', 'parent' => 'music', 'venue_kind' => 'Сцены'], [], EventAccess::STAGE],
            'концерт в баре' => [['slug' => 'music', 'venue_kind' => 'Клубы и бары'], [], EventAccess::BAR],
            'квест-комната' => [['slug' => 'quiz-games', 'venue_kind' => 'Квесты'], [], EventAccess::SKIP],
            'ярмарка в парке' => [['slug' => 'markets', 'venue_kind' => 'Парки'], [], EventAccess::OUT],
            'выставка в музее' => [['slug' => 'exhibitions', 'venue_kind' => 'Музеи'], [], EventAccess::WALK],
            'лекция в музее' => [['slug' => 'education', 'venue_kind' => 'Музеи'], ['title' => 'Лекция о Нилусе'], EventAccess::SIGN],
            'бесплатная лекция' => [['slug' => 'education'], ['price_status' => 'free'], EventAccess::WALK],
            'бесплатная лекция по записи' => [['slug' => 'education'], ['price_status' => 'free', 'price_text' => 'по предварительной регистрации'], EventAccess::SIGN],
            'квиз в баре' => [['slug' => 'quiz-games', 'venue_kind' => 'Клубы и бары'], [], EventAccess::SIGN],
        ];
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $attrs
     */
    #[DataProvider('accessCases')]
    public function test_access_says_how_to_get_in(array $signals, array $attrs, string $access): void
    {
        $e = $this->event(1, $signals, now()->addDay(), $attrs + ['title' => 'Событие']);

        $this->assertSame($access, $this->scorer->access($e));
    }

    public function test_free_event_rises_only_while_the_week_has_none(): void
    {
        $paid = $this->event(1, ['slug' => 'music'], now()->addDay(), ['price_status' => 'range', 'price_min' => 500]);
        $free = $this->event(2, ['slug' => 'music'], now()->addDay(), ['price_status' => 'free']);

        $this->assertSame($paid, $this->scorer->pickBest([$paid, $free], now()));
        $this->assertSame($free, $this->scorer->pickBest([$paid, $free], now(), freeWanted: true));
    }

    public function test_freshness_counts_days_by_moscow_calendar(): void
    {
        $slot = Carbon::parse('2026-03-31 10:00', 'Europe/Moscow');
        // ближнее без площадки уравнено с дальним, которое по Москве на третьей ступени; в UTC дальнее было бы на второй
        $near = $this->event(1, [], Carbon::parse('2026-04-01 19:00', 'Europe/Moscow'), ['venue_id' => null]);
        $far = $this->event(2, [], Carbon::parse('2026-04-03 01:00', 'Europe/Moscow'));

        $this->assertSame($near, $this->scorer->pickBest([$far, $near], $slot));
    }

    public function test_a_regular_event_wins_a_tie_with_a_closing_run(): void
    {
        $slot = Carbon::parse('2026-03-23 10:00', 'Europe/Moscow');
        $run = $this->event(1, [], Carbon::parse('2026-03-13 11:00', 'Europe/Moscow'), [
            'end_time' => Carbon::parse('2026-03-24 18:00', 'Europe/Moscow'),
        ]);
        $regular = $this->event(2, [], Carbon::parse('2026-03-25 19:00', 'Europe/Moscow'));

        $this->assertSame($regular, $this->scorer->pickBest([$run, $regular], $slot));
    }

    /**
     * @param  array<string, mixed>  $signals  поверх «разовое, с афишей, без темы»
     * @param  array<string, mixed>  $attrs
     */
    private function event(int $id, array $signals, Carbon $start, array $attrs = []): Event
    {
        $e = new Event;
        $e->setAttribute('id', $id);
        $e->start_time = $start;
        $e->venue_id = 7;
        $e->description = str_repeat('я', EventBroadcastScorer::MIN_DESCRIPTION_LEN);
        $e->time_precision = 'datetime';
        foreach ($attrs as $k => $v) {
            $e->setAttribute($k, $v);
        }

        $this->scorer->remember([$id => $signals + [
            'slug' => null, 'parent' => null, 'repeats' => 1, 'ticketed' => false,
            'rating' => 0, 'photo' => true, 'venue_kind' => null,
        ]]);

        return $e;
    }
}
