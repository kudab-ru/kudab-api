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
        DB::statement('CREATE SCHEMA IF NOT EXISTS telegram');

        Schema::create('telegram.users', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('PK');

            $table->unsignedBigInteger('user_id')
                ->nullable()
                ->comment('ID из таблицы users; null если не привязан');

            $table->unsignedBigInteger('telegram_id')
                ->unique()
                ->comment('Уникальный идентификатор пользователя Telegram (from.id)');

            $table->string('telegram_username')
                ->nullable()
                ->comment('@username (может отсутствовать/меняться)');

            $table->string('first_name')
                ->nullable()
                ->comment('Имя в Telegram');

            $table->string('last_name')
                ->nullable()
                ->comment('Фамилия в Telegram');

            $table->string('language_code', 8)
                ->nullable()
                ->comment('Код языка, например "ru", "en", "en-US"');

            $table->unsignedBigInteger('chat_id')
                ->nullable()
                ->comment('ID приватного чата с пользователем (chat.id)');

            $table->boolean('is_bot')
                ->default(false)
                ->comment('Флаг: является ли аккаунт ботом');

            $table->timestamp('registered_at')
                ->nullable()
                ->comment('Когда впервые зафиксирован в системе');

            $table->timestamp('last_active')
                ->nullable()
                ->comment('Последняя активность пользователя');

            $table->timestamps(); // created_at / updated_at

            // Связи и индексы
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['telegram_id']);
            $table->index(['user_id']);

            // Комментарий к таблице
            $table->comment('Пользователи Telegram');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram.users');
    }
};
