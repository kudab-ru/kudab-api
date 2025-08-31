<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_link_verifications', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('community_id');
            $table->unsignedBigInteger('community_social_link_id');
            $table->unsignedBigInteger('social_network_id');

            $table->timestamp('checked_at')->useCurrent();
            $table->string('status', 16)->default('ok'); // ok|error|skipped
            $table->integer('latency_ms')->nullable();

            $table->string('model', 64)->nullable();
            $table->unsignedInteger('prompt_version')->default(1);

            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            // Основные показатели
            $table->boolean('is_active')->nullable();
            $table->boolean('has_events_posts')->nullable();
            $table->decimal('activity_score', 3, 2)->nullable();
            $table->decimal('events_score', 3, 2)->nullable();

            // Классификация/HQ
            $table->string('kind', 16)->nullable(); // aggregator|venue_host|org
            $table->boolean('has_fixed_place')->nullable();
            $table->string('hq_city')->nullable();
            $table->string('hq_street')->nullable();
            $table->string('hq_house')->nullable();
            $table->decimal('hq_confidence', 3, 2)->nullable();

            // Примеры/площадки и полный ответ
            $table->jsonb('examples')->nullable();
            $table->jsonb('events_locations')->nullable();
            $table->jsonb('raw')->nullable();

            $table->timestamps();

            $table->foreign('community_id')->references('id')->on('communities')->onDelete('cascade');
            $table->foreign('community_social_link_id')->references('id')->on('community_social_links')->onDelete('cascade');
            $table->foreign('social_network_id')->references('id')->on('social_networks')->onDelete('cascade');

            $table->index(['community_social_link_id', 'checked_at'], 'idx_slv_link_time');
            $table->index(['community_id', 'checked_at'], 'idx_slv_comm_time');
        });

        // Опционально: GIN индекс по raw
        try {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_slv_raw_gin ON social_link_verifications USING GIN (raw);');
        } catch (\Throwable $e) {
            // no-op
        }
    }

    public function down(): void
    {
        try {
            DB::statement('DROP INDEX IF EXISTS idx_slv_raw_gin;');
        } catch (\Throwable $e) {}

        Schema::dropIfExists('social_link_verifications');
    }
};
