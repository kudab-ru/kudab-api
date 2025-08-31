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
        Schema::create('interest_user', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->comment('FK на users.id');
            $table->unsignedBigInteger('interest_id')->comment('FK на interests.id');
            $table->timestamps();

            $table->primary(['user_id', 'interest_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('interest_id')->references('id')->on('interests')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interest_user');
    }
};
