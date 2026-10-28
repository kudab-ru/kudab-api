<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telegram\BroadcastEventChanges;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BroadcastEventChangesCommand extends Command
{
    protected $signature = 'broadcast:event-changes {--dry-run : Показать, на сколько постов ответил бы, и ничего не писать}';

    protected $description = 'Ответить в канале на посты о событиях, которые отменили или перенесли';

    public function handle(BroadcastEventChanges $changes): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $s = $changes->run(Carbon::now(), $dryRun);

        $this->info(sprintf('event-changes: checked=%d replied=%d%s', $s['checked'], $s['replied'], $dryRun ? ' [DRY-RUN]' : ''));

        return self::SUCCESS;
    }
}
