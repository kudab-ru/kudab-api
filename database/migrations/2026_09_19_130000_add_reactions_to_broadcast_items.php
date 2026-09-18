<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Второй прибор отклика: реакции на пост.
 *
 * ЗАЧЕМ ВТОРОЙ. Первый — переходы по метке `utm_content` — меряет не отклик,
 * а исход: человек ушёл на сайт. Замер по каналу за 90 дней: 2 перехода на
 * 7 постов, реферер t.me телеграм режет, и по переходам нельзя отличить
 * «пост не понравился» от «пост понравился, но идти никуда не захотелось».
 * Просмотры Bot API не отдаёт вовсе — это метрика MTProto.
 *
 * Реакции этот разрыв закрывают: они не требуют ни ссылки, ни ухода из
 * телеграма, и ставят их именно те, кто пост прочитал.
 *
 * ЧТО НУЖНО, ЧТОБЫ ПРИБОР ЗАРАБОТАЛ. Две вещи, и обе снаружи этого кода:
 * реакции должны быть ВКЛЮЧЕНЫ в настройках канала (это делается в клиенте
 * телеграма, Bot API такой ручки не имеет), а бот — оставаться
 * администратором канала. Без первого телеграм не пришлёт ни одного
 * обновления, и все значения останутся NULL. Это честное «не мерили», а не
 * «реакций не было».
 *
 * ПОЧЕМУ ТРИ КОЛОНКИ. `reactions` — сумма, по ней сортируют и сравнивают
 * посты. `reactions_meta` — разбивка по эмодзи: «🔥 4, 👍 1» и «👎 5» дают
 * одинаковую сумму, но говорят противоположное. `reactions_at` отделяет
 * «мерили только что» от «последняя реакция была неделю назад».
 *
 * NULL против нуля — как у переходов: NULL значит «обновлений не приходило»,
 * 0 — «реакции ставили и сняли».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->integer('reactions')->nullable()->after('clicks_at')
                ->comment('Сумма реакций на пост; NULL = обновлений не приходило');
            $table->jsonb('reactions_meta')->nullable()->after('reactions')
                ->comment('Разбивка по эмодзи: [{"emoji":"🔥","count":4}]');
            $table->timestampTz('reactions_at')->nullable()->after('reactions_meta')
                ->comment('Когда пришло последнее обновление реакций');
        });

        // Поиск записи по номеру сообщения — единственный путь, которым
        // приходит обновление реакций: телеграм присылает chat_id и
        // message_id, и больше ничего.
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->index(['broadcast_id', 'message_id'], 'cbi_broadcast_message_idx');
        });
    }

    public function down(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->dropIndex('cbi_broadcast_message_idx');
            $table->dropColumn(['reactions', 'reactions_meta', 'reactions_at']);
        });
    }
};
