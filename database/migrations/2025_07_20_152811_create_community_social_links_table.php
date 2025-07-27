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
        Schema::create('community_social_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('community_id')->comment('FK на communities.id');
            $table->unsignedBigInteger('social_network_id')->comment('FK на social_networks.id');
            $table->string('external_community_id', 128)->nullable()->comment('ID/slug/username сообщества в соцсети или null для сайтов');
            $table->string('url', 512)->comment('Ссылка на профиль сообщества в соцсети или на сайте');
            $table->timestamps();

            $table->foreign('community_id')->references('id')->on('communities')->onDelete('cascade');
            $table->foreign('social_network_id')->references('id')->on('social_networks')->onDelete('cascade');
            $table->unique(['community_id', 'social_network_id'], 'community_network_unique');
            $table->index(['community_id', 'social_network_id']);
            $table->index('external_community_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('community_social_links');
    }
};
