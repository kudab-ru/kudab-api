<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tg_broadcast_rules', function (Blueprint $table) {
            $table->string('schedule_mode', 12)->default('interval'); // interval|window|rrule
            $table->string('tz', 64)->default('Europe/Moscow');
            $table->json('schedule_json')->nullable();
        });
        DB::statement("ALTER TABLE tg_broadcast_rules
            ADD CONSTRAINT chk_tg_rules_schedule_mode
            CHECK (schedule_mode IN ('interval','window','rrule'))");
    }

    public function down(): void
    {
        Schema::table('tg_broadcast_rules', function (Blueprint $table) {
            $table->dropColumn(['schedule_mode','tz','schedule_json']);
        });
        DB::statement("ALTER TABLE tg_broadcast_rules
            DROP CONSTRAINT IF EXISTS chk_tg_rules_schedule_mode");
    }
};
