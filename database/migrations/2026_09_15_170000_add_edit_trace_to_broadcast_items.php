<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * След ручной правки поста: что именно правили и когда.
 *
 * ЗАЧЕМ. Сегодня «правлено человеком» выражено ровно одним полем —
 * `caption_source = manual`, и то только про текст. Перенос поста на другой
 * день, замена картинок и возврат из снятых не оставляют следа вовсе: лента
 * выглядит одинаково независимо от того, собрал её сервис или пересобрал
 * человек. А отличать это нужно: пересборка недели и автонаполнение обязаны
 * обходить то, что трогали руками.
 *
 * `edited_fields` — список изменённых полей (caption | photos | time), а не
 * один флаг: «правил текст» и «двигал день» требуют разного обращения, и
 * человеку в ленте полезно видеть, что именно правили.
 *
 * Оба поля nullable и аддитивны — безопасно на горячей таблице.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->timestampTz('edited_at')->nullable()->after('text_hint')
                ->comment('Когда пост последний раз правили руками из админки');
            $table->json('edited_fields')->nullable()->after('edited_at')
                ->comment('Что именно правили: caption | photos | time');
        });
    }

    public function down(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->dropColumn(['edited_at', 'edited_fields']);
        });
    }
};
