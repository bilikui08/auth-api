<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesRealPassport;
use Tests\TestCase;

/**
 * En el hosting compartido los permisos de las claves de Passport vuelven solos a 644 (y con PHP 8.5 el aviso de la librería OAuth,
 * que pide 600, se convertía en un error 500 en el login). El login tiene que funcionar igual.
 */
class KeyPermissionsTest extends TestCase
{
    use RefreshDatabase;
    use UsesRealPassport;

    public function test_login_works_even_if_the_key_files_are_world_readable(): void
    {
        $this->setUpPassport();
        chmod($this->keyDir . '/oauth-private.key', 0644);
        chmod($this->keyDir . '/oauth-public.key', 0644);

        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => 'ClaveVieja1']);
        $organization = Organization::create(['name' => 'Empresa A SA']);
        $organization->users()->attach($user->id, ['role_id' => Role::where('name', 'company_admin')->value('id')]);

        $this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'ClaveVieja1'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['tokens' => ['access_token']]]);

        $this->tearDownPassport();
    }
}
