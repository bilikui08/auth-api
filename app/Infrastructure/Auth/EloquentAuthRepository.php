<?php

namespace App\Infrastructure\Auth;

use App\Domain\Auth\Contracts\AuthRepository;
use App\Domain\Auth\DTO\RegisterData;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Password;

final class EloquentAuthRepository implements AuthRepository
{
    public function create(RegisterData $data): User
    {
        $user = User::query()->create([
            'name' => $data->name,
            'email' => $data->email,
            'password' => $data->password,
        ]);

        // Todo usuario nuevo nace con el rol "user"; los demas roles los asigna un admin.
        $defaultRole = Role::query()->where('name', Role::USER)->value('id');
        if ($defaultRole !== null) {
            $user->roles()->attach($defaultRole);
        }

        return $user;
    }

    public function findByIdentifier(string $identifier): ?User
    {
        return User::query()
            ->where('email', $identifier)
            ->orWhere('name', $identifier)
            ->first();
    }

    public function sendPasswordResetLink(string $email): void
    {
        // The response remains generic to avoid revealing registered addresses.
        Password::broker()->sendResetLink(['email' => $email]);
    }
}
