<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_jobs', function (Blueprint $table) {
            // Когда результат llm_jobs был успешно обработан ConsumeLlmEventsJob
            $table->timestamp('consumed_at')
                ->nullable()
                ->comment('Время успешного консьюма: llm_jobs -> ConsumeLlmEventsJob');

            // Индекс под выборку: task=events_extract AND status=completed AND consumed_at IS NULL ORDER BY id LIMIT N
            $table->index(
                ['task', 'status', 'consumed_at', 'id'],
                'llm_jobs_task_status_consumed_id_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('llm_jobs', function (Blueprint $table) {
            $table->dropIndex('llm_jobs_task_status_consumed_id_idx');
            $table->dropColumn('consumed_at');
        });
    }
};
