<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заявка на текст: «напиши анонс этому посту, вот пожелание».
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЕ ПОЛЯ, А НЕ planned_at. Придержка planned_at — это «подожди
 * несколько минут, парсер допишет текст», и она живёт минуты: describe-due
 * берёт по ней только записи, поставленные прямо сейчас (см. ограничение
 * planned_at <= created_at + grace). Пост недельной ленты создан позавчера, и
 * под это условие он не подходит — попытка выразить заявку придержкой означала
 * бы снять защиту от «утащить завтрашний пост на сегодня».
 *
 * text_requested_at — просьба человека, а не расписание: парсер пишет анонс
 * этому событию вне очереди и поле снимает. Пустое поле = обычный порядок,
 * то есть текст появится сам за tg_lead_minutes до публикации.
 *
 * text_hint — пожелание к тексту («расскажи про бесплатный вход»), уходит в
 * промпт. Живёт на записи очереди, а не на событии: это просьба к ЭТОМУ посту,
 * и следующая генерация того же события её не наследует.
 *
 * Оба поля nullable и аддитивны — безопасно на горячей таблице.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->timestampTz('text_requested_at')->nullable()->after('caption_source')
                ->comment('Человек попросил написать текст ИИ. Снимает парсер (parser:tg:describe-due)');
            $table->string('text_hint', 500)->nullable()->after('text_requested_at')
                ->comment('Пожелание администратора к тексту — уходит в промпт генерации');
        });

        // Парсер каждую минуту спрашивает «есть ли заявки». Заявок единицы,
        // записей очереди — тысячи: частичный индекс держит этот запрос
        // копеечным и почти ничего не весит.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE INDEX IF NOT EXISTS chat_broadcast_items_text_req_idx
                 ON telegram.chat_broadcast_items (text_requested_at)
                 WHERE text_requested_at IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('DROP INDEX IF EXISTS telegram.chat_broadcast_items_text_req_idx');
        }

        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->dropColumn(['text_requested_at', 'text_hint']);
        });
    }
};
