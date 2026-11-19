<?php

namespace App\Domain\Organizations\Exceptions;

use RuntimeException;

/** La persona no puede trabajar con la empresa pedida (no pertenece, o no tiene ninguna). */
final class OrganizationAccessDenied extends RuntimeException
{
    public static function noOrganizations(): self
    {
        return new self('Tu usuario no tiene empresas asignadas. Contactá al administrador.');
    }

    public static function notAMember(): self
    {
        return new self('No pertenecés a esa empresa o está dada de baja.');
    }
}
