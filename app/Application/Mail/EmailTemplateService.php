<?php

namespace App\Application\Mail;

use App\Application\System\SystemSettings;
use App\Models\EmailTemplate;
use Throwable;

/**
 * Plantillas de correo: el original está en el código (EmailTemplateRegistry) y la administración del sistema puede guardar una
 * versión propia. Si una versión propia falla al armarse, se usa el original: nunca se deja de mandar un correo (ej. una
 * invitación) por un error de edición.
 */
class EmailTemplateService
{
    public function __construct(private readonly SystemSettings $system) {}

    /** @return array{subject: string, heading: string, body: string, button_label: string} */
    public function defaults(string $key): array
    {
        return EmailTemplateRegistry::definition($key)['defaults'];
    }

    /**
     * Lo vigente: la versión propia si existe, o el original.
     *
     * @return array{subject: string, heading: string, body: string, button_label: string}
     */
    public function current(string $key): array
    {
        $custom = EmailTemplate::query()->where('key', $key)->first(['subject', 'heading', 'body', 'button_label']);

        return $custom === null ? $this->defaults($key) : $custom->only(['subject', 'heading', 'body', 'button_label']);
    }

    public function isCustomized(string $key): bool
    {
        return EmailTemplate::query()->where('key', $key)->exists();
    }

    /**
     * Arma el correo. `$draft` permite previsualizar un borrador sin guardarlo.
     *
     * @param array<string, string> $variables  Sin `sistema`: lo agrega el servicio (nombre vigente del sistema).
     * @param array<string, string>|null $draft
     */
    public function render(string $key, array $variables, ?array $draft = null): RenderedEmail
    {
        $variables += ['sistema' => $this->system->systemName()];

        try {
            return $this->build($draft ?? $this->current($key), $variables);
        } catch (Throwable $e) {
            report($e);

            return $this->build($this->defaults($key), $variables);
        }
    }

    /** Vista previa con datos de ejemplo. */
    public function preview(string $key, ?array $draft = null): RenderedEmail
    {
        $samples = EmailTemplateRegistry::definition($key)['samples'];

        return $this->render($key, array_filter($samples, fn ($v, $k) => $k !== 'sistema', ARRAY_FILTER_USE_BOTH), $draft);
    }

    /** @param array<string, string> $data */
    public function save(string $key, array $data, int $userId): void
    {
        EmailTemplate::query()->updateOrCreate(['key' => $key], [
            'subject' => $data['subject'], 'heading' => $data['heading'], 'body' => $data['body'],
            'button_label' => $data['button_label'], 'updated_by' => $userId,
        ]);
    }

    /** Vuelve al original. */
    public function restore(string $key): void
    {
        EmailTemplate::query()->where('key', $key)->delete();
    }

    /**
     * @param array<string, string> $template
     * @param array<string, string> $variables
     */
    private function build(array $template, array $variables): RenderedEmail
    {
        return new RenderedEmail(
            subject: TemplateRenderer::line($template['subject'], $variables),
            heading: TemplateRenderer::line($template['heading'], $variables),
            bodyMarkdown: TemplateRenderer::markdown($template['body'], $variables),
            buttonLabel: TemplateRenderer::line($template['button_label'], $variables),
        );
    }
}
