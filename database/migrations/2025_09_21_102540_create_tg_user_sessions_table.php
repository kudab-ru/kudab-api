<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('tg_user_sessions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');           // telegram_users.id
            $t->unsignedBigInteger('selected_chat_id')->nullable(); // telegram_chats.id
            $t->string('selected_label')->nullable();    // денорм для UX
            $t->timestampTz('expires_at')->nullable();   // TTL (напр. now()+interval '14 days')
            $t->timestamps();

            $t->unique(['user_id']); // одна сессия на пользователя
            $t->foreign('user_id')->references('id')->on('telegram_users')->cascadeOnDelete();
            $t->foreign('selected_chat_id')->references('id')->on('telegram_chats')->nullOnDelete();
            $t->index('expires_at');
        });
    }
    public function down(): void {
        Schema::dropIfExists('tg_user_sessions');
    }
};
