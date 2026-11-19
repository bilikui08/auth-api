<?php

namespace App\Domain\Auth\Contracts;

use App\Domain\Auth\DTO\RegisterData;
use App\Domain\Auth\DTO\ResetPasswordData;
use App\Models\User;

interface AuthRepository
{
    public function create(RegisterData $data): User;

    public function findByIdentifier(string $identifier): ?User;

    /** Si el email tiene cuenta, le manda el enlace (token hasheado, vence, un solo uso). Nunca revela si existe. */
    public function sendPasswordResetLink(string $email): void;

    /** Indica si el enlace sirve todavía (no vencido, no usado, del email indicado), sin consumirlo. */
    public function passwordResetIsValid(string $email, string $token): bool;

    /** Cambia la contraseña con el enlace, lo consume y cierra todas las sesiones de la persona. @throws \App\Domain\Auth\Exceptions\PasswordResetNotValid */
    public function resetPassword(ResetPasswordData $data): void;
}
