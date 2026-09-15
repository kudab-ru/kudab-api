<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telegram\BroadcastDigestBooking;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Бронь слота под подборку недели.
 *
 * Идёт из scheduler рядом с портретами площадок. Ставит ПУСТУЮ запись на
 * ближайший день рубрики: состав и текст ей соберут перед самой отправкой —
 * подборка, собранная заранее, показывает пятую часть недели.
 */
class EnqueueDigestsCommand extends Command
{
    protected $signature = 'broadcast:enqueue-digests {--dry-run : Ничего не писать, только показать сводку}';

    protected $description = 'Забронировать слот под подборку недели в каналах, где рубрика включена';

    public function handle(BroadcastDigestBooking $booking): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $s = $booking->bookDue(Carbon::now(), $dryRun);

        $this->info(sprintf(
            'digests: checked=%d booked=%d already=%d off=%d%s',
            $s['checked'], $s['booked'], $s['already'], $s['off'],
            $dryRun ? ' [DRY-RUN]' : '',
        ));

        return self::SUCCESS;
    }
}
