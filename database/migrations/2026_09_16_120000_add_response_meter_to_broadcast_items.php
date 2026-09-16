<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Прибор отклика: чем ответил канал на пост.
 *
 * ЗАЧЕМ. До сих пор у канала не было НИ ОДНОГО прибора. Ни просмотров, ни
 * реакций, ни переходов — ни одного числа, по которому можно сказать, сработал
 * пост или нет. Из-за этого любой спор о качестве («живо — не живо», «какие
 * события ставить») решался мнением, а веса подбора настраивались вслепую.
 *
 * ЧТО МОЖНО ИЗМЕРИТЬ НА САМОМ ДЕЛЕ. Просмотры постов Bot API не отдаёт вовсе —
 * это MTProto-метрика, боту недоступная, и обещать её нельзя. Зато доступны
 * две вещи:
 *
 *   - ПЕРЕХОДЫ. Ссылка в посте получает метку `utm_content = i<id записи>`, и
 *     Яндекс.Метрика считает визиты в разрезе этой метки. Это прибор ровно на
 *     тот вопрос, ради которого канал существует: привёл ли пост человека.
 *   - НОМЕР СООБЩЕНИЯ. Без него пост в канале нельзя ни найти, ни открыть, ни
 *     связать с чем-либо снаружи. Он же — вход для будущих реакций
 *     (`message_reaction_count` появился в Bot API 7.0).
 *
 * Все поля nullable: у постов, ушедших до этой миграции, номера сообщения нет
 * и взять его неоткуда, а `clicks = NULL` честно отличается от `clicks = 0`
 * («мерили, переходов не было»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->bigInteger('message_id')->nullable()->after('posted_at')
                ->comment('id сообщения в канале: вход для ссылки на пост и для реакций');
            $table->integer('clicks')->nullable()->after('message_id')
                ->comment('Переходов по ссылке поста, по метке utm_content; NULL = ещё не мерили');
            $table->timestampTz('clicks_at')->nullable()->after('clicks')
                ->comment('Когда переходы считали в последний раз');
        });
    }

    public function down(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->dropColumn(['message_id', 'clicks', 'clicks_at']);
        });
    }
};
