<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кто открывал карточку события на сайте: один посетитель — одна строка в сутки. Пишет `EventViews`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            // хэш случайной метки браузера, см. `EventViews::visitor`
            $table->string('visitor', 16);
            // московские сутки: «за неделю» считаем календарными днями
            $table->date('day');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['event_id', 'visitor', 'day']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_views');
    }
};
