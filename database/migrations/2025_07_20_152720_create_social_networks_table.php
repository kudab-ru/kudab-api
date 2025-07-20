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
        Schema::create('social_networks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Название соцсети (VK, Telegram, Instagram, ...)');
            $table->string('slug')->unique()->comment('Слаг: vk, telegram, instagram и др.');
            $table->string('icon')->nullable()->comment('Иконка/emoji или URL');
            $table->string('url_mask')->nullable()->comment('Шаблон для генерации ссылок');
            $table->timestamps();

            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_networks');
    }
};
