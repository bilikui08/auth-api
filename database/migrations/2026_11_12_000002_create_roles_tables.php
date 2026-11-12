<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles N:N con usuarios. Reemplaza la columna simple users.role: los roles que
 * ya hubiera se migran a la tabla pivote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'role_id']);
            $table->index('role_id');
        });

        $now = now();

        // Roles del sistema (no se pueden borrar ni renombrar).
        foreach (['admin' => 'Administrador del sistema', 'user' => 'Usuario estandar'] as $name => $description) {
            DB::table('roles')->insert(['name' => $name, 'description' => $description, 'created_at' => $now, 'updated_at' => $now]);
        }

        if (Schema::hasColumn('users', 'role')) {
            // Migra el rol unico anterior (creando los roles personalizados que existieran).
            foreach (DB::table('users')->select('id', 'role')->get() as $user) {
                $name = $user->role ?: 'user';
                $roleId = DB::table('roles')->where('name', $name)->value('id')
                    ?? DB::table('roles')->insertGetId(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);

                DB::table('role_user')->insert(['user_id' => $user->id, 'role_id' => $roleId, 'created_at' => $now, 'updated_at' => $now]);
            }

            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(['role']);
                $table->dropColumn('role');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 50)->default('user')->index()->after('email');
        });

        // Conserva un rol por usuario (el "mayor": admin si lo tiene).
        foreach (DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')->select('role_user.user_id', 'roles.name')->get()->groupBy('user_id') as $userId => $rows) {
            $names = $rows->pluck('name');
            DB::table('users')->where('id', $userId)->update(['role' => $names->contains('admin') ? 'admin' : $names->first()]);
        }

        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
    }
};
