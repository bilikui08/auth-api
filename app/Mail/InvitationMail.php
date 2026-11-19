<?php

namespace App\Mail;

use App\Models\Organization;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Invitación a sumarse a una empresa: lleva el enlace (con el token secreto) para crear la cuenta. */
final class InvitationMail extends Mailable
{
    public function __construct(
        public readonly Organization $organization,
        public readonly string $inviterName,
        public readonly string $acceptUrl,
        public readonly int $validDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->inviterName} te invitó a {$this->organization->name} en " . config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invitation');
    }
}
