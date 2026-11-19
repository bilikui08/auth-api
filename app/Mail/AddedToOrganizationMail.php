<?php

namespace App\Mail;

use App\Application\Mail\EmailTemplateRegistry;
use App\Models\Organization;

/** Aviso a una persona que ya tenía cuenta y fue sumada a una empresa. */
final class AddedToOrganizationMail extends TemplatedMail
{
    public function __construct(
        public readonly Organization $organization,
        public readonly string $inviterName,
        public readonly string $loginUrl,
    ) {
        parent::__construct();
    }

    protected function templateKey(): string
    {
        return EmailTemplateRegistry::ADDED_TO_ORGANIZATION;
    }

    protected function variables(): array
    {
        return ['invitador' => $this->inviterName, 'empresa' => $this->organization->name];
    }

    protected function buttonUrl(): string
    {
        return $this->loginUrl;
    }
}
