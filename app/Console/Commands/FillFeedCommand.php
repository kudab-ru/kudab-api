<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\TelegramChat;
use App\Models\TelegramChatBroadcast;
use App\Models\Venue;
use App\Services\Telegram\BroadcastSlotPlanner;
use App\Services\Telegram\PickReason;
use App\Services\Telegram\TelegramChatBroadcastService;
use App\Support\BroadcastSafety;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Заполнить свободные слоты ленты по расписанию. Занятый слот не трогает: лента, которая
 * сама себя переставляет, не даёт верить, что увиденное утром выйдет вечером.
 * Запрет стенда тот же, что у enqueueDueForAllChannels (см. BroadcastSafety).
 */
class FillFeedCommand extends Command
{
    protected $signature = 'broadcast:fill-feed
        {--broadcast= : Только один канал, по id}
        {--dry-run : Показать выборы и ничего не писать}
        {--at= : Только с --dry-run: прогон как будто сейчас это время по Москве, например "2026-10-01 19:05"}
        {--mode= : Только с --dry-run: выбрать как в режиме week или eve, не переключая канал}';

    protected $description = 'Заполнить свободные слоты ленты каналов (то же, что кнопка «Пересобрать», но только свободное)';

    /** Что стало с составом подборки; коды — из prepareDigestAhead. */
    private const DIGEST_OUTCOMES = [
        'composed' => 'собран состав',
        'text_requested' => 'собран состав, заказан текст',
        'ready' => 'состав уже готов',
        'failed' => 'состава не набралось, бронь снята',
    ];

    public function handle(TelegramChatBroadcastService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $at = $this->option('at');
        $mode = $this->option('mode');

        if (($at !== null || $mode !== null) && ! $dryRun) {
            $this->error('--at и --mode работают только вместе с --dry-run');

            return self::FAILURE;
        }
        if ($mode !== null && ! in_array($mode, [TelegramChatBroadcast::PICK_MODE_WEEK, TelegramChatBroadcast::PICK_MODE_EVE], true)) {
            $this->error('--mode: week или eve');

            return self::FAILURE;
        }

        $testNowBefore = Carbon::getTestNow();
        if ($at !== null) {
            try {
                $moment = Carbon::parse((string) $at, BroadcastSlotPlanner::TZ);
            } catch (\Throwable) {
                $this->error('--at не разобрать, нужно время по Москве вида "2026-10-01 19:05"');

                return self::FAILURE;
            }
            // Время прогона и здесь, и в сервисах берётся из now(). В UTC: даты модели без пояса
            // Carbon читает в поясе подставного «сейчас», и с московским они уехали бы на три часа.
            Carbon::setTestNow($moment->copy()->utc());
            $this->line('Как будто сейчас '.$moment->copy()->setTimezone(BroadcastSlotPlanner::TZ)->format('d.m.Y H:i').' по Москве');
        }

        try {
            return $this->fill($service, $dryRun, $mode);
        } finally {
            Carbon::setTestNow($testNowBefore);
        }
    }

