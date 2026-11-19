<?php

use App\Application\Organizations\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Empresas (organizaciones) con varios usuarios, permisos por rol y la empresa con la que se emitió cada token.
 *
 * Cada usuario que ya existe pasa a ser administrador de su propia empresa, con el MISMO id que el usuario
 * (organizations.id = users.id): así billing puede pasar de `user_id` a `organization_id` sin mover un solo dato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('tax_id', 20)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('module', 50)->index();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
        });

        // Qué rol(es) tiene una persona en cada empresa: el mismo usuario puede ser administrador en una y lector en otra.
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['organization_id', 'user_id', 'role_id']);
            $table->index('user_id');
        });

        // Con qué empresa se emitió cada access token: el refresh y el cambio de empresa parten de acá.
        Schema::create('oauth_token_organizations', function (Blueprint $table): void {
            $table->string('access_token_id', 100)->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        PermissionCatalog::sync();

        // El cliente OAuth con el que auth-api emite tokens debe poder usar el grant de selección de empresa
        foreach (DB::table('oauth_clients')->get(['id', 'grant_types']) as $client) {
            $grants = json_decode((string) $client->grant_types, true) ?: [];

            if (in_array('password', $grants, true) && ! in_array('organization_selection', $grants, true)) {
                DB::table('oauth_clients')->where('id', $client->id)->update([
                    'grant_types' => json_encode([...$grants, 'organization_selection']),
                ]);
            }
        }

        $adminRole = DB::table('roles')->where('name', PermissionCatalog::COMPANY_ADMIN)->value('id');
        $now = now();

        foreach (DB::table('users')->select('id', 'name')->orderBy('id')->get() as $user) {
            DB::table('organizations')->insert(['id' => $user->id, 'name' => $user->name, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('organization_user')->insert([
                'organization_id' => $user->id, 'user_id' => $user->id, 'role_id' => $adminRole, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('oauth_clients')->get(['id', 'grant_types']) as $client) {
            $grants = array_values(array_diff(json_decode((string) $client->grant_types, true) ?: [], ['organization_selection']));
            DB::table('oauth_clients')->where('id', $client->id)->update(['grant_types' => json_encode($grants)]);
        }

        Schema::dropIfExists('oauth_token_organizations');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('organizations');

        DB::table('roles')->whereIn('name', array_keys(PermissionCatalog::ROLES))->delete();
    }
};
