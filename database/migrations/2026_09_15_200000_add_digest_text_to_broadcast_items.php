<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Текст подборки, написанный моделью, и тема, под которую он написан.
 *
 * ЗАЧЕМ ОТДЕЛЬНОЕ ПОЛЕ. У обычного поста текст модели лежит в
 * `events.tg_description` — у события, про которое написан. У подборки такого
 * места нет: подводка написана про НЕДЕЛЮ, а строки — про тройку событий
 * вместе, и по одному событию их не разложить (в отдельном посте эта строка
 * читалась бы обрубком).
 *
 * ЧТО ВНУТРИ:
 *   theme   — слаг темы («koncerty»), под которую собран состав. Без него
 *             пересборка подписи по сохранённому составу невозможна: заголовок,
 *             склонения и подвал берутся из реестра тем по слагу, а вывести его
 *             из событий нельзя — у 4.8% событий больше одного первичного
 *             интереса, и три названных дали бы разные темы.
 *   intro   — фраза про эту неделю, вместо шаблонной подводки.
 *   hooks   — строка про каждое событие, ключ — id события. Ключом именно id:
 *             состав между заказом текста и отправкой может сдвинуться, и
 *             строка обязана уехать вместе со своим событием, а не с позицией.
 *   model / written_at / prompt — след генерации, как text_meta у событий.
 *
 * Поле аддитивное и nullable: подборка без него собирается ровно как раньше,
 * шаблонной подводкой и первой фразой описания.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->jsonb('digest_meta')->nullable()->after('edited_fields')
                ->comment('Подборка: тема состава и текст, написанный моделью (intro + hooks по event_id)');
        });
    }

    public function down(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->dropColumn('digest_meta');
        });
    }
};
