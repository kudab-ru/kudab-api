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
        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Название сообщества');
            $table->text('description')->nullable()->comment('Описание сообщества');
            $table->string('city')->nullable()->comment('Город (опционально)');
            $table->string('street')->nullable()->comment('Улица');
            $table->string('house')->nullable()->comment('Дом');
            $table->string('avatar_url')->nullable()->comment('Ссылка на аватар');
            $table->string('image_url')->nullable()->comment('Доп. изображение или постер');
            $table->timestamp('last_checked_at')->nullable()->comment('Время последней проверки (парсинг/валидность)');
            $table->string('verification_status')->nullable()->default('pending')->comment('Статус проверки/верификации (pending/approved/rejected)');
            $table->boolean('is_verified')->default(false)->comment('Признак верификации');
            $table->timestamps();

            $table->index('city');
            $table->index('verification_status');
            $table->index('is_verified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communities');
    }
};
