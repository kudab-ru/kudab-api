<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Подробности карточки катка: фраза о катке, сеанс и дорога. Детский билет, залог за коньки и оплата
 * лежат рядом с ценами, в `prices` (`child_text`, `deposit`, `pay`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rinks', function (Blueprint $table) {
            $table->string('about')->nullable();
            $table->string('session_note')->nullable();
            $table->string('way')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rinks', function (Blueprint $table) {
            $table->dropColumn(['about', 'session_note', 'way']);
        });
    }
};
