<?php

namespace App\Domain\Auth\Contracts;

use App\Domain\Auth\DTO\RegisterData;
use App\Models\User;

interface AuthRepository
{
    public function create(RegisterData $data): User;

    public function findByIdentifier(string $identifier): ?User;

    public function sendPasswordResetLink(string $email): void;
}
