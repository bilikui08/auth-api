<?php

namespace App\Domain\Organizations\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;

/** La invitación no existe, venció, se revocó o ya se usó. Un único mensaje: no revela cuál de esos casos es. */
final class InvitationNotValid extends DomainException
{
    public const MESSAGE = 'La invitación no es válida o venció. Pedí que te inviten de nuevo.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage(), 'data' => null, 'errors' => null], 404);
    }

    public function report(): bool
    {
        return true;
    }
}
