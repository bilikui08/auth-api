<?php

namespace App\Http\Controllers;

use App\Application\Organizations\InvitationService;
use App\Application\Organizations\MemberService;
use App\Http\Requests\Organization\AddMemberRequest;
use App\Http\Requests\Organization\InviteRequest;
use App\Http\Requests\Organization\SetMemberRolesRequest;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Administración de la empresa activa del token: sus personas y los roles de cada una (permiso `org.members.manage`). */
final class OrganizationController extends Controller
{
    public function __construct(private readonly MemberService $members, private readonly InvitationService $invitations) {}

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

    /**
     * Suma a una persona por su email: si ya tiene cuenta entra al instante (y se le avisa); si no, se le manda una invitación
     * por email. `email_sent` dice si el correo salió (si no, se puede reinvitar).
     */
    public function invite(InviteRequest $request): JsonResponse
    {
        $result = $this->invitations->invite($this->organization($request), $request->user(), $request->validated('email'), $request->validated('roles'));

        return response()->json([
            'message' => $result['status'] === 'added' ? 'La persona ya tenía cuenta: se la sumó a la empresa.' : 'Invitación enviada.',
            'data' => $result + [
                'members' => $this->members->members($this->organization($request)),
                'invitations' => $this->invitations->pending($this->organization($request)),
            ],
        ], 201);
    }

    public function invitations(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Invitaciones pendientes.', 'data' => $this->invitations->pending($this->organization($request))]);
    }

    public function revokeInvitation(Request $request, int $invitation): JsonResponse
    {
        $this->invitations->revoke($this->organization($request), $invitation);

        return response()->json(['message' => 'Invitación cancelada.', 'data' => $this->invitations->pending($this->organization($request))]);
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
