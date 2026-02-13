<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 0..10 (и дальше) должны влезать. numeric(4,2) позволяет до 99.99
        DB::statement('ALTER TABLE social_link_verifications ALTER COLUMN activity_score TYPE numeric(4,2)');
        DB::statement('ALTER TABLE social_link_verifications ALTER COLUMN events_score   TYPE numeric(4,2)');

        // hq_confidence оставляем numeric(3,2): это 0..1 и ему достаточно
    }

    public function down(): void
    {
        // Откат может сломаться, если уже есть 10+.
        // Поэтому явно проверяем и падаем, чтобы не потерять данные.
        $bad = DB::selectOne("
            SELECT
              SUM(CASE WHEN ABS(activity_score) >= 10 THEN 1 ELSE 0 END) +
              SUM(CASE WHEN ABS(events_score)   >= 10 THEN 1 ELSE 0 END) AS cnt
            FROM social_link_verifications
        ");

        if (($bad->cnt ?? 0) > 0) {
            throw new RuntimeException('Cannot downgrade scores to numeric(3,2): found values with abs(score) >= 10');
        }

        DB::statement('ALTER TABLE social_link_verifications ALTER COLUMN activity_score TYPE numeric(3,2)');
        DB::statement('ALTER TABLE social_link_verifications ALTER COLUMN events_score   TYPE numeric(3,2)');
    }
};
