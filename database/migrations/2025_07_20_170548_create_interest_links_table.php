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
        Schema::create('interest_links', function (Blueprint $table) {
            $table->string('parent_type')->comment('Тип объекта: context_post, event и др.');
            $table->unsignedBigInteger('parent_id')->comment('ID объекта');
            $table->unsignedBigInteger('interest_id')->comment('FK на interests.id');
            $table->timestamps();

            $table->primary(['parent_type', 'parent_id', 'interest_id']);
            $table->foreign('interest_id')->references('id')->on('interests')->onDelete('cascade');
            $table->index(['parent_type', 'parent_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interest_links');
    }
};
