<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS telegram');

        Schema::table('telegram.users', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')
                ->nullable()
                ->after('language_code')
                ->comment('ID города из доменного справочника (без FK)');
            $table->index('city_id', 'tg_users_city_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('telegram.users', function (Blueprint $table) {
            $table->dropIndex('tg_users_city_id_idx');
            $table->dropColumn('city_id');
        });
    }
};
