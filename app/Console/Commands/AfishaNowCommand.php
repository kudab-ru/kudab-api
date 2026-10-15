<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\AfishaNow;
use Illuminate\Console\Command;

class AfishaNowCommand extends Command
{
    protected $signature = 'afisha:now';

    protected $description = 'Пересчитать «Что сейчас в афише» для админки';

    public function handle(AfishaNow $afisha): int
    {
        $s = $afisha->rebuild();
        $this->info("событий впереди {$s['events_ahead']}, тем ".count($s['themes']));

        return self::SUCCESS;
    }
}
