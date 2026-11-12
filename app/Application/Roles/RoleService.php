<?php

namespace App\Application\Roles;

use App\Domain\Roles\Exceptions\RoleOperationNotAllowed;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class RoleService
{
    /** @return Collection<int, Role> */
    public function all(): Collection
    {
        return Role::query()->withCount('users')->orderBy('name')->get();
    }

    /** @param array{name: string, description?: string|null} $data */
    public function create(array $data): Role
    {
        return Role::query()->create($data);
    }

    /** @param array{name?: string, description?: string|null} $data */
    public function update(Role $role, array $data): Role
    {
        if ($role->isSystem() && isset($data['name']) && $data['name'] !== $role->name) {
            throw RoleOperationNotAllowed::systemRole($role->name);
        }

        $role->update($data);

        return $role;
    }

    public function delete(Role $role): void
    {
        if ($role->isSystem()) {
            throw RoleOperationNotAllowed::systemRole($role->name);
        }

        // La tabla pivote tiene ON DELETE CASCADE: se desasigna de todos los usuarios.
        $role->delete();
    }

    /**
     * Reemplaza TODOS los roles del usuario por la lista dada (nombres).
     *
     * @param  list<string>  $names
     */
    public function syncUserRoles(User $user, array $names): void
    {
        DB::transaction(function () use ($user, $names): void {
            if (! in_array(Role::ADMIN, $names, true)) {
                $this->assertNotLastAdmin($user);
            }

            $user->roles()->sync(Role::query()->whereIn('name', $names)->pluck('id')->all());
        });
    }

    public function attach(User $user, Role $role): void
    {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    public function detach(User $user, Role $role): void
    {
        DB::transaction(function () use ($user, $role): void {
            if ($role->name === Role::ADMIN) {
                $this->assertNotLastAdmin($user);
            }

            $user->roles()->detach($role->id);
        });
    }

    /** Impide dejar el sistema sin administradores (si $user es el ultimo). */
    private function assertNotLastAdmin(User $user): void
    {
        $admin = Role::query()->where('name', Role::ADMIN)->first();

        if ($admin === null || ! $user->roles()->whereKey($admin->id)->exists()) {
            return;
        }

        // lockForUpdate evita que dos peticiones simultaneas quiten a los dos ultimos admins.
        $admins = $admin->users()->lockForUpdate()->count();

        if ($admins <= 1) {
            throw RoleOperationNotAllowed::lastAdmin();
        }
    }
}
