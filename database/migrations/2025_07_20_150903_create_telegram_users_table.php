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
        Schema::create('telegram_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->comment('FK на users.id, опционально');
            $table->unsignedBigInteger('telegram_id')->unique()->comment('Telegram user ID (уникальный)');
            $table->string('telegram_username')->nullable()->comment('username в Telegram');
            $table->string('first_name')->nullable()->comment('Имя пользователя в Telegram');
            $table->string('last_name')->nullable()->comment('Фамилия пользователя в Telegram');
            $table->string('language_code', 8)->nullable()->comment('Язык пользователя');
            $table->unsignedBigInteger('chat_id')->nullable()->comment('Активный chat_id Telegram');
            $table->boolean('is_bot')->default(false)->comment('Это бот-пользователь');
            $table->timestamp('registered_at')->nullable()->comment('Первое посещение');
            $table->timestamp('last_active')->nullable()->comment('Последняя активность');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->index('telegram_username');
            $table->index('last_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_users');
    }
};
