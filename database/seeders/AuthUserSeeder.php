<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AuthUserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'gustavo639@gmail.com'],
            [
                'name' => 'Gustavo Luis',
                'password' => 'Nueva123',
            ],
        );
    }
}
