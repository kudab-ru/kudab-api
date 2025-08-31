<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SocialNetworksTableSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $socialNetworks = [
            [
                'name' => 'VK',
                'slug' => 'vk',
                'icon' => '🖖',
                'url_mask' => 'https://vk.com/{slug}',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Telegram',
                'slug' => 'telegram',
                'icon' => '✈️',
                'url_mask' => 'https://t.me/{slug}',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Афиша/Сайт',
                'slug' => 'site',
                'icon' => '🌐',
                'url_mask' => '{url}',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($socialNetworks as $network) {
            DB::table('social_networks')->updateOrInsert(
                ['slug' => $network['slug']],
                $network
            );
        }
    }
}
