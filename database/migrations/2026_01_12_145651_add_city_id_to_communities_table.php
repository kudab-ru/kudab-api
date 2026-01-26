<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('communities', 'city_id')) {
            Schema::table('communities', function (Blueprint $table) {
                $table->foreignId('city_id')->nullable()->after('id');
            });
        }

        Schema::table('communities', function (Blueprint $table) {
            $table->foreign('city_id', 'communities_city_id_fk')
                ->references('id')->on('cities')
                ->nullOnDelete();
        });

        try {
            Schema::table('communities', function (Blueprint $table) {
                $table->index('city_id', 'communities_city_id_idx');
            });
        } catch (\Throwable $e) {
            // индекс уже есть — ок
        }
    }

    public function down(): void
    {
        // drop FK/idx только если колонка есть
        if (Schema::hasColumn('communities', 'city_id')) {
            try {
                Schema::table('communities', function (Blueprint $table) {
                    $table->dropForeign('communities_city_id_fk');
                });
            } catch (\Throwable $e) {}

            try {
                Schema::table('communities', function (Blueprint $table) {
                    $table->dropIndex('communities_city_id_idx');
                });
            } catch (\Throwable $e) {}

            Schema::table('communities', function (Blueprint $table) {
                $table->dropColumn('city_id');
            });
        }
    }
};
