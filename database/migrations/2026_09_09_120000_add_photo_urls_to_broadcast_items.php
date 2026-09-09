<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ручной выбор картинок для поста.
 *
 * До сих пор состав альбома был неуправляем: eventPhotos брал первые три
 * картинки события и отсеивал только точные повторы URL. Один и тот же кадр,
 * приехавший с парсинга под разными адресами, уходил в канал дважды, и
 * поправить это можно было лишь через сами данные события.
 *
 * NULL означает «как раньше»: собрать автоматически. Пустой массив — это
 * осознанный выбор «без картинок», и он не то же самое, что NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->json('photo_urls')->nullable()->after('photo_url');
        });
    }

    public function down(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->dropColumn('photo_urls');
        });
    }
};
