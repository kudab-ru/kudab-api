<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_sources', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('social_link_id');
            $table->unsignedBigInteger('context_post_id')->nullable();

            // откуда
            $table->text('source');
            $table->text('post_external_id');
            $table->text('external_url')->nullable();
            $table->timestampTz('published_at')->nullable();

            // медиа/мета
            $table->json('images')->nullable();
            $table->json('meta')->nullable();

            // пригодится для прямого перехода
            $table->text('generated_link')->nullable();

            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('social_link_id')->references('id')->on('community_social_links')->onDelete('cascade');
            $table->foreign('context_post_id')->references('id')->on('context_posts')->onDelete('set null');

            // один пост соцсети должен соответствовать ровно одному событию
            $table->unique(['source', 'post_external_id']);
            // одна и та же связка не должна дублироваться
            $table->unique(['event_id', 'source', 'post_external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sources');
    }
};
