<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0.5 approve-in-DM: поля ревью-гейта в очереди публикаций.
 *
 * Всё nullable + аддитивно — безопасно на горячей таблице. Статусы
 * pending_review/approved/rejected/auto_approved лезут в существующий
 * status varchar(32) без изменения схемы. Спит, пока выключен флаг
 * services.bot.broadcast_review_gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->bigInteger('review_reviewer_telegram_id')->nullable()->after('error_message')
                ->comment('Кому ушло превью на ревью (snapshot owner на момент enqueue)');
            $table->bigInteger('review_message_id')->nullable()->after('review_reviewer_telegram_id')
                ->comment('message_id превью в ЛС (персист вместо in-memory _PREVIEW_MAP бота)');
            $table->timestampTz('review_deadline_at')->nullable()->after('review_message_id')
                ->comment('Дедлайн авто-постинга, если ревьюер не ответил');
            $table->timestampTz('reviewed_at')->nullable()->after('review_deadline_at')
                ->comment('Когда решено: approve/reject/timeout');
            $table->string('review_action', 16)->nullable()->after('reviewed_at')
                ->comment('approve / reject / timeout');

            $table->index(['status', 'review_deadline_at'], 'cbi_status_deadline_idx');
        });
    }

    public function down(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->dropIndex('cbi_status_deadline_idx');
            $table->dropColumn([
                'review_reviewer_telegram_id',
                'review_message_id',
                'review_deadline_at',
                'reviewed_at',
                'review_action',
            ]);
        });
    }
};
