<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Имя профиля сайта бывает длиннее 32 знаков: у касс QuickTickets «quicktickets-voronej-dom-aktera-kravcovoj». */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE source_runs ALTER COLUMN source_slug TYPE varchar(100)');
    }

    public function down(): void
    {
        // назад не сужаем, если длинные имена уже записаны: иначе откат упадёт посреди выката
        $long = (int) DB::table('source_runs')->whereRaw('length(source_slug) > 32')->count();
        if ($long === 0) {
            DB::statement('ALTER TABLE source_runs ALTER COLUMN source_slug TYPE varchar(32)');
        }
    }
};
