<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class AuthUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'gustavo639@gmail.com'],
            [
                'name' => 'Gustavo Luis',
                'password' => 'Nueva123',
            ],
        );

        // Usuario inicial de desarrollo: administrador (permite probar la gestion de roles).
        $roles = Role::query()->whereIn('name', [Role::ADMIN, Role::USER])->pluck('id');
        $user->roles()->syncWithoutDetaching($roles);
    }
}
