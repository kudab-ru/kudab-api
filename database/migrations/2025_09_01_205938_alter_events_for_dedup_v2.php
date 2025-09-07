<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // для FIAS-ключа нужно больше длины
            $table->string('dedup_key', 66)->nullable()->change();
            // приоритетный идентификатор здания из DaData
            $table->string('house_fias_id', 36)->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('dedup_key', 34)->nullable()->change();
            $table->dropColumn('house_fias_id');
        });
    }
};
