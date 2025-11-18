<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // На всякий случай, как в миграции telegram.users
        DB::statement('CREATE SCHEMA IF NOT EXISTS telegram');

        Schema::create('telegram.chats', function (Blueprint $table) {
            $table->bigIncrements('id')
                ->comment('PK');

            $table->unsignedBigInteger('telegram_user_id')
                ->nullable()
                ->comment('FK на telegram.users.id — владелец/инициатор привязки');

            $table->unsignedBigInteger('telegram_chat_id')
                ->comment('ID чата/канала в Telegram (chat.id)');

            $table->string('chat_type', 32)
                ->comment('Тип: private, group, supergroup, channel');

            $table->string('title')
                ->nullable()
                ->comment('Название чата/канала');

            $table->string('username')
                ->nullable()
                ->comment('@username, если есть');

            $table->boolean('is_active')
                ->default(true)
                ->comment('Привязка активна');

            $table->timestamp('linked_at')
                ->nullable()
                ->comment('Когда привязали');

            $table->timestamp('unlinked_at')
                ->nullable()
                ->comment('Когда отвязали (если есть)');

            $table->timestamps();

            // Связь с telegram.users
            $table->foreign('telegram_user_id')
                ->references('id')
                ->on('telegram.users')
                ->nullOnDelete();

            // Один chat.id — одна запись (один владелец на чат)
            $table->unique('telegram_chat_id', 'telegram_chats_chat_id_unique');

            // Быстрый поиск по владельцу
            $table->index('telegram_user_id', 'telegram_chats_user_idx');

            // Фильтрация по активности/типу
            $table->index(['is_active', 'chat_type'], 'telegram_chats_active_type_idx');

            $table->comment('Телеграм-чаты (группы/каналы), привязанные к пользователям');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram.chats');
    }
};
