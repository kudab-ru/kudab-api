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
        Schema::create('interests', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Название интереса');
            $table->unsignedBigInteger('parent_id')->nullable()->comment('FK на interests.id, для дерева интересов');
            $table->boolean('is_paid')->default(false)->comment('Платный интерес?');
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('interests')->onDelete('set null');
            $table->index('name');
            $table->index('parent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interests');
    }
};
