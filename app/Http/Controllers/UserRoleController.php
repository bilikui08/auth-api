<?php

namespace App\Http\Controllers;

use App\Application\Roles\RoleService;
use App\Http\Requests\Role\SyncUserRolesRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class UserRoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(User $user): JsonResponse
    {
        return $this->respond($user, 'Roles del usuario.');
    }

    /** Reemplaza todos los roles del usuario por los indicados (por nombre). */
    public function sync(SyncUserRolesRequest $request, User $user): JsonResponse
    {
        $this->roles->syncUserRoles($user, $request->validated('roles'));

        return $this->respond($user, 'Roles del usuario actualizados.');
    }

    public function attach(User $user, Role $role): JsonResponse
    {
        $this->roles->attach($user, $role);

        return $this->respond($user, 'Rol asignado correctamente.');
    }

    public function detach(User $user, Role $role): JsonResponse
    {
        $this->roles->detach($user, $role);

        return $this->respond($user, 'Rol retirado correctamente.');
    }

    private function respond(User $user, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => ['user_id' => $user->id, 'email' => $user->email, 'roles' => $user->roleNames()],
        ]);
    }
}
