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
        Schema::create('interest_relations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_interest_id')->comment('FK на interests.id (родитель)');
            $table->unsignedBigInteger('child_interest_id')->comment('FK на interests.id (дочерний)');
            $table->timestamps();

            $table->foreign('parent_interest_id')->references('id')->on('interests')->onDelete('cascade');
            $table->foreign('child_interest_id')->references('id')->on('interests')->onDelete('cascade');
            $table->index(['parent_interest_id', 'child_interest_id']);
            $table->unique(['parent_interest_id', 'child_interest_id'], 'unique_interest_relation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interest_relations');
    }
};
