<?php

namespace App\Domain\Auth\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;

/** El enlace de restablecimiento no existe, venció, ya se usó o no corresponde al email. Un único mensaje: no revela cuál de esos casos es. */
final class PasswordResetNotValid extends DomainException
{
    public const MESSAGE = 'El enlace no es válido o venció. Pedí uno nuevo.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage(), 'data' => null, 'errors' => null], 422);
    }
}
