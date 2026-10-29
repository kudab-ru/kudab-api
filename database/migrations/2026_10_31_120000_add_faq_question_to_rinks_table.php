<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вопрос катка в «Коротко» страницы катков, ответ — его пояснение `status_note`. Так отвечают тем, кто ищет
 * каток, которого нет в списке: каток в Центральном парке не заливают, а его всё ещё ищут.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rinks', function (Blueprint $table) {
            $table->string('faq_q', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rinks', function (Blueprint $table) {
            $table->dropColumn('faq_q');
        });
    }
};
