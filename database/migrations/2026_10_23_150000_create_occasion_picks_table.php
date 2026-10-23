<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Решения человека по программе праздника на сайте: событие добавлено вопреки правилам повода или убрано.
 * Хранятся только расхождения с правилами, читает `OccasionPage::eventsQuery`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occasion_picks', function (Blueprint $table) {
            $table->id();
            // слаг повода из config/broadcast_digest.php
            $table->string('slug', 64);
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            // true — добавлено руками, false — убрано
            $table->boolean('included');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestampsTz();

            $table->unique(['slug', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occasion_picks');
    }
};
