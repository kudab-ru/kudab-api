<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('context_posts', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->nullable()->comment('ID исходного поста VK/TG/сайт');
            $table->string('source')->nullable()->comment('vk, tg, site и др.');
            $table->unsignedBigInteger('author_id')->nullable()->comment('ID автора (user/community/external)');
            $table->string('author_type')->nullable()->comment('user, community, external');
            $table->unsignedBigInteger('community_id')->nullable()->comment('FK на communities.id');
            $table->string('title')->nullable();
            $table->text('text')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('status')->default('active')->comment('active, flagged, hidden и др.');
            $table->timestamps();

            $table->foreign('community_id')->references('id')->on('communities')->onDelete('set null');
            // author_id и author_type — morph, не ставим FK
            $table->index('external_id');
            $table->index('source');
            $table->index('published_at');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('context_posts');
    }
};
