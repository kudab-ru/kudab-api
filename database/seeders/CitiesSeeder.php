<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CitiesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Встроенный набор: RU + координаты центроидов (lon/lat)
        // status у всех 'active' (пример с 'disabled' оставлен в комментарии)
        $rows = [
            ['name' => 'Москва',            'country_code' => 'RU', 'lon' => 37.617635, 'lat' => 55.755814, 'status' => 'active'],
            ['name' => 'Санкт-Петербург',   'country_code' => 'RU', 'lon' => 30.335099, 'lat' => 59.934280, 'status' => 'active'],
            ['name' => 'Новосибирск',       'country_code' => 'RU', 'lon' => 82.935733, 'lat' => 55.008353, 'status' => 'active'],
            ['name' => 'Екатеринбург',      'country_code' => 'RU', 'lon' => 60.605703, 'lat' => 56.838926, 'status' => 'active'],
            ['name' => 'Нижний Новгород',   'country_code' => 'RU', 'lon' => 43.936059, 'lat' => 56.296504, 'status' => 'active'],
            ['name' => 'Казань',            'country_code' => 'RU', 'lon' => 49.106414, 'lat' => 55.796127, 'status' => 'active'],
            ['name' => 'Челябинск',         'country_code' => 'RU', 'lon' => 61.436843, 'lat' => 55.164441, 'status' => 'active'],
            ['name' => 'Самара',            'country_code' => 'RU', 'lon' => 50.100202, 'lat' => 53.195878, 'status' => 'active'],
            ['name' => 'Омск',              'country_code' => 'RU', 'lon' => 73.368212, 'lat' => 54.989342, 'status' => 'active'],
            ['name' => 'Ростов-на-Дону',    'country_code' => 'RU', 'lon' => 39.701505, 'lat' => 47.235713, 'status' => 'active'],
            ['name' => 'Уфа',               'country_code' => 'RU', 'lon' => 55.972055, 'lat' => 54.738760, 'status' => 'active'],
            ['name' => 'Красноярск',        'country_code' => 'RU', 'lon' => 92.893248, 'lat' => 56.015283, 'status' => 'active'],
            ['name' => 'Пермь',             'country_code' => 'RU', 'lon' => 56.229443, 'lat' => 58.010456, 'status' => 'active'],
            ['name' => 'Воронеж',           'country_code' => 'RU', 'lon' => 39.200269, 'lat' => 51.660781, 'status' => 'active'],
            // пример отключенного города:
            // ['name' => 'Тестоград', 'country_code' => 'RU', 'lon' => 30.0, 'lat' => 60.0, 'status' => 'disabled'],
        ];

        foreach ($rows as $r) {
            DB::statement(
                "INSERT INTO cities (name, country_code, location, status, created_at, updated_at)
                 VALUES (?, ?, ST_SetSRID(ST_Point(?, ?), 4326), ?, NOW(), NOW())
                 ON CONFLICT (country_code, name_ci)
                 DO UPDATE SET
                   location   = EXCLUDED.location,
                   status     = EXCLUDED.status,
                   updated_at = NOW()",
                [$r['name'], $r['country_code'], $r['lon'], $r['lat'], $r['status']]
            );
        }
    }
}
