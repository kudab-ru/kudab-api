<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $t) {
            $t->unsignedBigInteger('city_id')->nullable()->after('city');
            $t->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
            $t->index('city_id', 'communities_city_id_idx');
        });

        Schema::table('events', function (Blueprint $t) {
            $t->unsignedBigInteger('city_id')->nullable()->after('city');
            $t->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
            $t->index('city_id', 'events_city_id_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $t) {
            $t->dropForeign(['city_id']);
            $t->dropColumn('city_id');
        });
        Schema::table('communities', function (Blueprint $t) {
            $t->dropForeign(['city_id']);
            $t->dropColumn('city_id');
        });
    }
};
