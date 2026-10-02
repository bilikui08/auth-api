<?php

namespace App\Domain\Auth\DTO;

final readonly class AuthData
{
    public function __construct(
        public string $identifier,
        public string $password,
    ) {}
}
