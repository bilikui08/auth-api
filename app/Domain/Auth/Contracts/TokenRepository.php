<?php

namespace App\Domain\Auth\Contracts;

use App\Domain\Auth\DTO\TokenData;

interface TokenRepository
{
    public function issue(string $identifier, string $password): TokenData;

    public function refresh(string $refreshToken): TokenData;

    public function revoke(string $accessTokenId): void;
}
