<?php

namespace App\Domain\Auth\DTO;

final readonly class RefreshTokenData
{
    public function __construct(public string $refreshToken) {}
}
