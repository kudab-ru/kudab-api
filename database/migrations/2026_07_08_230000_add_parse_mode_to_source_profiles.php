<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PR4 само-строящегося парсера: режим извлечения профиля.
// 'jsonld' — detail несёт Event JSON-LD, bypass без LLM (qtickets-путь);
// 'llm_text' — сайт без разметки: detail → чистый текст → стандартный
// LLM-extract (v15). Сбор llm_text-профилей инкрементальный (движок скипает
// уже собранные страницы — повторный LLM-вызов стоит денег).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_profiles', function (Blueprint $table) {
            $table->string('parse_mode', 20)->default('jsonld');
        });
    }

    public function down(): void
    {
        Schema::table('source_profiles', function (Blueprint $table) {
            $table->dropColumn('parse_mode');
        });
    }
};
