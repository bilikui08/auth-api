<?php

namespace App\Http\Requests\Organization;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('organizations', 'tax_id')->ignore($organization->id)],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
