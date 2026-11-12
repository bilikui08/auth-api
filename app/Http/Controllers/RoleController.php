<?php

namespace App\Http\Controllers;

use App\Application\Roles\RoleService;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

final class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'message' => 'Listado de roles.',
            'data' => $this->roles->all()->map(fn (Role $role): array => $this->present($role))->all(),
        ]);
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json([
            'message' => 'Rol encontrado.',
            'data' => $this->present($role->loadCount('users')),
        ]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create($request->validated());

        return response()->json([
            'message' => 'Rol creado correctamente.',
            'data' => $this->present($role),
        ], 201);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $role = $this->roles->update($role, $request->validated());

        return response()->json([
            'message' => 'Rol actualizado correctamente.',
            'data' => $this->present($role->loadCount('users')),
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->roles->delete($role);

        return response()->json(['message' => 'Rol eliminado correctamente.', 'data' => null]);
    }

    /** @return array<string, mixed> */
    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'is_system' => $role->isSystem(),
            'users_count' => $role->users_count ?? null,
            'created_at' => $role->created_at,
            'updated_at' => $role->updated_at,
        ];
    }
}
