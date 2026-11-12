<?php

namespace App\Domain\Roles\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;

/** Operacion de roles que viola una regla de negocio (se responde como 422). */
final class RoleOperationNotAllowed extends DomainException
{
    /** Laravel convierte las excepciones en respuestas antes de llegar al middleware uniforme. */
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }

    /** Es un error esperable del usuario: no se registra como fallo. */
    public function report(): bool
    {
        return true;
    }

    public static function systemRole(string $name): self
    {
        return new self("El rol «{$name}» es del sistema y no se puede modificar ni eliminar.");
    }

    public static function lastAdmin(): self
    {
        return new self('Debe existir al menos un usuario con el rol «admin».');
    }
}
