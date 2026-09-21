<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ключ для склейки повторных заявок на один и тот же источник.
//
// Форма публичная: один человек может отправить заявку дважды, а бот —
// сколько угодно. Складывать их отдельными строками значит заставлять
// владельца разбирать одно и то же по нескольку раз.
//
// Ключ — нормализованный адрес: без схемы, www, хвостового слэша, query и
// якоря, в нижнем регистре. Так «https://VK.com/Paradice/» и
// «vk.com/paradice?from=feed» — одна и та же заявка.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_leads', function (Blueprint $table) {
            $table->string('source_key', 300)->nullable()->after('source_url');
            $table->index(['source_key', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::table('organizer_leads', function (Blueprint $table) {
            $table->dropIndex(['source_key', 'resolved_at']);
            $table->dropColumn('source_key');
        });
    }
};
