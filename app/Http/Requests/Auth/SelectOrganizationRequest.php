<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class SelectOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'selection_token' => ['required', 'string', 'max:255'],
            'organization_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
