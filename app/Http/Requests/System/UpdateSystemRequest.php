<?php

namespace App\Http\Requests\System;

use App\Application\System\SystemSettings;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateSystemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Vacío o null = volver al nombre del .env. Sin HTML: se muestra en correos y en el login.
            'system_name' => ['present', 'nullable', 'string', 'max:' . SystemSettings::NAME_MAX, 'not_regex:/[<>\x00-\x1F]/'],
        ];
    }

    public function messages(): array
    {
        return ['system_name.not_regex' => 'El nombre no puede llevar HTML ni caracteres de control.'];
    }
}
