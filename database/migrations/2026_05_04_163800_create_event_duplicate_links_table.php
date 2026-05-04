<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Журнал решений cross-community semantic dedup (задача 2.1, шаг 2).
 *
 * Каждая строка — это решение «events {drop_event_id} и {keep_event_id}
 * считаем дублями». Источник решения — local scorer (verdict='auto'),
 * LLM verifier (verdict='llm_yes'/'llm_no') или ручная разметка
 * (verdict='manual_yes'/'manual_no'/'rejected').
 *
 * Таблица служит и журналом (что/когда/почему смерджили), и точкой
 * отката: `reverted_at` маркирует, что merge нужно откатить руками.
 *
 * Не путать с `event_groups`/`event_sources` — там продуктовые сущности.
 * Это сугубо служебная таблица процесса dedup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_duplicate_links', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Пара events. keep — оставляем, drop — soft-delete после merge.
            $table->unsignedBigInteger('keep_event_id');
            $table->unsignedBigInteger('drop_event_id');

            // Композитный score из EventDuplicateCandidateScorer + breakdown.
            $table->float('score_total');
            $table->jsonb('score_breakdown')->nullable();

            // Откуда взялось решение.
            // auto         — local scorer выше auto-merge threshold
            // llm_yes/no   — gpt-4o-mini verifier ответил yes/no
            // manual_yes/no — ручная разметка через админку (будущее)
            // rejected     — local scorer / LLM решили, что НЕ дубль
            $table->string('verdict', 20);

            // Опциональный LLM-результат (если был вызван verifier).
            $table->float('llm_confidence')->nullable();
            $table->string('llm_reason', 120)->nullable();

            // Состояние merge-а:
            //   NULL              — решение зафиксировано, merge ещё не выполнен
            //   merged_at IS NOT NULL — merge выполнен
            //   reverted_at IS NOT NULL — был выполнен и откачен
            $table->timestampTz('merged_at')->nullable();
            $table->timestampTz('reverted_at')->nullable();

            $table->timestamps();

            $table->foreign('keep_event_id')
                ->references('id')->on('events')
                ->onDelete('cascade');
            $table->foreign('drop_event_id')
                ->references('id')->on('events')
                ->onDelete('cascade');

            $table->index('keep_event_id', 'edl_keep_idx');
            $table->index('drop_event_id', 'edl_drop_idx');
            $table->index('verdict', 'edl_verdict_idx');
            $table->index('merged_at', 'edl_merged_at_idx');
        });

        // Уникальная пара (keep, drop). Защита от двойной записи решения.
        // Симметричный матч (drop, keep) — отдельный кейс, специально не
        // защищаем: scan-команда всегда выдаёт (a < b), keeper подбирается
        // detеrмиnированно, перестановка маловероятна.
        DB::statement('CREATE UNIQUE INDEX event_duplicate_links_pair_uniq
                       ON event_duplicate_links (keep_event_id, drop_event_id)');

        // keep != drop sanity check на уровне БД.
        DB::statement('ALTER TABLE event_duplicate_links
                       ADD CONSTRAINT event_duplicate_links_distinct_chk
                       CHECK (keep_event_id <> drop_event_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('event_duplicate_links');
    }
};
