<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Отпечатки картинок событий. Фото, заново загруженное в повторный пост, приходит с новым адресом,
// и узнать его можно только по содержимому. Считает парсер (images:fingerprint), читает выдача событий.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_prints', function (Blueprint $table) {
            // md5 от адреса картинки без изменений, как он записан в `event_sources.images`
            $table->char('url_md5', 32)->primary();
            // серая копия 16×16 точек в base64; null — картинку не скачали или не прочитали, см. fails
            $table->text('gray')->nullable();
            $table->unsignedSmallInteger('fails')->default(0);
            $table->timestamp('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_prints');
    }
};
