<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            // ускоряет все выборки/апдейты по владельцу вложений
            $table->index(['parent_type', 'parent_id'], 'idx_attachments_parent');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex('idx_attachments_parent');
        });
    }
};
