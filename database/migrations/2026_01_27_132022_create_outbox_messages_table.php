<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();

            // кто положил сообщение (удобно для дебага)
            $table->string('producer', 32)->default('kudab-api');

            // тип события: community.ingest / community.verify / ...
            $table->string('topic', 100);

            // ключ идемпотентности (например нормализованный url или sha256)
            $table->string('dedup_key', 190)->nullable();

            // данные события (минимум: { "url": "..." })
            $table->json('payload');

            // queued|processing|done|failed|canceled
            $table->string('status', 32)->default('queued');

            // ретраи — в стиле llm_jobs (attempt/retry_at/started_at/finished_at/error_*)
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(10);
            $table->timestampTz('retry_at')->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            // блокировка воркером (чтобы не схватили сразу два процесса)
            $table->timestampTz('locked_at')->nullable();
            $table->string('locked_by', 64)->nullable();

            $table->unsignedInteger('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->json('meta')->nullable();

            $table->timestampsTz();

            // быстрый выбор “что брать в работу”
            $table->index(['status', 'retry_at', 'id'], 'outbox_status_retry_idx');
            $table->index(['topic', 'status', 'retry_at', 'id'], 'outbox_topic_status_retry_idx');
        });

        DB::statement("
            CREATE UNIQUE INDEX outbox_topic_dedup_uniq
            ON outbox_messages (topic, dedup_key)
            WHERE dedup_key IS NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS outbox_topic_dedup_uniq');
        Schema::dropIfExists('outbox_messages');
    }
};
