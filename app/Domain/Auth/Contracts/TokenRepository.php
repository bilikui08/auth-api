<?php

namespace App\Domain\Auth\Contracts;

use App\Domain\Auth\DTO\TokenData;

interface TokenRepository
{
    /** Emite los tokens de una empresa con el permiso temporal que se obtuvo al validar las credenciales. */
    public function issueForOrganization(string $selectionToken, int $organizationId): TokenData;

    /** Renueva los tokens conservando la empresa de la sesión, o cambiando a $organizationId si se indica. */
    public function refresh(string $refreshToken, ?int $organizationId = null): TokenData;

    public function revoke(string $accessTokenId): void;
}
