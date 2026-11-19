<?php

namespace App\Http\Requests\Auth;

use App\Domain\Auth\DTO\ResetPasswordData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    public function toDto(): ResetPasswordData
    {
        return new ResetPasswordData(
            (string) $this->validated('email'),
            (string) $this->validated('token'),
            (string) $this->validated('password'),
        );
    }
}
