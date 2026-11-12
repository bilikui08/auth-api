<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

final class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Minusculas, numeros, "-" y "_": son identificadores usados en reglas del gateway.
            'name' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'unique:roles,name'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
