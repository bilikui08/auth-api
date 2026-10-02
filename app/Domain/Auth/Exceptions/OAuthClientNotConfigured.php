<?php

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

final class OAuthClientNotConfigured extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El cliente OAuth de Passport no está configurado.');
    }
}
