<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram.users', function (Blueprint $t) {
            // уникальность по telegram_id для корректного upsert в DAL
            $t->unique('telegram_id', 'telegram_users_telegram_id_uniq');
        });
    }

    public function down(): void
    {
        Schema::table('telegram.users', function (Blueprint $t) {
            $t->dropUnique('telegram_users_telegram_id_uniq');
        });
    }
};
