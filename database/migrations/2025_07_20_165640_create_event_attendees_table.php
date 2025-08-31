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
        Schema::create('event_attendees', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->comment('FK на events.id');
            $table->unsignedBigInteger('user_id')->comment('FK на users.id');
            $table->string('status')->default('going')->comment('Статус участия: going, interested, rejected и др.');
            $table->timestamp('joined_at')->nullable()->comment('Время присоединения');
            $table->timestamps();

            $table->primary(['event_id', 'user_id']);
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_attendees');
    }
};
