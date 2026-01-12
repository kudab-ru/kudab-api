<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CitiesSeeder extends Seeder
{
    public function run(): void
    {
        // Встроенный набор: RU + координаты центроидов (lon/lat)
        // slug задаём явно, чтобы не зависеть от транслитерации/команд fill-slugs
        $rows = [
            ['name' => 'Москва',            'slug' => 'moskva',             'country_code' => 'RU', 'lon' => 37.617635, 'lat' => 55.755814, 'status' => 'active'],
            ['name' => 'Санкт-Петербург',   'slug' => 'sankt-peterburg',    'country_code' => 'RU', 'lon' => 30.335099, 'lat' => 59.934280, 'status' => 'active'],
            ['name' => 'Новосибирск',       'slug' => 'novosibirsk',        'country_code' => 'RU', 'lon' => 82.935733, 'lat' => 55.008353, 'status' => 'active'],
            ['name' => 'Екатеринбург',      'slug' => 'ekaterinburg',       'country_code' => 'RU', 'lon' => 60.605703, 'lat' => 56.838926, 'status' => 'active'],
            ['name' => 'Нижний Новгород',   'slug' => 'niznii-novgorod',    'country_code' => 'RU', 'lon' => 43.936059, 'lat' => 56.296504, 'status' => 'active'],
            ['name' => 'Казань',            'slug' => 'kazan',              'country_code' => 'RU', 'lon' => 49.106414, 'lat' => 55.796127, 'status' => 'active'],
            ['name' => 'Челябинск',         'slug' => 'celiabinsk',         'country_code' => 'RU', 'lon' => 61.436843, 'lat' => 55.164441, 'status' => 'active'],
            ['name' => 'Самара',            'slug' => 'samara',             'country_code' => 'RU', 'lon' => 50.100202, 'lat' => 53.195878, 'status' => 'active'],
            ['name' => 'Омск',              'slug' => 'omsk',               'country_code' => 'RU', 'lon' => 73.368212, 'lat' => 54.989342, 'status' => 'active'],
            ['name' => 'Ростов-на-Дону',    'slug' => 'rostov-na-donu',     'country_code' => 'RU', 'lon' => 39.701505, 'lat' => 47.235713, 'status' => 'active'],
            ['name' => 'Уфа',               'slug' => 'ufa',                'country_code' => 'RU', 'lon' => 55.972055, 'lat' => 54.738760, 'status' => 'active'],
            ['name' => 'Красноярск',        'slug' => 'krasnoiarsk',        'country_code' => 'RU', 'lon' => 92.893248, 'lat' => 56.015283, 'status' => 'active'],
            ['name' => 'Пермь',             'slug' => 'perm',               'country_code' => 'RU', 'lon' => 56.229443, 'lat' => 58.010456, 'status' => 'active'],
            ['name' => 'Воронеж',           'slug' => 'voronezh',           'country_code' => 'RU', 'lon' => 39.200269, 'lat' => 51.660781, 'status' => 'active'],
        ];

        foreach ($rows as $r) {
            DB::statement(
                "INSERT INTO cities (name, slug, country_code, location, status, created_at, updated_at)
                 VALUES (?, ?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, NOW(), NOW())
                 ON CONFLICT (country_code, name_ci)
                 DO UPDATE SET
                   slug       = EXCLUDED.slug,
                   location   = EXCLUDED.location,
                   status     = EXCLUDED.status,
                   updated_at = NOW()",
                [$r['name'], $r['slug'], $r['country_code'], $r['lon'], $r['lat'], $r['status']]
            );
        }
    }
}
