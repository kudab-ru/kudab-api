<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Заявки от организаторов: «подключите мой источник» и «хочу поддержать».
//
// Сторона СПРОСА, которой у проекта не было вовсе: мы искали источники сами —
// мониторили ссылки, разведывали сайты, подписывались на паблики. При этом
// организатору нишевого события наш анонс нужнее, чем нам его квартирник, и
// он готов прийти сам. Страница /organizers на сайте уже приглашает присылать
// ссылки, а форма написана и помечена «НЕ ПОДКЛЮЧЁН»: ручки приёма не было,
// и заявка уходила в никуда.
//
// Приём публичный и без авторизации, поэтому таблица рассчитана на мусор:
// ip и user_agent нужны, чтобы отличить живого человека от бота, а
// resolved_at — чтобы разобранное не мозолило глаза.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer_leads', function (Blueprint $table) {
            $table->id();
            // source — «подключите мой источник», sponsor — «хочу поддержать»
            $table->string('kind', 20)->default('source');
            $table->string('source_url', 500)->nullable();
            $table->string('contact', 300);
            $table->string('city', 120)->nullable();
            $table->text('comment')->nullable();
            // с какой страницы сайта пришла заявка
            $table->string('page_path', 300)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            // разобрана владельцем: подключили, отказали или это спам
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 20)->nullable();
            $table->timestamps();

            $table->index(['resolved_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_leads');
    }
};
