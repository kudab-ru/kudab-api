<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Выходные дни площадки: список дней недели 1–7, по которым она закрыта; пусто — не знаем.
 * Выставка заведена днём на каждую дату, а музей в понедельник закрыт — звать туда «сегодня» нельзя.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->jsonb('closed_weekdays')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn('closed_weekdays');
        });
    }
};
