<?php

namespace App\Application\Roles;

use App\Application\Organizations\PermissionCatalog;
use App\Domain\Organizations\Exceptions\OrganizationOperationNotAllowed;
use App\Domain\Roles\Exceptions\RoleOperationNotAllowed;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class RoleService
{
    /**
     * Roles con cuántas personas y empresas los usan (`usage`): lo que importa para borrarlos o cambiarles los permisos.
     *
     * @return Collection<int, Role>
     */
    public function all(): Collection
    {
        $usage = DB::table('organization_user')
            ->select('role_id', DB::raw('COUNT(DISTINCT user_id) AS members'), DB::raw('COUNT(DISTINCT organization_id) AS organizations'))
            ->groupBy('role_id')
            ->get()
            ->keyBy('role_id');

        return Role::query()->withCount('users')->orderBy('name')->get()->each(function (Role $role) use ($usage): void {
            $role->setAttribute('members_count', (int) ($usage[$role->id]->members ?? 0));
            $role->setAttribute('organizations_count', (int) ($usage[$role->id]->organizations ?? 0));
        });
    }

    /** Uso de un rol en las empresas: personas distintas y empresas distintas. @return array{members: int, organizations: int} */
    public function usage(Role $role): array
    {
        $row = DB::table('organization_user')->where('role_id', $role->id)
            ->selectRaw('COUNT(DISTINCT user_id) AS members, COUNT(DISTINCT organization_id) AS organizations')->first();

        return ['members' => (int) ($row->members ?? 0), 'organizations' => (int) ($row->organizations ?? 0)];
    }

    /** @param array{name: string, description?: string|null, permissions?: list<string>} $data */
    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data): Role {
            $permissions = $data['permissions'] ?? [];
            unset($data['permissions']);

            $role = Role::query()->create($data);
            $this->syncPermissions($role, $permissions);

            return $role;
        });
    }

    /** @param array{name?: string, description?: string|null, permissions?: list<string>} $data */
    public function update(Role $role, array $data): Role
    {
        if ($role->isSystem() && isset($data['name']) && $data['name'] !== $role->name) {
            throw RoleOperationNotAllowed::systemRole($role->name);
        }

        // El rol del dueño de la empresa conserva todos los permisos: si se le pudieran quitar, nadie podría administrar
        if (array_key_exists('permissions', $data) && $role->name === PermissionCatalog::COMPANY_ADMIN) {
            throw OrganizationOperationNotAllowed::protectedRole($role->name);
        }

        if (array_key_exists('permissions', $data)) {
            // `admin` y `user` son roles globales (`user` lo tiene toda cuenta): darles permisos de empresa sería confuso
            if (in_array($role->name, [Role::ADMIN, Role::USER], true) && $data['permissions'] !== []) {
                throw RoleOperationNotAllowed::globalRole($role->name);
            }

            // Quienes tienen el rol quedarían sin poder hacer nada en su empresa
            if ($data['permissions'] === [] && $this->usage($role)['members'] > 0) {
                throw RoleOperationNotAllowed::emptyRoleInUse($role->name);
            }
        }

        return DB::transaction(function () use ($role, $data): Role {
            $permissions = $data['permissions'] ?? null;
            unset($data['permissions']);

            $role->update($data);

            if ($permissions !== null) {
                $this->syncPermissions($role, $permissions);
            }

            return $role;
        });
    }

    /** @param list<string> $names */
    private function syncPermissions(Role $role, array $names): void
    {
        $role->permissions()->sync(Permission::query()->whereIn('name', $names)->pluck('id')->all());
    }

    public function delete(Role $role): void
    {
        if ($role->isSystem()) {
            throw RoleOperationNotAllowed::systemRole($role->name);
        }

        // En una empresa NO se borra: la asignación se borraría en cascada y esas personas quedarían fuera de la empresa sin aviso
        $usage = $this->usage($role);

        if ($usage['members'] > 0) {
            throw RoleOperationNotAllowed::inUse($role->name, $usage['members'], $usage['organizations']);
        }

        // La asignación global (role_user) tiene ON DELETE CASCADE: se desasigna de todos los usuarios.
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
