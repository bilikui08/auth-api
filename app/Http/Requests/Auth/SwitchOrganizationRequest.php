<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class SwitchOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'refresh_token' => ['required', 'string'],
            'organization_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
