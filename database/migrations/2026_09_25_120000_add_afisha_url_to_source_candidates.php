<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Найденный раздел афиши домена-кандидата.
 *
 * ЗАЧЕМ. Вердикт «есть ли разметка» выносился по `sample_url` — случайной
 * ссылке из поста ВК. У btickets.ru это оказалась схема зала, у muzei.ru —
 * `muzei.ru/100/&utf=1`, у nastol.io — коллекция настолок. То есть в админке
 * писалось «без разметки» про сайт, а проверяли не сайт.
 *
 * Теперь ночной прогон ищет на домене сам раздел афиши и судит по нему.
 * Найденный адрес храним, чтобы кнопка «Разведать» подставляла путь целиком:
 * страница честно предупреждала «путь до страницы с афишей допиши сам», и это
 * была ровно та работа, которую можно не делать руками.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_candidates', function (Blueprint $table) {
            $table->string('afisha_url', 500)->nullable()->after('sample_url');
        });
    }

    public function down(): void
    {
        Schema::table('source_candidates', function (Blueprint $table) {
            $table->dropColumn('afisha_url');
        });
    }
};
