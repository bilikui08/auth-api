<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

final class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'tax_id' => ['nullable', 'string', 'max:20', 'unique:organizations,tax_id'],
            'admin_email' => ['required', 'email', 'max:255', 'exists:users,email'],
        ];
    }

    public function messages(): array
    {
        return ['admin_email.exists' => 'No hay ningún usuario con ese email: la persona debe registrarse primero.'];
    }
}
