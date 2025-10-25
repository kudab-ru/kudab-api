<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesSeeder extends Seeder
{
    public function run(): void {
        foreach (['user','moderator','admin','superadmin'] as $r) {
            Role::firstOrCreate(['name'=>$r,'guard_name'=>'web']);
        }
    }
}
