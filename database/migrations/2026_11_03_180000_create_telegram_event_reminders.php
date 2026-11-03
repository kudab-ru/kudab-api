<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram.event_reminders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('telegram_id');
            $table->unsignedBigInteger('chat_id');
            $table->unsignedBigInteger('event_id');
            // `eve` — накануне начала, `last` — накануне последнего дня идущей выставки
            $table->string('kind', 8);
            $table->timestampTz('remind_at');
            // начало события, от которого считали `remind_at`: дату сдвинули — время считаем заново
            $table->timestampTz('event_start')->nullable();
            $table->timestampTz('taken_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestampsTz();

            $table->unique(['telegram_id', 'event_id']);
            $table->index('remind_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram.event_reminders');
    }
};
