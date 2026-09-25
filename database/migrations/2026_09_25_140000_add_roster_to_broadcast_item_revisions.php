<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Состав подборки на момент сохранения версии.
 *
 * ЗАЧЕМ. Откат версии возвращает подпись из прошлого и ставит
 * `caption_source='manual'`, а такую подпись доставка не пересобирает вовсе.
 * Состав при этом не трогается — то есть откат был законным способом выпустить
 * текст, написанный под ДРУГУЮ тройку событий: ровно то, против чего заведено
 * правило «текст всегда под текущий состав».
 *
 * Проверить было нечем: история хранит подпись, картинки и автора, а состав —
 * нет. Теперь хранит.
 *
 * У версий, записанных до этой миграции, поле пустое — для них откат
 * разрешаем как раньше: знать, под что они написаны, неоткуда.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chat_broadcast_item_revisions', function (Blueprint $table) {
            $table->jsonb('roster')->nullable()->after('photo_urls');
        });
    }

    public function down(): void
    {
        Schema::table('telegram.chat_broadcast_item_revisions', function (Blueprint $table) {
            $table->dropColumn('roster');
        });
    }
};
