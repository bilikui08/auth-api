<?php

namespace App\Http\Requests\TwoFactor;

use Illuminate\Foundation\Http\FormRequest;

/** Pasos del ingreso con 2FA: el permiso temporal que se obtuvo al validar la contraseña, y (según el paso) el código. */
final class ChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:32'],
        ];
    }
}
