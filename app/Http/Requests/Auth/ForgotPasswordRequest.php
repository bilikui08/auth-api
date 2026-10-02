<?php

namespace App\Http\Requests\Auth;

use App\Domain\Auth\DTO\ForgotPasswordData;
use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
        ];
    }

    public function toDto(): ForgotPasswordData
    {
        return new ForgotPasswordData((string) $this->validated('email'));
    }
}
