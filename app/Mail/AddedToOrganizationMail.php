<?php

namespace App\Mail;

use App\Models\Organization;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso a una persona que ya tenía cuenta y fue sumada a una empresa. */
final class AddedToOrganizationMail extends Mailable
{
    public function __construct(
        public readonly Organization $organization,
        public readonly string $inviterName,
        public readonly string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Ahora podés trabajar con {$this->organization->name} en " . config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.added-to-organization');
    }
}
