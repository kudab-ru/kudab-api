<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал состава подборок: что выбрал автомат и что потом поменял человек. Пишет `DigestRosterJournal`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_edits', function (Blueprint $table) {
            $table->id();
            // `digest` — подборка канала; программы праздников пойдут сюда же своим видом
            $table->string('scope', 16);
            // рубрика подборки, слаг из config/broadcast_digest.php
            $table->string('rubric', 64)->nullable();
            // без внешнего ключа: журнал нужен и после удаления записи
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedBigInteger('broadcast_id')->nullable();
            // `auto` — состав выбрал автомат, остальные значения — правки человека
            $table->string('action', 16);
            $table->unsignedBigInteger('event_in')->nullable();
            $table->unsignedBigInteger('event_out')->nullable();
            $table->jsonb('roster_before')->nullable();
            $table->jsonb('roster_after')->nullable();
            // убранному поставили «больше не предлагать»
            $table->boolean('cooled')->default(false);
            $table->string('reason', 300)->nullable();
            // каким правилом автомат сам не взял добавленное, см. `BroadcastDigestComposer::missReason`
            $table->string('missed_rule', 32)->nullable();
            $table->string('missed_note', 300)->nullable();
            // пусто — сборка по расписанию, без человека
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['scope', 'broadcast_id', 'id']);
            $table->index(['item_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_edits');
    }
};
