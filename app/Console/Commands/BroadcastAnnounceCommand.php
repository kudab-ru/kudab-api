<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telegram\BroadcastAnnounce;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BroadcastAnnounceCommand extends Command
{
    protected $signature = 'broadcast:announce {--dry-run : Показать, что объявил бы, и ничего не писать}';

    protected $description = 'Пост «Объявили» о крупном событии, появившемся в афише за сутки';

    public function handle(BroadcastAnnounce $announce): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $s = $announce->run(Carbon::now(), $dryRun);

        $this->info(sprintf('announce: announced=%d burst=%d%s', $s['announced'], $s['burst'], $dryRun ? ' [DRY-RUN]' : ''));

        return self::SUCCESS;
    }
}
