<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReflectionClass;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $seeders = [
            SocialNetworksTableSeeder::class,
            CommunitiesTableSeeder::class,
            //            InterestsTableSeeder::class,
            //            InterestsCsvSeeder::class,
            //            AdditionCommunitiesTableSeeder::class,
        ];

        foreach ($seeders as $seeder) {
            $this->runSeeder($seeder);
        }
    }

    protected function runSeeder($seeder)
    {
        $seederName = (new ReflectionClass($seeder))->getShortName();

        // Проверка, был ли сидер выполнен
        if (DB::table('seeders')->where('seeder_name', $seederName)->exists()) {
            $this->command->info("$seederName has already been run.");

            return;
        }

        // Запуск сидера
        Artisan::call('db:seed', ['--class' => $seeder]);
        $this->command->info("$seederName has been run successfully.");

        // Запись в лог о выполнении сидера
        DB::table('seeders')->insert([
            'seeder_name' => $seederName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
