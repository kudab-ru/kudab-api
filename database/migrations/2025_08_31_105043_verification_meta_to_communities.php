<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            // PostgreSQL JSONB для хранения результата верификации/классификации
            $table->jsonb('verification_meta')->nullable()->after('is_verified');
        });

        // Опционально: GIN-индекс, если планируете фильтровать/искать по JSON
        try {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_communities_verification_meta_gin ON communities USING GIN (verification_meta);');
        } catch (\Throwable $e) {
            // пропускаем для окружений без прав
        }
    }

    public function down(): void
    {
        // Удалим индекс и колонку
        try {
            DB::statement('DROP INDEX IF EXISTS idx_communities_verification_meta_gin;');
        } catch (\Throwable $e) {}

        Schema::table('communities', function (Blueprint $table) {
            $table->dropColumn('verification_meta');
        });
    }
};
