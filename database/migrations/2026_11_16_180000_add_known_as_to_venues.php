<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Как площадку называют люди («Драмтеатр Кольцова», «ВКЗ»), если не так, как по документам.
 * Идёт в заголовок страницы площадки и в поиск; карточки и посты берут официальное имя.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->string('known_as', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn('known_as');
        });
    }
};
