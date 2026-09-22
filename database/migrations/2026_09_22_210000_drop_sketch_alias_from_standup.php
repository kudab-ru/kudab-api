<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// «скетч» как псевдоним темы «Стендап и юмор» — ложный друг.
//
// В русском это ещё и быстрый рисунок. Псевдоним срабатывал ровно на двух
// событиях, и оба — мастер-классы по рисованию: «Мастер-класс „Воронежский
// дворец. Скетч“» получал главной темой standup. Попаданий в настоящий
// стендап — ноль.
//
// Сидер правится тем же коммитом; здесь — чтобы правка доехала до баз, где
// сидер повторно не гоняют.
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('interests')->where('slug', 'standup')->value('id');
        if ($id === null) {
            return;
        }

        DB::table('interest_aliases')->where('interest_id', $id)->where('alias', 'скетч')->delete();
    }

    public function down(): void
    {
        // Возврата нет намеренно: псевдоним давал только ложные срабатывания.
    }
};
