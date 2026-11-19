<?php

namespace App\Application\Organizations;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Con qué empresas puede trabajar una persona y qué permisos tiene en cada una. */
final class OrganizationAccess
{
    /**
     * Empresas activas a las que pertenece la persona, por nombre.
     *
     * @return Collection<int, Organization>
     */
    public function organizationsOf(int $userId): Collection
    {
        return Organization::query()
            ->where('is_active', true)
            ->whereIn('id', DB::table('organization_user')->where('user_id', $userId)->select('organization_id'))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function isMember(int $userId, int $organizationId): bool
    {
        return $this->organizationsOf($userId)->contains('id', $organizationId);
    }

    /**
     * Permisos de la persona en la empresa: la unión de los de todos sus roles allí.
     *
     * @return list<string>
     */
    public function permissionsOf(int $userId, int $organizationId): array
    {
        return DB::table('organization_user')
            ->join('permission_role', 'permission_role.role_id', '=', 'organization_user.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('organization_user.user_id', $userId)
            ->where('organization_user.organization_id', $organizationId)
            ->distinct()
            ->orderBy('permissions.name')
            ->pluck('permissions.name')
            ->all();
    }

    /** Empresa propia de un usuario nuevo: queda como su administrador. */
    public function createPersonalOrganization(User $user): Organization
    {
        $organization = Organization::query()->create(['name' => $user->name]);

        $roleId = Role::query()->where('name', PermissionCatalog::COMPANY_ADMIN)->value('id');

        if ($roleId !== null) {
            $organization->users()->attach($user->id, ['role_id' => $roleId]);
        }

        return $organization;
    }
}
