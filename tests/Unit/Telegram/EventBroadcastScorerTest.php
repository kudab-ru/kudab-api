<?php

namespace Tests\Unit\Telegram;

use App\Models\Event;
use App\Services\Telegram\Scoring\EventBroadcastScorer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Веса и выбор контент-скоринга автопостинга (EventBroadcastScorer).
 * DB не нужна — события конструируем in-memory (sources/interests_count проставляем вручную).
 */
class EventBroadcastScorerTest extends TestCase
{
    private EventBroadcastScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 3, 22, 12, 0, 0, 'Europe/Moscow'));
        $this->scorer = new EventBroadcastScorer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_minimal_event_scores_zero(): void
    {
        $e = $this->makeEvent(start: now()->addDay()); // без факторов, ближайший → 0
        $this->assertSame(0, $this->scorer->score($e));
    }

    public function test_photo_adds_its_weight(): void
    {
        $e = $this->makeEvent(photo: true, start: now()->addDay());
        $this->assertSame(EventBroadcastScorer::W_PHOTO, $this->scorer->score($e));
    }

    public function test_all_factors_sum_up(): void
    {
        $e = $this->makeEvent(
            attrs: [
                'house_fias_id'  => 'fias-123',
                'venue_id'       => 7,
                'tickets_status' => 'available',
                'price_status'   => 'priced',
                'description'    => str_repeat('я', EventBroadcastScorer::MIN_DESCRIPTION_LEN),
                'time_precision' => 'datetime',
            ],
            photo: true,
            interests: 2,
            start: now()->addDay(),
        );

        $expected = EventBroadcastScorer::W_PHOTO
            + EventBroadcastScorer::W_FIAS
            + EventBroadcastScorer::W_VENUE
            + EventBroadcastScorer::W_INTERESTS
            + EventBroadcastScorer::W_TICKETS
            + EventBroadcastScorer::W_PRICE
            + EventBroadcastScorer::W_DESCRIPTION
            + EventBroadcastScorer::W_TIME_EXACT; // freshness +1д = 0

        $this->assertSame($expected, $this->scorer->score($e));
    }

    public function test_freshness_penalty_tiers(): void
    {
        $this->assertSame(0,   $this->scorer->score($this->makeEvent(start: now()->addDay())));
        $this->assertSame(-5,  $this->scorer->score($this->makeEvent(start: now()->addDays(5))));
        $this->assertSame(-12, $this->scorer->score($this->makeEvent(start: now()->addDays(20))));
        $this->assertSame(-25, $this->scorer->score($this->makeEvent(start: now()->addDays(40))));
    }

    public function test_pick_best_takes_highest_score(): void
    {
        $withPhotoFar = $this->makeEvent(photo: true, start: now()->addDays(3)); // 40 - 5 = 35
        $noPhotoSoon  = $this->makeEvent(start: now()->addDay());                // 0

        $best = $this->scorer->pickBest(new Collection([$noPhotoSoon, $withPhotoFar]));

        $this->assertSame($withPhotoFar, $best);
    }

    public function test_pick_best_tie_break_earliest_start(): void
    {
        $later   = $this->makeEvent(start: now()->addDays(2)); // 0
        $earlier = $this->makeEvent(start: now()->addDay());   // 0 — при равенстве выигрывает

        $best = $this->scorer->pickBest(new Collection([$later, $earlier]));

        $this->assertSame($earlier, $best);
    }

    public function test_pick_best_of_empty_is_null(): void
    {
        $this->assertNull($this->scorer->pickBest(new Collection()));
    }

    /**
     * @param array<string,mixed> $attrs
     */
    private function makeEvent(array $attrs = [], bool $photo = false, int $interests = 0, ?Carbon $start = null): Event
    {
        $e = new Event();
        $e->start_time = $start ?? now()->addDay();

        foreach ($attrs as $k => $v) {
            $e->setAttribute($k, $v);
        }
        $e->setAttribute('interests_count', $interests);

        $e->setRelation('sources', $photo
            ? new Collection([(object) ['images' => ['http://example.test/p.jpg']]])
            : new Collection());

        return $e;
    }
}
