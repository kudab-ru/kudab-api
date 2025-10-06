<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_chats', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tg_chat_id')->unique();
            $t->string('type', 20); // check ниже
            $t->string('title')->nullable();
            $t->string('username')->nullable();
            $t->string('invite_link')->nullable();
            $t->boolean('is_member')->default(true);
            $t->string('timezone')->nullable();
            $t->timestamp('left_at')->nullable();
            $t->timestamp('last_activity_at')->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE telegram_chats ADD CONSTRAINT chk_telegram_chats_type CHECK (type IN ('private','group','supergroup','channel'))");

        // telegram_users уже есть в проекте
        Schema::create('telegram_chat_members', function (Blueprint $t) {
            $t->unsignedBigInteger('chat_id');
            $t->unsignedBigInteger('user_id');
            $t->string('role', 20); // check ниже
            $t->timestamp('joined_at')->nullable();
            $t->timestamp('left_at')->nullable();
            $t->primary(['chat_id','user_id']);
            $t->foreign('chat_id')->references('id')->on('telegram_chats')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('telegram_users')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE telegram_chat_members ADD CONSTRAINT chk_tg_chat_members_role CHECK (role IN ('creator','admin','member','left','kicked'))");

        Schema::create('tg_message_templates', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->unique();
            $t->string('locale', 8)->default('ru');
            $t->text('body_markdown');
            $t->boolean('show_images')->default(false);
            $t->integer('max_images')->default(0);
            $t->timestamps();
        });

        Schema::create('tg_broadcast_rules', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('chat_id');
            $t->boolean('enabled')->default(true);
            $t->json('cities')->default(DB::raw("'[]'::jsonb"));
            $t->json('interest_slugs')->nullable();
            $t->integer('window_hours')->default(72);
            $t->time('not_before')->nullable();
            $t->time('not_after')->nullable();
            $t->integer('interval_minutes')->default(15);
            $t->integer('burst_limit')->default(1);
            $t->integer('dedup_window_days')->default(7);
            $t->string('update_mode', 10)->default('edit'); // check ниже
            $t->unsignedBigInteger('template_id');
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();

            $t->foreign('chat_id')->references('id')->on('telegram_chats')->cascadeOnDelete();
            $t->foreign('template_id')->references('id')->on('tg_message_templates')->restrictOnDelete();
            $t->foreign('created_by_user_id')->references('id')->on('telegram_users')->nullOnDelete();
        });
        DB::statement("ALTER TABLE tg_broadcast_rules ADD CONSTRAINT chk_tg_rules_update_mode CHECK (update_mode IN ('edit','resend','skip'))");

        Schema::create('tg_broadcast_state', function (Blueprint $t) {
            $t->unsignedBigInteger('rule_id')->primary();
            $t->boolean('enabled')->default(true);
            $t->timestamp('last_run_at')->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamp('cursor_start_time')->nullable();
            $t->integer('backlog_count')->default(0);
            $t->foreign('rule_id')->references('id')->on('tg_broadcast_rules')->cascadeOnDelete();
        });

        Schema::create('tg_outbox', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('rule_id');
            $t->unsignedBigInteger('chat_id');
            $t->unsignedBigInteger('event_id');
            $t->timestamp('scheduled_at');
            $t->string('status', 20)->default('pending'); // check ниже
            $t->integer('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->unsignedBigInteger('message_id')->nullable();
            $t->string('payload_hash', 64);
            $t->timestamps();
            $t->timestamp('sent_at')->nullable();

            $t->foreign('rule_id')->references('id')->on('tg_broadcast_rules')->cascadeOnDelete();
            $t->foreign('chat_id')->references('id')->on('telegram_chats')->cascadeOnDelete();
            $t->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();

            $t->index(['status','scheduled_at'], 'idx_outbox_status_when');
            $t->index(['chat_id','scheduled_at'], 'idx_outbox_chat_when');
            $t->index(['rule_id','status'], 'idx_outbox_rule_status');
        });
        DB::statement("ALTER TABLE tg_outbox ADD CONSTRAINT chk_tg_outbox_status CHECK (status IN ('pending','sent','failed','skipped_dup','edited'))");

        Schema::create('tg_event_deliveries', function (Blueprint $t) {
            $t->unsignedBigInteger('chat_id');
            $t->unsignedBigInteger('event_id');
            $t->unsignedBigInteger('rule_id')->nullable();
            $t->string('dedup_key', 255)->nullable();
            $t->timestamp('first_sent_at');
            $t->timestamp('last_sent_at');
            $t->unsignedBigInteger('message_id')->nullable();
            $t->string('content_hash', 64)->nullable();
            $t->primary(['chat_id','event_id']);
            $t->foreign('chat_id')->references('id')->on('telegram_chats')->cascadeOnDelete();
            $t->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $t->foreign('rule_id')->references('id')->on('tg_broadcast_rules')->nullOnDelete();
            $t->index(['chat_id','dedup_key'], 'idx_deliv_chat_dedup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_event_deliveries');
        Schema::dropIfExists('tg_outbox');
        Schema::dropIfExists('tg_broadcast_state');
        Schema::dropIfExists('tg_broadcast_rules');
        Schema::dropIfExists('tg_message_templates');
        Schema::dropIfExists('telegram_chat_members');
        Schema::dropIfExists('telegram_chats');
    }
};
