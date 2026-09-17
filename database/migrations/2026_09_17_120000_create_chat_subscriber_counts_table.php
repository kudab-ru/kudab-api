<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сколько у канала подписчиков — по дням.
 *
 * ЗАЧЕМ. Все приборы канала, какие есть, меряют ОТТОК: метка utm_content
 * считает переходы из канала на сайт. Притока не меряет ничто, и числа
 * подписчиков нет нигде — ни в базе, ни в админке. Из-за этого вопрос «канал
 * растёт?» не имеет ответа в принципе, а любое предложение «привлечь
 * аудиторию» непроверяемо: не с чем сравнить до и после.
 *
 * ПОЧЕМУ ОТДЕЛЬНАЯ ТАБЛИЦА, А НЕ КОЛОНКА У ЧАТА. Колонка отвечает на «сколько
 * сейчас», а нужен ответ на «сколько прибавилось за неделю» — то есть история.
 * Одна строка в сутки на канал: за год это 365 строк, дешевле любого графика.
 *
 * ДЕНЬ, А НЕ МОМЕНТ. Замер делает бот раз в сутки, и точное время значения не
 * имеет: подписчики не скачут внутри часа. Уникальность по (чат, дата) делает
 * повторный замер идемпотентным — второй прогон в тот же день перезапишет
 * число, а не заведёт вторую строку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram.chat_subscriber_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')
                ->constrained('telegram.chats')
                ->cascadeOnDelete();
            $table->date('measured_on');
            $table->unsignedInteger('count');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['chat_id', 'measured_on'], 'chat_subscriber_counts_day_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram.chat_subscriber_counts');
    }
};
