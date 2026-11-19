<?php

namespace App\Http\Requests\System;

use App\Application\Mail\EmailTemplateRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida una plantilla de correo. El texto no puede llevar enlaces ni HTML (el enlace del botón lo pone el sistema: así una plantilla
 * editada no puede mandar a la gente a un sitio ajeno desde tu remitente) y solo puede usar las variables que la plantilla admite.
 */
final class SaveEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return EmailTemplateRegistry::exists((string) $this->route('key'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $key = (string) $this->route('key');
        $check = $this->check($key);

        return [
            'subject' => ['required', 'string', 'max:150', 'not_regex:/[\r\n]/', $check],
            'heading' => ['required', 'string', 'max:120', 'not_regex:/[\r\n]/', $check],
            'body' => ['required', 'string', 'max:4000', $check],
            'button_label' => ['required', 'string', 'max:40', 'not_regex:/[\r\n]/', $check],
        ];
    }

    public function messages(): array
    {
        return [
            'subject.not_regex' => 'El asunto debe ir en una sola línea.',
            'heading.not_regex' => 'El título debe ir en una sola línea.',
            'button_label.not_regex' => 'El botón debe ir en una sola línea.',
        ];
    }

    private function check(string $key): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($key): void {
            if (! is_string($value) || ! EmailTemplateRegistry::exists($key)) {
                return;
            }

            if (preg_match('/https?:|www\.|javascript:|data:|<|>/i', $value)) {
                $fail('No puede incluir enlaces ni HTML: el enlace del botón lo agrega el sistema.');

                return;
            }

            $allowed = array_keys(EmailTemplateRegistry::definition($key)['variables']);
            // Todo lo que va entre llaves debe ser una variable válida (también una mal escrita, como {Invitador} o {contraseña})
            preg_match_all('/\{([^{}]*)\}/', $value, $found);
            $unknown = array_values(array_unique(array_diff($found[1], $allowed)));

            if ($unknown !== []) {
                $fail('Variable desconocida: {' . implode('}, {', $unknown) . '}. Podés usar: {' . implode('}, {', $allowed) . '}.');
            }
        };
    }
}
