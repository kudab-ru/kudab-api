<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // LLM v13: доступность билетов.
            //   available / sold_out / unknown (default).
            // Precision-first: sold_out ставится только по явным маркерам
            // («все билеты проданы», «аншлаг», «регистрация закрыта»).
            // NOT NULL default 'unknown' — PG11+ добавляет default-колонку
            // мгновенно (metadata-only), без переписывания большой таблицы.
            if (!Schema::hasColumn('events', 'tickets_status')) {
                $table->string('tickets_status', 16)->default('unknown');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'tickets_status')) {
                $table->dropColumn('tickets_status');
            }
        });
    }
};
