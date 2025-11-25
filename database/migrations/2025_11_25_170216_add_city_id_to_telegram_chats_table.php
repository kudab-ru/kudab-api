<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Привязка Telegram-чатов к городу (cities.id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.chats', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')
                ->nullable()
                ->after('username')
                ->comment('ID города (cities.id), по которому подбираем события для рассылки');

            $table->foreign('city_id', 'telegram_chats_city_fk')
                ->references('id')
                ->on('cities')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('telegram.chats', function (Blueprint $table) {
            $table->dropForeign('telegram_chats_city_fk');
            $table->dropColumn('city_id');
        });
    }
};
