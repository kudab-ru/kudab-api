<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Админка источников PR2: заявка на probe-разведку сайта. Пишет kudab-api
// (форма «добавить сайт»), выполняет парсер (consume-probe-requests,
// everyMinute), результат кладётся обратно — админка поллит.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_probe_requests', function (Blueprint $table) {
            $table->id();
            $table->string('listing_url', 500);
            $table->string('status', 20)->default('pending'); // pending|running|done|failed
            $table->json('result')->nullable();               // clusters/suggested_regex/coverage
            $table->text('error')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_probe_requests');
    }
};
