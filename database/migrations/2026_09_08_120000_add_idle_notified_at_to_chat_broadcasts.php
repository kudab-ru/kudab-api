<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сигнал о простое канала.
 *
 * Рассылка Воронежа встала 2026-07-31 на одной отравленной записи очереди и
 * простояла 33 дня, потому что заметить было нечем: в логи писалось
 * event_load_failed 1892 раза, и их никто не читал. Теперь API сам замечает,
 * что канал молчит дольше двух своих окон, и отдаёт боту задачу написать
 * владельцу в личку.
 *
 * Поле нужно, чтобы напоминание приходило раз в сутки, а не каждый тик
 * поллера (60 с). Nullable и аддитивно — безопасно на горячей таблице.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chat_broadcasts', function (Blueprint $table) {
            $table->timestampTz('idle_notified_at')->nullable()->after('last_preview_at')
                ->comment('Когда владельцу канала в последний раз писали о простое. NULL = не писали');
        });
    }

    public function down(): void
    {
        Schema::table('telegram.chat_broadcasts', function (Blueprint $table) {
            $table->dropColumn('idle_notified_at');
        });
    }
};
