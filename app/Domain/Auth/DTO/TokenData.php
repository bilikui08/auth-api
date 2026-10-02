<?php

namespace App\Domain\Auth\DTO;

use JsonSerializable;

final readonly class TokenData implements JsonSerializable
{
    public function __construct(
        public string $tokenType,
        public int $expiresIn,
        public string $accessToken,
        public string $refreshToken,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function jsonSerialize(): array
    {
        return [
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
        ];
    }
}
