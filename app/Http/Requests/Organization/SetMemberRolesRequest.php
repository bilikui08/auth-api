<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

final class SetMemberRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1', 'max:20'],
            'roles.*' => ['required', 'string', 'distinct', AddMemberRequest::assignable()],
        ];
    }
}
