<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramChatBroadcast;
use App\Services\Telegram\BroadcastQueueSweeper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Уборщик ленты, правила — в BroadcastQueueSweeper. Отдельно от наполнителя: подметать можно
 * и канал, куда стенду постить запрещено (см. BroadcastSafety), а наполнять его нельзя.
 */
class SweepQueueCommand extends Command
{
    protected $signature = 'broadcast:sweep-queue
        {--broadcast= : Только один канал, по id}
        {--dry-run : Показать, что сняли бы и перевесили, и ничего не писать}';

    protected $description = 'Снять из ленты записи, которые уже не выйдут; запись удалённого события перевесить на выжившее';

    private const VIA = [
        'link' => 'по журналу склеек',
        'dedup_key' => 'по общему ключу склейки',
        'source' => 'по исходному посту',
    ];

    public function handle(BroadcastQueueSweeper $sweeper): int
    {
        $now = Carbon::now();
        $dry = (bool) $this->option('dry-run');

        $query = TelegramChatBroadcast::query()->with('chat');
        if ($this->option('broadcast') !== null) {
            $query->where('id', (int) $this->option('broadcast'));
        }

        $summary = ['checked' => 0, BroadcastQueueSweeper::SKIPPED => 0, BroadcastQueueSweeper::MOVED => 0];

        foreach ($query->get() as $broadcast) {
            $result = $dry ? $this->dryRun($sweeper, $broadcast, $now) : $sweeper->sweep($broadcast, $now);
            $summary['checked'] += $result['checked'];

            foreach ($result['done'] as $row) {
                $summary[$row['action']]++;
                $this->line($this->describe($row));

                if (! $dry) {
                    Log::info(
                        $row['action'] === BroadcastQueueSweeper::MOVED ? 'broadcast.queue.moved' : 'broadcast.queue.swept',
                        ['broadcast_id' => $broadcast->id] + $row,
                    );
                }
            }
        }

        $this->info(sprintf(
            'sweep-queue: проверено %d, %s %d, %s %d, живых %d%s',
            $summary['checked'],
            $dry ? 'сняли бы' : 'снято',
            $summary[BroadcastQueueSweeper::SKIPPED],
            $dry ? 'перевесили бы' : 'перевешено',
            $summary[BroadcastQueueSweeper::MOVED],
            $summary['checked'] - $summary[BroadcastQueueSweeper::SKIPPED],
            $dry ? ' [DRY-RUN, ничего не записано]' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Сухой прогон — как у broadcast:fill-feed, см. `FillFeedCommand::wouldFill`.
     *
     * @return array{checked: int, done: list<array<string, mixed>>}
     */
    private function dryRun(BroadcastQueueSweeper $sweeper, TelegramChatBroadcast $broadcast, Carbon $now): array
    {
        DB::beginTransaction();

        try {
            return $sweeper->sweep($broadcast, $now);
        } finally {
            DB::rollBack();
        }
    }

    /** @param  array<string, mixed>  $row */
    private function describe(array $row): string
    {
        $where = sprintf(
            '<fg=yellow>#%d</> (событие %s%s)',
            $row['item_id'],
            $row['event_id'] ?? '?',
            $row['at'] !== null ? ', слот '.Carbon::parse($row['at'])->format('d.m H:i') : ', без дня',
        );

        return $row['action'] === BroadcastQueueSweeper::MOVED
            ? sprintf('%s — перевешено на событие %d %s', $where, $row['to_event_id'], self::VIA[$row['via']] ?? $row['via'])
            : sprintf('%s — %s', $where, $row['reason']);
    }
}
