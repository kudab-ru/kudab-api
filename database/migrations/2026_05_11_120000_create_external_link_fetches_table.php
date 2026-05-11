<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кэш + дебаг-история фетчей внешних ссылок (kudab-parser TASKS.md §13).
 *
 * Использование: `App\Support\ExternalLinkFetcher` пишет сюда каждый успех/
 * провал HTTP-запроса по URL'у из поста (после strip HTML → text). При
 * повторном fetch'е того же URL в течение TTL — отдаётся cached.
 *
 * Не хранит сам HTML — только text после strip, чтобы не разрастаться.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_link_fetches', function (Blueprint $table) {
            $table->id();

            // sha256(original_url) — UNIQUE, lookup key
            $table->char('url_hash', 64)->unique();

            $table->text('url');                          // оригинальный URL
            $table->text('final_url')->nullable();        // после редиректов

            // ok | failed | blocked | oversize | timeout
            // (blocked — host в blocklist'е / SSRF-IP; oversize — body > limit)
            $table->string('status', 16);

            $table->unsignedSmallInteger('http_status')->nullable();

            $table->longText('content_text')->nullable(); // после strip HTML
            $table->unsignedInteger('content_size')->nullable(); // байт ДО strip

            $table->text('error')->nullable();            // подробности fail/blocked

            $table->timestamp('fetched_at');
            $table->timestamp('expires_at');              // fetched_at + TTL

            $table->timestamps();

            $table->index('expires_at');
            $table->index('fetched_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_link_fetches');
    }
};
