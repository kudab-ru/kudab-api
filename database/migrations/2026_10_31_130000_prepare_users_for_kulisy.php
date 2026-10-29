<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // Организатор, пришедший через Telegram, живёт без почты и пароля, пока не задаст их сам.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });

        Role::findOrCreate('organizer', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Почту и пароль обязательными обратно не делаем: у организаторов из Telegram их нет.
        Role::where(['name' => 'organizer', 'guard_name' => 'web'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
