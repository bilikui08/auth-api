<?php

namespace App\Infrastructure\Auth;

use App\Domain\Auth\Contracts\AuthRepository;
use App\Domain\Auth\DTO\RegisterData;
use App\Models\User;
use Illuminate\Support\Facades\Password;

final class EloquentAuthRepository implements AuthRepository
{
    public function create(RegisterData $data): User
    {
        return User::query()->create([
            'name' => $data->name,
            'email' => $data->email,
            'password' => $data->password,
        ]);
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
