<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Даты праздников, которые каждый год назначают заново (Ночь музеев, День города, школьные каникулы):
 * владелец вписывает их в календаре админки. У повода в конфиге для этого `occasion` => 'floating'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occasion_dates', function (Blueprint $table) {
            $table->id();
            // slug повода из config/broadcast_digest.php
            $table->string('slug', 64);
            $table->date('starts_on');
            // пусто — праздник в один день, окно событий тогда задают window_before и window_after повода
            $table->date('ends_on')->nullable();
            $table->timestampsTz();

            $table->unique(['slug', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occasion_dates');
    }
};
