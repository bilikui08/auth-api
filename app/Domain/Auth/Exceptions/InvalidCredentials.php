<?php

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

final class InvalidCredentials extends RuntimeException
{
    public function __construct(string $message = 'Las credenciales ingresadas no son válidas.')
    {
        parent::__construct($message);
    }
}