    private function fill(TelegramChatBroadcastService $service, bool $dryRun, ?string $mode): int
    {
        $now = Carbon::now();

        $query = TelegramChatBroadcast::query()->with('chat');
        if ($this->option('broadcast') !== null) {
            $query->where('id', (int) $this->option('broadcast'));
        }

        $summary = ['checked' => 0, 'filled' => 0, 'channels' => 0, 'not_allowed' => 0, 'off' => 0];

        foreach ($query->get() as $broadcast) {
            $summary['checked']++;

            if (! $broadcast->enabled || $broadcast->period === 'off') {
                $summary['off']++;

                continue;
            }

            $chat = $broadcast->chat;
            if (! $chat instanceof TelegramChat || ! $chat->city_id || ! $chat->telegram_chat_id) {
                continue;
            }

            // запрет стенда — про запись в очередь: сухой прогон показывает и запрещённый канал, с пометкой
            $blocked = ! BroadcastSafety::postingAllowed((int) $chat->telegram_chat_id);
            if ($blocked && ! $dryRun) {
                $summary['not_allowed']++;

                continue;
            }

            if ($mode !== null) {
                $broadcast->pick_mode = $mode;
            }

            $filled = $dryRun
                ? $this->wouldFill($service, $broadcast, $now)
                : $service->fillFeedDays($broadcast, $now);

            $capped = (int) ($filled['feed_limit'] ?? 0);

            if ($dryRun || ($filled['filled'] ?? 0) > 0 || ($filled['no_candidate'] ?? 0) > 0 || $capped > 0) {
                $this->line(sprintf(
                    '  канал #%d%s, режим %s: %s %d, без кандидата %d, дней в горизонте %d%s',
                    $broadcast->id,
                    $blocked ? ' (стенду постить в него запрещено)' : '',
                    $broadcast->pick_mode,
                    $dryRun ? 'заполнил бы' : 'заполнено',
                    (int) ($filled['filled'] ?? 0),
                    (int) ($filled['no_candidate'] ?? 0),
                    (int) ($filled['days'] ?? 0),
                    // без этой оговорки «заполнено 0» читается как «событий в городе нет»
                    $capped > 0
                        ? sprintf(', слотов не тронуто из-за капа ленты %d (feed_limit %d)', $capped, $broadcast->feed_limit)
                        : '',
                ));
            }

            $this->printPicks($filled['picks'] ?? []);

            if (($filled['filled'] ?? 0) > 0) {
                $summary['channels']++;
                $summary['filled'] += (int) $filled['filled'];

                if (! $dryRun) {
                    Log::info('broadcast.feed.filled', [
                        'broadcast_id' => $broadcast->id,
                        'filled' => $filled['filled'],
                        'no_candidate' => $filled['no_candidate'] ?? 0,
                        'feed_limit_hits' => $capped,
                    ]);
                }
            }
        }

        $this->info(sprintf(
            'fill-feed: каналов %d, %s %d в %d каналах, выключено %d, стенд не пустил %d%s',
            $summary['checked'],
            $dryRun ? 'заполнил бы' : 'заполнено',
            $summary['filled'], $summary['channels'],
            $summary['off'], $summary['not_allowed'],
            $dryRun ? ' [DRY-RUN, ничего не записано]' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Прогон тем же кодом в транзакции с откатом, а не отдельная сухая ветка в наполнителе:
     * второй путь быстро разойдётся с первым, и сухой прогон начнёт врать.
     *
     * @return array{filled: int, days: int, no_candidate: int, feed_limit: int, picks: list<array<string, mixed>>}
     */
    private function wouldFill(
        TelegramChatBroadcastService $service,
        TelegramChatBroadcast $broadcast,
        Carbon $now,
    ): array {
        DB::beginTransaction();

        try {
            return $service->fillFeedDays($broadcast, $now);
        } finally {
            DB::rollBack();
        }
    }

    /** @param  list<array<string, mixed>>  $picks */
    private function printPicks(array $picks): void
    {
        if ($picks === []) {
            return;
        }

        $titles = Event::withTrashed()
            ->whereIn('id', array_filter(array_column($picks, 'event_id')))
            ->pluck('title', 'id');
        $venues = Venue::query()
            ->whereIn('id', array_filter(array_column($picks, 'venue_id')))
            ->pluck('name', 'id');

        foreach ($picks as $pick) {
            $what = match ($pick['by']) {
                PickReason::BY_DIGEST => 'подборка',
                PickReason::BY_PORTRAIT => sprintf('портрет площадки #%d «%s»', $pick['venue_id'], $venues[$pick['venue_id']] ?? '?'),
                default => sprintf('событие #%d «%s»', $pick['event_id'], $titles[$pick['event_id']] ?? '?'),
            };

            $reason = PickReason::forItem($pick['meta'] ?? null);
            if ($pick['by'] === PickReason::BY_DIGEST) {
                $reason .= ': '.(self::DIGEST_OUTCOMES[$pick['rule']] ?? $pick['rule']);
            }

            $this->line(sprintf(
                '    %s  %s — %s',
                Carbon::parse($pick['at'])->format('d.m H:i'),
                $what,
                $reason,
            ));
        }
    }
}
