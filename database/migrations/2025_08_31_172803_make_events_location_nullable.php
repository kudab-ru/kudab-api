<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE events ALTER COLUMN location DROP NOT NULL");
    }
    public function down(): void
    {
        // откат возможен только если нет NULL-ов
        DB::statement("ALTER TABLE events ALTER COLUMN location SET NOT NULL");
    }
};
