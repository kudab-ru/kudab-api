<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        DB::statement(
            "ALTER TABLE tg_broadcast_rules
             ALTER COLUMN schedule_json TYPE jsonb
             USING schedule_json::jsonb"
        );
    }
    public function down(): void {
        DB::statement(
            "ALTER TABLE tg_broadcast_rules
             ALTER COLUMN schedule_json TYPE json
             USING schedule_json::json"
        );
    }
};
