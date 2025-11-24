<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Очередь публикаций в телеграм-чаты.
 *
 * Каждый ряд — одна попытка/план публикации конкретного события
 * в конкретный чат (через TelegramChatBroadcast).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->bigIncrements('id')
                ->comment('Primary key');

            $table->unsignedBigInteger('broadcast_id')
                ->comment('ID настроек рассылки (telegram.chat_broadcasts.id) для конкретного чата');

            $table->unsignedBigInteger('event_id')
                ->comment('ID события (events.id), которое планируем/опубликовали');

            $table->string('status', 32)
                ->default('pending')
                ->comment('Статус элемента очереди: pending/planned/posted/skipped/error');

            $table->timestampTz('planned_at')
                ->nullable()
                ->comment('Когда планируется публикация поста в канал');

            $table->timestampTz('posted_at')
                ->nullable()
                ->comment('Фактическое время отправки поста в канал');

            $table->text('error_message')
                ->nullable()
                ->comment('Последняя ошибка, если отправка не удалась');

            $table->timestampsTz();

            // FK на настройки рассылки
            $table->foreign('broadcast_id', 'chat_broadcast_items_broadcast_fk')
                ->references('id')
                ->on('telegram.chat_broadcasts')
                ->onDelete('cascade');

            // FK на событие
            $table->foreign('event_id', 'chat_broadcast_items_event_fk')
                ->references('id')
                ->on('events')
                ->onDelete('cascade');

            // Один и тот же event не должен висеть в очереди дважды для одного чата
            $table->unique(
                ['broadcast_id', 'event_id'],
                'chat_broadcast_items_broadcast_event_uq'
            );

            // Быстрый выбор "что пора отправить"
            $table->index(
                ['status', 'planned_at'],
                'chat_broadcast_items_status_planned_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram.chat_broadcast_items');
    }
};
