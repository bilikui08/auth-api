<?php

namespace App\Domain\Organizations\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;

/** Operación de empresas que viola una regla de negocio (se responde como 422 con el motivo). */
final class OrganizationOperationNotAllowed extends DomainException
{
    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage(), 'data' => null, 'errors' => null], 422);
    }

    /** Es un error esperable de quien administra: no se registra como fallo. */
    public function report(): bool
    {
        return true;
    }

    public static function lastAdmin(): self
    {
        return new self('La empresa debe tener al menos un administrador: asigná ese rol a otra persona antes.');
    }

    public static function alreadyMember(): self
    {
        return new self('Esa persona ya pertenece a la empresa.');
    }

    public static function notAMember(): self
    {
        return new self('Esa persona no pertenece a la empresa.');
    }

    public static function protectedRole(string $name): self
    {
        return new self("Los permisos del rol «{$name}» no se pueden modificar: es el rol del dueño de la empresa.");
    }
}
