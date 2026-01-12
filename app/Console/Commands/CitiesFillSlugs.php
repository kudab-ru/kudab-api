<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Models\City;

class CitiesFillSlugs extends Command
{
    protected $signature = 'cities:fill-slugs {--force : overwrite existing slugs}';
    protected $description = 'Заполнение cities.slug (unique) из cities.name';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $cities = City::query()
            ->when(!$force, fn ($q) => $q->whereNull('slug'))
            ->orderBy('id')
            ->get();

        $used = City::query()->whereNotNull('slug')->pluck('slug')->flip();

        $updated = 0;

        foreach ($cities as $city) {
            // Вариант А: кириллица -> slug с ru-транслитерацией
            $base = Str::slug((string) $city->name, '-', 'ru');

            if ($base === '') {
                $this->warn("skip city_id={$city->id}: empty slug from name='{$city->name}'");
                continue;
            }

            $slug = $base;
            $i = 2;
            while (isset($used[$slug])) {
                $slug = $base . '-' . $i;
                $i++;
            }

            $city->slug = $slug;
            $city->save();

            $used[$slug] = true;
            $updated++;
        }

        $this->info("OK: updated={$updated}");
        return self::SUCCESS;
    }
}
