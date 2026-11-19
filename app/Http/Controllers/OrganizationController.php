<?php

namespace App\Http\Controllers;

use App\Application\Organizations\MemberService;
use App\Http\Requests\Organization\AddMemberRequest;
use App\Http\Requests\Organization\SetMemberRolesRequest;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Administración de la empresa activa del token: sus personas y los roles de cada una (permiso `org.members.manage`). */
final class OrganizationController extends Controller
{
    public function __construct(private readonly MemberService $members) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Empresa.', 'data' => $this->present($this->organization($request))]);
    }

    public function members(Request $request): JsonResponse
    {
        return $this->respondMembers($request, 'Personas de la empresa.');
    }

    public function addMember(AddMemberRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->firstOrFail();
        $this->members->add($this->organization($request), $user, $request->validated('roles'));

        return $this->respondMembers($request, 'Persona agregada a la empresa.', 201);
    }

    public function setMemberRoles(SetMemberRolesRequest $request, User $user): JsonResponse
    {
        $this->members->setRoles($this->organization($request), $user, $request->validated('roles'));

        return $this->respondMembers($request, 'Roles actualizados.');
    }

    public function removeMember(Request $request, User $user): JsonResponse
    {
        $this->members->remove($this->organization($request), $user);

        return $this->respondMembers($request, 'Persona quitada de la empresa.');
    }

    /** Roles que se pueden asignar, con sus permisos (para armar el selector de roles). */
    public function roles(): JsonResponse
    {
        return response()->json([
            'message' => 'Roles de empresa.',
            'data' => $this->members->assignableRoles()->map(fn (Role $role): array => [
                'name' => $role->name,
                'description' => $role->description,
                'permissions' => $role->permissions->sortBy('name')->map(fn ($p): array => ['name' => $p->name, 'description' => $p->description])->values()->all(),
            ])->all(),
        ]);
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    private function respondMembers(Request $request, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => $this->members->members($this->organization($request))], $status);
    }

    /** @return array<string, mixed> */
    private function present(Organization $organization): array
    {
        return ['id' => $organization->id, 'name' => $organization->name, 'tax_id' => $organization->tax_id, 'is_active' => $organization->is_active];
    }
}
