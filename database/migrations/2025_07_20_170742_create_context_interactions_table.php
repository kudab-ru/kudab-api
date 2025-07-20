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
        Schema::create('context_interactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id')->comment('FK на context_posts.id');
            $table->unsignedBigInteger('user_id')->comment('FK на users.id');
            $table->string('type')->comment('Тип действия: request, response, flag, comment и др.');
            $table->string('status')->nullable()->comment('Статус действия: active, reviewed, flagged и др.');
            $table->text('message')->nullable()->comment('Сообщение, комментарий, ответ');
            $table->string('reason')->nullable()->comment('Причина/категория, если применимо');
            $table->timestamps();

            $table->foreign('post_id')->references('id')->on('context_posts')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('type');
            $table->index('status');
            $table->index(['post_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('context_interactions');
    }
};
