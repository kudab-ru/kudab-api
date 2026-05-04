<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('events', 'title_norm')) {
            Schema::table('events', function (Blueprint $table) {
                // EventGroupKey::titleNorm(): ё→е, унификация кавычек, удаление age-marks/hashtags/punct.
                // Используется в dedup_key и group_key — совпадение нормализации в обеих сторонах
                // закрывает кейсы вида "Концерт" vs "Концерт 12+" (одна группа, но разные dedup_key).
                $table->string('title_norm', 255)->nullable()->after('title');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('events', 'title_norm')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropColumn('title_norm');
            });
        }
    }
};
