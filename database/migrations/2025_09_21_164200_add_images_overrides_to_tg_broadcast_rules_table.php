<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tg_broadcast_rules', function (Blueprint $table) {
            $table->boolean('show_images_override')->nullable()->after('template_id');
            $table->integer('max_images_override')->nullable()->after('show_images_override');
        });

        DB::statement(
            'ALTER TABLE tg_broadcast_rules
             ADD CONSTRAINT tg_broadcast_rules_max_images_override_chk
             CHECK (max_images_override IS NULL OR (max_images_override BETWEEN 0 AND 10))'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE tg_broadcast_rules
             DROP CONSTRAINT IF EXISTS tg_broadcast_rules_max_images_override_chk'
        );

        Schema::table('tg_broadcast_rules', function (Blueprint $table) {
            $table->dropColumn(['show_images_override','max_images_override']);
        });
    }
};
