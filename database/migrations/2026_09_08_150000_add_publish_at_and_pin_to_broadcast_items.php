<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Настоящая дата публикации и закрепление поста.
 *
 * ПОЧЕМУ ОТДЕЛЬНОЕ ПОЛЕ, А НЕ planned_at. planned_at — это НЕ «когда
 * опубликовать», а техническая придержка на несколько минут: запись ставится
 * в очередь, а парсер в это время дописывает ТГ-текст события (см.
 * textGraceMinutes и комментарий на месте постановки). Значение живёт минуты
 * и снимается само. Если повесить на него ещё и план недели, две разные вещи
 * начнут спорить: перенос поста на четверг выглядел бы как «придержать до
 * четверга», и запись всё это время считалась бы не готовой к выдаче.
 *
 * publish_at — то, что видит человек в ленте недели и что он двигает,
 * перенося пост. NULL означает «по расписанию канала», как было раньше.
 *
 * is_pinned — «закрепить»: не заменять при пересборке недели. Именно не
 * заменять, а НЕ «опубликовать повторно»: повтор упирается в
 * UNIQUE(broadcast_id, event_id) и потребовал бы пересобрать все три
 * анти-дубля, это отдельная работа.
 *
 * Оба поля nullable/со значением по умолчанию и аддитивны — безопасно на
 * горячей таблице. Существующие записи не трогаем: у них publish_at = NULL,
 * то есть прежнее поведение «по расписанию».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->timestampTz('publish_at')->nullable()->after('planned_at')
                ->comment('Когда опубликовать. NULL = по расписанию канала. Не путать с planned_at — та техническая придержка на время генерации текста');
            $table->boolean('is_pinned')->default(false)->after('caption_source')
                ->comment('Закреплён: не заменять при пересборке ленты');

            // Лента недели читается «что публиковать дальше в этом канале» —
            // это и есть порядок выдачи.
            $table->index(['broadcast_id', 'publish_at'], 'chat_broadcast_items_feed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->dropIndex('chat_broadcast_items_feed_idx');
            $table->dropColumn(['publish_at', 'is_pinned']);
        });
    }
};
