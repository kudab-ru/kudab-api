<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('events', 'short_description')) {
            return;
        }

        // Короткое описание для карточки в ленте — производное от description
        // (парсер считает EventShortDescription). Nullable: заполняется парсером
        // на upsert + бэкфилл-командой, до этого фронт берёт усечённый description.
        Schema::table('events', function (Blueprint $table) {
            $table->text('short_description')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('short_description');
        });
    }
};
