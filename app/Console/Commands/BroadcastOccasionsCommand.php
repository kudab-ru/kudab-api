<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telegram\BroadcastOccasions;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BroadcastOccasionsCommand extends Command
{
    protected $signature = 'broadcast:occasions {--dry-run : Показать, что поставил бы, и ничего не писать}';

    protected $description = 'Поставить подборки к праздникам и напоминания о них в сам день';

    public function handle(BroadcastOccasions $occasions): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $s = $occasions->run(Carbon::now(), $dryRun);

        $this->info(sprintf('occasions: booked=%d reminded=%d%s', $s['booked'], $s['reminded'], $dryRun ? ' [DRY-RUN]' : ''));

        return self::SUCCESS;
    }
}
