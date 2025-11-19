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
        Schema::create('telegram.chat_broadcasts', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('chat_id')
                ->comment('FK на telegram.chats.id (привязанный телеграм-чат/канал)');

            $table->boolean('enabled')
                ->default(false)
                ->comment('Признак: включена ли рассылка для этого чата');

            $table->json('settings')
                ->nullable()
                ->comment('Произвольные настройки рассылки в JSON (period, template_code и др.)');

            $table->timestamp('last_run_at')
                ->nullable()
                ->comment('Время последней фактической отправки в канал');

            $table->timestamp('last_preview_at')
                ->nullable()
                ->comment('Время последнего предпросмотра (например, в личку админа)');

            $table->timestamps();

            $table->foreign('chat_id')
                ->references('id')
                ->on('telegram.chats')
                ->onDelete('cascade');

            // Один набор настроек на один чат
            $table->unique('chat_id', 'chat_broadcasts_chat_id_uq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram.chat_broadcasts');
    }
};
