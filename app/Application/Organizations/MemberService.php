<?php

namespace App\Application\Organizations;

use App\Domain\Organizations\Exceptions\OrganizationOperationNotAllowed;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Personas de una empresa y los roles que tienen allí. Reglas:
 * - Solo se asignan roles de empresa (los que tienen permisos), nunca los globales `admin` / `user`.
 * - Una persona pertenece a la empresa mientras tenga al menos un rol allí.
 * - La empresa nunca se queda sin administrador (`company_admin`).
 */
final class MemberService
{
    /**
     * @return list<array{user_id: int, name: string, email: string, roles: list<string>}>
     */
    public function members(Organization $organization): array
    {
        $rows = DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->join('roles', 'roles.id', '=', 'organization_user.role_id')
            ->where('organization_user.organization_id', $organization->id)
            ->orderBy('users.name')
            ->orderBy('roles.name')
            ->get(['users.id as user_id', 'users.name', 'users.email', 'roles.name as role']);

        return $rows->groupBy('user_id')->map(fn ($group) => [
            'user_id' => (int) $group->first()->user_id,
            'name' => $group->first()->name,
            'email' => $group->first()->email,
            'roles' => $group->pluck('role')->values()->all(),
        ])->values()->all();
    }

    /**
     * Roles que se pueden asignar en una empresa, con sus permisos.
     *
     * @return Collection<int, Role>
     */
    public function assignableRoles(): Collection
    {
        return Role::query()->whereHas('permissions')->with('permissions')->orderBy('name')->get();
    }

    /** @param list<string> $roles */
    public function add(Organization $organization, User $user, array $roles): void
    {
        DB::transaction(function () use ($organization, $user, $roles): void {
            if ($this->isMember($organization, $user)) {
                throw OrganizationOperationNotAllowed::alreadyMember();
            }

            $this->attach($organization, $user, $roles);
        });
    }

    /**
     * Reemplaza TODOS los roles de la persona en la empresa.
     *
     * @param list<string> $roles
     */
    public function setRoles(Organization $organization, User $user, array $roles): void
    {
        DB::transaction(function () use ($organization, $user, $roles): void {
            $this->assertMember($organization, $user);

            if (! in_array(PermissionCatalog::COMPANY_ADMIN, $roles, true)) {
                $this->assertNotLastAdmin($organization, $user);
            }

            $this->detachAll($organization, $user);
            $this->attach($organization, $user, $roles);
        });
    }

    public function remove(Organization $organization, User $user): void
    {
        DB::transaction(function () use ($organization, $user): void {
            $this->assertMember($organization, $user);
            $this->assertNotLastAdmin($organization, $user);

            $this->detachAll($organization, $user);
            // Las sesiones abiertas en esa empresa dejan de renovarse (el refresh revalida la pertenencia)
        });
    }

    /** Empresa nueva con su primer administrador (una persona que ya existe). */
    public function createOrganization(string $name, ?string $taxId, User $admin): Organization
    {
        return DB::transaction(function () use ($name, $taxId, $admin): Organization {
            $organization = Organization::query()->create(['name' => $name, 'tax_id' => $taxId, 'is_active' => true]);
            $this->attach($organization, $admin, [PermissionCatalog::COMPANY_ADMIN]);

            return $organization;
        });
    }

    private function isMember(Organization $organization, User $user): bool
    {
        return DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $user->id)->exists();
    }

    private function assertMember(Organization $organization, User $user): void
    {
        if (! $this->isMember($organization, $user)) {
            throw OrganizationOperationNotAllowed::notAMember();
        }
    }

    /** @param list<string> $names */
    private function attach(Organization $organization, User $user, array $names): void
    {
        $roleIds = Role::query()->whereIn('name', $names)->whereHas('permissions')->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('organization_user')->insert([
                'organization_id' => $organization->id, 'user_id' => $user->id, 'role_id' => $roleId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function detachAll(Organization $organization, User $user): void
    {
        DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $user->id)->delete();
    }

    /** Impide dejar la empresa sin administrador si $user es el último (lockForUpdate: dos pedidos simultáneos no lo evaden). */
    private function assertNotLastAdmin(Organization $organization, User $user): void
    {
        $adminRoleId = Role::query()->where('name', PermissionCatalog::COMPANY_ADMIN)->value('id');

        $admins = DB::table('organization_user')
            ->where('organization_id', $organization->id)
            ->where('role_id', $adminRoleId)
            ->lockForUpdate()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);

        if ($admins->contains($user->id) && $admins->count() <= 1) {
            throw OrganizationOperationNotAllowed::lastAdmin();
        }
    }
}
