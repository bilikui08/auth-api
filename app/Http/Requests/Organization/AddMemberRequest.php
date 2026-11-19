<?php

namespace App\Http\Requests\Organization;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

final class AddMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'exists:users,email'],
            'roles' => ['required', 'array', 'min:1', 'max:20'],
            'roles.*' => ['required', 'string', 'distinct', $this->assignableRole()],
        ];
    }

    public function messages(): array
    {
        return [
            'email.exists' => 'No hay ningún usuario con ese email: la persona debe registrarse primero.',
            'roles.*.in' => 'Uno de los roles no existe o no se puede asignar en una empresa.',
        ];
    }

    /** Solo roles de empresa (con permisos): los globales `admin` / `user` no. */
    public static function assignable(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! Role::query()->where('name', $value)->whereHas('permissions')->exists()) {
                $fail('Uno de los roles no existe o no se puede asignar en una empresa.');
            }
        };
    }

    private function assignableRole(): \Closure
    {
        return self::assignable();
    }
}
