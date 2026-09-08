<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Откуда взялся текст поста.
 *
 * Колонка caption существовала, но заполнялась ТОЛЬКО у портретов площадок
 * (7 строк из 99): текст событийного поста нигде не хранился — API отдавал
 * боту event_id и код шаблона, а подпись бот собирал сам. Из-за этого пост
 * нельзя было ни отредактировать, ни честно показать в админке.
 *
 * Теперь текст строится в API (EventCaptionBuilder) и кладётся в caption.
 * Этот признак нужен, чтобы пересборка ленты не затирала ручную правку:
 *   template — текст собран из шаблона, можно пересобирать свободно;
 *   manual   — правили руками, не трогать.
 *
 * Nullable и аддитивно — безопасно на горячей таблице. У существующих строк
 * остаётся NULL: у портретов площадок текст и раньше не пересобирался, а
 * событийные записи прошлого всё равно уже отправлены.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->string('caption_source', 16)->nullable()->after('caption')
                ->comment('template = собран из шаблона, можно пересобирать; manual = правили руками, не трогать');
        });
    }

    public function down(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->dropColumn('caption_source');
        });
    }
};
