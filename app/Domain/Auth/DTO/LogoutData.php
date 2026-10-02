<?php

namespace App\Domain\Auth\DTO;

final readonly class LogoutData
{
    public function __construct(public string $accessTokenId) {}
}
