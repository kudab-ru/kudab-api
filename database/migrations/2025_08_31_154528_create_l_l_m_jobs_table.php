<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void {
        Schema::create('llm_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('type', 32)->default('chat');           // 'chat' | 'embedding' ...
            $table->string('status', 24)->default('pending');      // pending|processing|completed|failed
            $table->foreignId('context_post_id')->nullable()
                ->constrained('context_posts')->nullOnDelete();

            $table->jsonb('input')->nullable();    // payload для модели
            $table->jsonb('options')->nullable();  // model, temperature и т.п.
            $table->jsonb('result')->nullable();   // ответ модели

            $table->integer('error_code')->nullable();
            $table->string('error_message', 1024)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['context_post_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('llm_jobs');
    }
};
