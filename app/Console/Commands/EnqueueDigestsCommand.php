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
 * ближайший день рубрики: состав и текст ей соберёт broadcast:prepare-digests
 * за prepare_hours до слота — собранная раньше подборка показывает пятую
 * часть недели (почему именно столько — config/broadcast_digest.php).
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
            'digests: checked=%d booked=%d already=%d off=%d failed=%d%s',
            $s['checked'], $s['booked'], $s['already'], $s['off'], $s['failed'] ?? 0,
            $dryRun ? ' [DRY-RUN]' : '',
        ));

        // Упавший канал — не «всё хорошо». Прогон продолжается (иначе один
        // канал уносил бы остальные), но команда обязана сказать об этом
        // ненулевым кодом: иначе беда видна только в логе, куда никто не
        // смотрит, пока не спохватится.
        return ($s['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
