<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1) колонка
        if (!Schema::hasColumn('events', 'city_id')) {
            Schema::table('events', function (Blueprint $table) {
                $table->foreignId('city_id')->nullable()->after('id');
            });
        }

        // 2) FK (фиксированное имя)
        Schema::table('events', function (Blueprint $table) {
            // если FK уже есть — Postgres упадёт, поэтому обернём
            try {
                $table->foreign('city_id', 'events_city_id_fk')
                    ->references('id')->on('cities')
                    ->nullOnDelete();
            } catch (\Throwable $e) {}
        });

        // 3) составной индекс (фиксированное имя)
        Schema::table('events', function (Blueprint $table) {
            try {
                $table->index(['city_id', 'start_date'], 'events_city_id_start_date_idx');
            } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('events', 'city_id')) {
            return;
        }

        // индекс
        try {
            Schema::table('events', function (Blueprint $table) {
                $table->dropIndex('events_city_id_start_date_idx');
            });
        } catch (\Throwable $e) {}

        // FK
        try {
            Schema::table('events', function (Blueprint $table) {
                $table->dropForeign('events_city_id_fk');
            });
        } catch (\Throwable $e) {}

        // колонка
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('city_id');
        });
    }
};
