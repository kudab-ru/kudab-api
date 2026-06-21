<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Надёжность поллера, Часть 1 — claim-before-post.
 *
 * Поллер раньше работал poll → send → mark-sent без claim'а: между send и
 * mark-sent параллельный поллер или краш = дубль-пост в канал. Теперь poll
 * атомарно клеймит publish-айтем (time-lease), бот постит с токеном, mark-sent
 * проверяет токен. Зависший claim сам реклеймится по lease.
 *
 * Оба поля nullable + аддитивно — безопасно на горячей таблице.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->timestampTz('claimed_at')->nullable()->after('review_action')
                ->comment('Когда айтем заклеймлен поллером на публикацию (lease). NULL = свободен');
            $table->string('claim_token', 40)->nullable()->after('claimed_at')
                ->comment('UUID claim\'а: mark-sent помечает posted только при совпадении токена');
        });
    }

    public function down(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->dropColumn(['claimed_at', 'claim_token']);
        });
    }
};
