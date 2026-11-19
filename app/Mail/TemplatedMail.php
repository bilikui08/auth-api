<?php

namespace App\Mail;

use App\Application\Mail\EmailTemplateService;
use App\Application\Mail\RenderedEmail;
use App\Application\System\SystemSettings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo cuyo contenido sale de una plantilla editable (ver EmailTemplateService). El enlace del botón lo pone el sistema.
 * El nombre del sistema (encabezado, pie y remitente) es el configurado en la aplicación.
 */
abstract class TemplatedMail extends Mailable
{
    /** Borrador para la vista previa y la prueba de envío (sin guardar). @var array<string, string>|null */
    public ?array $draft = null;

    private ?RenderedEmail $rendered = null;

    public function __construct()
    {
        $system = app(SystemSettings::class);
        $system->applyToMail();
        $this->from((string) config('mail.from.address'), $system->systemName());
    }

    abstract protected function templateKey(): string;

    /** @return array<string, string> */
    abstract protected function variables(): array;

    abstract protected function buttonUrl(): string;

    public function rendered(): RenderedEmail
    {
        return $this->rendered ??= app(EmailTemplateService::class)->render($this->templateKey(), $this->variables(), $this->draft);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->rendered()->subject);
    }

    public function content(): Content
    {
        $mail = $this->rendered();

        return new Content(markdown: 'mail.templated', with: [
            'heading' => $mail->heading,
            'bodyMarkdown' => $mail->bodyMarkdown,
            'buttonLabel' => $mail->buttonLabel,
            'buttonUrl' => $this->buttonUrl(),
        ]);
    }
}
