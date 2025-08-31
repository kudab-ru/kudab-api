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
        Schema::create('community_interest', function (Blueprint $table) {
            $table->unsignedBigInteger('community_id')->comment('FK на communities.id');
            $table->unsignedBigInteger('interest_id')->comment('FK на interests.id');
            $table->timestamps();

            $table->primary(['community_id', 'interest_id']);
            $table->foreign('community_id')->references('id')->on('communities')->onDelete('cascade');
            $table->foreign('interest_id')->references('id')->on('interests')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('community_interest');
    }
};
