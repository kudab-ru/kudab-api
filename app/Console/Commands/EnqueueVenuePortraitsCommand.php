<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramVenuePortraitService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Наполнение очереди рассылки портретами площадок (этап 2 venue-portrait).
 *
 * Идёт из scheduler. Внутри — недельный каденс на канал + ротация без повторов.
 * Сам постинг — существующий bot-cron (poll → send → mark), как у событий.
 */
class EnqueueVenuePortraitsCommand extends Command
{
    protected $signature = 'broadcast:enqueue-venue-portraits {--dry-run : Ничего не писать, только показать сводку}';

    protected $description = 'Поставить портреты площадок в очередь рассылки (недельный каденс, без повторов)';

    public function handle(TelegramVenuePortraitService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $summary = $service->enqueueDueVenuePortraits(Carbon::now(), $dryRun);

        $this->info(sprintf(
            'venue-portraits: checked=%d due=%d enqueued=%d | no_city=%d busy=%d no_candidate=%d no_reviewer=%d%s',
            $summary['checked'], $summary['due'], $summary['enqueued'],
            $summary['skipped_no_city'], $summary['skipped_queue_busy'],
            $summary['no_candidate'], $summary['skipped_no_reviewer'],
            $dryRun ? ' [DRY-RUN]' : '',
        ));

        return self::SUCCESS;
    }
}
