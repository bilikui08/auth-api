<?php

namespace App\Mail;

use App\Application\Mail\EmailTemplateRegistry;

/** Restablecer contraseña: lleva el enlace (con el token secreto) a la pantalla donde se elige la nueva. */
final class PasswordResetMail extends TemplatedMail
{
    public function __construct(
        public readonly string $resetUrl,
        public readonly int $validMinutes,
    ) {
        parent::__construct();
    }

    protected function templateKey(): string
    {
        return EmailTemplateRegistry::PASSWORD_RESET;
    }

    protected function variables(): array
    {
        return ['minutos' => (string) $this->validMinutes];
    }

    protected function buttonUrl(): string
    {
        return $this->resetUrl;
    }
}
