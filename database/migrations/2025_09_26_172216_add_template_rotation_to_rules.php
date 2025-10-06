<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tg_broadcast_rules', function (Blueprint $table) {
            $table->string('template_mode', 10)->default('static'); // static|rotate|random
            $table->json('template_ids')->nullable(); // массив id шаблонов для ротации
        });
        DB::statement("ALTER TABLE tg_broadcast_rules ADD CONSTRAINT chk_tg_rules_template_mode CHECK (template_mode IN ('static','rotate','random'))");
    }

    public function down(): void
    {
        Schema::table('tg_broadcast_rules', function (Blueprint $table) {
            $table->dropColumn(['template_mode','template_ids']);
        });
        DB::statement("ALTER TABLE tg_broadcast_rules DROP CONSTRAINT IF EXISTS chk_tg_rules_template_mode");
    }
};
