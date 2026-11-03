<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\City;
use App\Services\Occasions\OccasionPromo;
use Carbon\Carbon;
use Illuminate\Console\Command;

class OccasionsWarmCommand extends Command
{
    protected $signature = 'occasions:warm';

    protected $description = 'Собрать заранее страницы праздников ближайших двух месяцев';

    public function handle(OccasionPromo $promo): int
    {
        $now = Carbon::now('Europe/Moscow');
        $built = 0;
        foreach (City::query()->where('status', 'active')->get() as $city) {
            $built += $promo->warm($city, $now);
        }
        $this->info("страниц собрано: {$built}");

        return self::SUCCESS;
    }
}
