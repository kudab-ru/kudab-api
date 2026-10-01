<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Почему пост стоит в ленте: кто его поставил (`by`) и по какому правилу.
 * Пусто — пост выбран до появления колонки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->jsonb('pick_meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram.chat_broadcast_items', function (Blueprint $table) {
            $table->dropColumn('pick_meta');
        });
    }
};
