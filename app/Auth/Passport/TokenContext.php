<?php

namespace App\Auth\Passport;

/**
 * Con qué empresa se está emitiendo el token en curso. Lo fijan los grants (login, selección de empresa, refresh)
 * y lo lee ClaimsAccessToken al armar el JWT. Dura lo que dura la emisión: quien lo usa lo limpia al terminar.
 */
final class TokenContext
{
    public ?int $organizationId = null;

    /** Empresa pedida explícitamente (cambio de empresa); si es null el refresh conserva la del token anterior. */
    public ?int $requestedOrganizationId = null;

    public function reset(): void
    {
        $this->organizationId = null;
        $this->requestedOrganizationId = null;
    }
}
