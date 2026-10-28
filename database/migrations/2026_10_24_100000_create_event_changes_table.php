<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Что случилось с уже записанным событием: отменили, отложили без даты, перенесли. Пишет парсер вместе
 * с `events.status`, читают страница события (куда перенесли) и ответ в канал под постом события.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            // то же значение, что легло в `events.status`
            $table->string('kind', 16);
            $table->unsignedBigInteger('new_event_id')->nullable();
            $table->unsignedBigInteger('context_post_id')->nullable();
            // ответ в канал отправлен или не нужен; null — ещё не смотрели
            $table->timestampTz('notified_at')->nullable();
            $table->timestampsTz();

            $table->index('event_id');
            $table->index('notified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_changes');
    }
};
