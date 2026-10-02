<?php

namespace App\Http\Requests\Auth;

use App\Domain\Auth\DTO\AuthData;
use Illuminate\Foundation\Http\FormRequest;

final class AuthRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255', 'required_without:email', 'prohibits:email'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:name', 'prohibits:name'],
            'password' => ['required', 'string'],
        ];
    }

    public function toDto(): AuthData
    {
        return new AuthData(
            identifier: (string) ($this->validated('email') ?? $this->validated('name')),
            password: (string) $this->validated('password'),
        );
    }
}
