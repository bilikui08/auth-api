<?php

namespace App\Http\Requests\TwoFactor;

use Illuminate\Foundation\Http\FormRequest;

/** Acciones de la cuenta ya iniciada: siempre llevan un código vigente y, para apagarlo, también la contraseña. */
final class CodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
            'password' => [$this->routeIs('*two-factor.disable') ? 'required' : 'prohibited', 'string', 'max:255'],
        ];
    }
}
