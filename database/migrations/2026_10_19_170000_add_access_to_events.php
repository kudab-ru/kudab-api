<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Как попасть на событие (см. `EventAccess`): по нему сайт подписывает событие и фильтрует афишу.
 * Заполняет и обновляет `events:access:refresh`; пусто — ещё не посчитано.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('access', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('access');
        });
    }
};
