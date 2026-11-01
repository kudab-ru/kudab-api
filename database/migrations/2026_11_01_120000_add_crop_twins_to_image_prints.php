<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Одно фото в разной обрезке: парсер (images:fingerprint) сравнивает пары картинок события по копии
// с пропорциями кадра и пишет ответ, выдача событий показывает одну копию.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_prints', function (Blueprint $table) {
            // серая копия с пропорциями в base64: байт ширины, байт высоты, точки по строкам
            $table->text('shape')->nullable();
        });

        Schema::create('image_twins', function (Blueprint $table) {
            // md5 адресов двух картинок одного события, a < b
            $table->char('url_md5_a', 32);
            $table->char('url_md5_b', 32);
            // false тоже пишется: пара сравнена, второй раз её не считаем
            $table->boolean('same');
            $table->timestamp('checked_at');
            $table->primary(['url_md5_a', 'url_md5_b']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_twins');
        Schema::table('image_prints', function (Blueprint $table) {
            $table->dropColumn('shape');
        });
    }
};
