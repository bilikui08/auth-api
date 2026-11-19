<?php

namespace App\Application\Mail;

/** Un correo ya armado a partir de su plantilla. */
final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $heading,
        public string $bodyMarkdown,
        public string $buttonLabel,
    ) {}
}
