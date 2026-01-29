<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Postgres: меняем типы без doctrine/dbal
        DB::statement("ALTER TABLE communities ALTER COLUMN description TYPE text");
        DB::statement("ALTER TABLE communities ALTER COLUMN avatar_url TYPE text");
        DB::statement("ALTER TABLE communities ALTER COLUMN image_url TYPE text");
    }

    public function down(): void
    {
        // обратно в varchar(255) (обрежет данные, так что осторожно)
        DB::statement("ALTER TABLE communities ALTER COLUMN description TYPE varchar(255)");
        DB::statement("ALTER TABLE communities ALTER COLUMN avatar_url TYPE varchar(255)");
        DB::statement("ALTER TABLE communities ALTER COLUMN image_url TYPE varchar(255)");
    }
};
