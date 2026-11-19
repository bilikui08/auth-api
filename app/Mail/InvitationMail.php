<?php

namespace App\Mail;

use App\Application\Mail\EmailTemplateRegistry;
use App\Models\Organization;

/** Invitación a sumarse a una empresa: lleva el enlace (con el token secreto) para crear la cuenta. */
final class InvitationMail extends TemplatedMail
{
    public function __construct(
        public readonly Organization $organization,
        public readonly string $inviterName,
        public readonly string $acceptUrl,
        public readonly int $validDays,
    ) {
        parent::__construct();
    }

    protected function templateKey(): string
    {
        return EmailTemplateRegistry::INVITATION;
    }

    protected function variables(): array
    {
        return ['invitador' => $this->inviterName, 'empresa' => $this->organization->name, 'dias' => (string) $this->validDays];
    }

    protected function buttonUrl(): string
    {
        return $this->acceptUrl;
    }
}
