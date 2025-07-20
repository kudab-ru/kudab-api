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
        Schema::create('event_interest', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->comment('FK на events.id');
            $table->unsignedBigInteger('interest_id')->comment('FK на interests.id');
            $table->timestamps();

            $table->primary(['event_id', 'interest_id']);
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('interest_id')->references('id')->on('interests')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_interest');
    }
};
