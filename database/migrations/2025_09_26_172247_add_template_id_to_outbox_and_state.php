<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // tg_outbox.template_id
        Schema::table('tg_outbox', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable()->after('rule_id');
        });

        // бэкофилл из rules
        DB::statement("
            UPDATE tg_outbox o
            SET template_id = r.template_id
            FROM tg_broadcast_rules r
            WHERE o.rule_id = r.id AND o.template_id IS NULL
        ");

        // FK + NOT NULL только для будущих записей (оставим NULL для старых)
        Schema::table('tg_outbox', function (Blueprint $table) {
            $table->foreign('template_id')->references('id')->on('tg_message_templates');
        });

        // tg_broadcast_state.next_template_idx
        if (!Schema::hasColumn('tg_broadcast_state', 'next_template_idx')) {
            Schema::table('tg_broadcast_state', function (Blueprint $table) {
                $table->integer('next_template_idx')->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::table('tg_outbox', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropColumn('template_id');
        });
        if (Schema::hasColumn('tg_broadcast_state', 'next_template_idx')) {
            Schema::table('tg_broadcast_state', function (Blueprint $table) {
                $table->dropColumn('next_template_idx');
            });
        }
    }
};
