<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // LLM v11: возрастная целевая аудитория.
            //   kids / family / teens / adults / general.
            // Главная kudab-api скрывает 'kids' и 'family' по умолчанию.
            if (!Schema::hasColumn('events', 'audience')) {
                $table->string('audience', 16)->nullable();
            }

            // Опциональные явные возрастные диапазоны из текста ("для детей 5–10 лет").
            // НЕ выводятся из юридической маркировки 12+/16+/18+.
            if (!Schema::hasColumn('events', 'audience_age_min')) {
                $table->smallInteger('audience_age_min')->unsigned()->nullable();
            }
            if (!Schema::hasColumn('events', 'audience_age_max')) {
                $table->smallInteger('audience_age_max')->unsigned()->nullable();
            }

            // LLM v11: категория контента.
            //   entertainment / culture / education / sport / civic — на главной по умолчанию.
            //   official / patriotic_ceremony / religious / other — скрыты с главной.
            if (!Schema::hasColumn('events', 'content_kind')) {
                $table->string('content_kind', 24)->nullable();
            }
        });

        // Индексы под фильтрацию главной (audience NOT IN (...) AND content_kind IN (...)).
        Schema::table('events', function (Blueprint $table) {
            if (!$this->indexExists('events', 'events_audience_status_idx')) {
                $table->index(['audience', 'status'], 'events_audience_status_idx');
            }
            if (!$this->indexExists('events', 'events_content_kind_status_idx')) {
                $table->index(['content_kind', 'status'], 'events_content_kind_status_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if ($this->indexExists('events', 'events_audience_status_idx')) {
                $table->dropIndex('events_audience_status_idx');
            }
            if ($this->indexExists('events', 'events_content_kind_status_idx')) {
                $table->dropIndex('events_content_kind_status_idx');
            }

            foreach (['audience', 'audience_age_min', 'audience_age_max', 'content_kind'] as $col) {
                if (Schema::hasColumn('events', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        $row = \DB::selectOne(
            'SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?',
            [$table, $name]
        );
        return (bool) $row;
    }
};
