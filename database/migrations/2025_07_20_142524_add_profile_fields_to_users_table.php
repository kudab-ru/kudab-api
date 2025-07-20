<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_url')->nullable()->comment('Ссылка на аватар пользователя')->after('password');
            $table->text('bio')->nullable()->comment('Коротко о себе')->after('avatar_url');
            $table->softDeletes()->comment('Мягкое удаление');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_url');
            $table->dropColumn('bio');
            $table->dropSoftDeletes();
        });
    }
};
