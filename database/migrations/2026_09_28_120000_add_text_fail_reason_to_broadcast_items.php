<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Почему текст так и не появился. Заявку на текст снимают в любом исходе, и
 * админка показывала прежний текст без объяснений. Пишет парсер, читает лента.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->string('text_fail_reason', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->dropColumn('text_fail_reason');
        });
    }
};
