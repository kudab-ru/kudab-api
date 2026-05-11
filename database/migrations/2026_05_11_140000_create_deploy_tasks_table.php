<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `deploy_tasks` — трекер one-shot data-операций после деплоя (по аналогии с
 * Laravel migrations, но для данных, а не схемы). См. `app/DeployTasks/*.php`.
 *
 * Использование: `php artisan parser:deploy:run-once-tasks` после deploy'а
 * (или из `make prod-deploy`). Идемпотентно: каждый task выполняется
 * РОВНО ОДИН РАЗ на стенде.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploy_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191)->unique();   // имя class'а task'а (FQCN или короткое)
            $table->timestamp('executed_at');         // когда успешно выполнилось
            $table->unsignedInteger('duration_ms')->nullable();
            $table->longText('output')->nullable();   // лог-выход task'а (для диагностики)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_tasks');
    }
};
