<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Календарная дата события (ключевая штука для date-only)
            if (!Schema::hasColumn('events', 'start_date')) {
                $table->date('start_date')->nullable()->after('start_time');
            }

            // Точность времени: datetime|date (пока достаточно)
            if (!Schema::hasColumn('events', 'time_precision')) {
                $table->string('time_precision', 16)->default('datetime')->after('start_date');
            }

            // Сырой текст про время (вечером/после 18/уточнить)
            if (!Schema::hasColumn('events', 'time_text')) {
                $table->string('time_text', 80)->nullable()->after('time_precision');
            }

            // Часовой пояс события (пока у вас по факту Europe/Moscow)
            if (!Schema::hasColumn('events', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('time_text');
            }
        });

        // Базовый backfill без тяжёлых операций:
        // 1) start_date из start_time (в локальном TZ, чтобы дата совпадала с тем, что видит пользователь)
        DB::statement("
            UPDATE events
            SET start_date = (start_time AT TIME ZONE 'Europe/Moscow')::date
            WHERE start_date IS NULL AND start_time IS NOT NULL
        ");

        // 2) timezone по умолчанию (пока так, потом можно будет ставить по городу/площадке)
        DB::statement("
            UPDATE events
            SET timezone = 'Europe/Moscow'
            WHERE timezone IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // dropColumn безопасно, даже если поле уже отсутствует — не всегда, поэтому проверим.
            $cols = [];
            foreach (['start_date', 'time_precision', 'time_text', 'timezone'] as $c) {
                if (Schema::hasColumn('events', $c)) $cols[] = $c;
            }
            if ($cols) $table->dropColumn($cols);
        });
    }
};
