<?php

namespace App\Http\Controllers;

use App\Application\Organizations\MemberService;
use App\Http\Requests\Organization\StoreOrganizationRequest;
use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Administración de TODAS las empresas: solo para el administrador del sistema (rol global `admin`). */
final class AdminOrganizationController extends Controller
{
    public function __construct(private readonly MemberService $members) {}

    public function index(): JsonResponse
    {
        $counts = DB::table('organization_user')->select('organization_id', DB::raw('COUNT(DISTINCT user_id) as members'))->groupBy('organization_id')->pluck('members', 'organization_id');

        return response()->json([
            'message' => 'Empresas.',
            'data' => Organization::query()->orderBy('name')->get()
                ->map(fn (Organization $o): array => $this->present($o) + ['members_count' => (int) ($counts[$o->id] ?? 0)])->all(),
        ]);
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $admin = User::query()->where('email', $request->validated('admin_email'))->firstOrFail();
        $organization = $this->members->createOrganization($request->validated('name'), $request->validated('tax_id'), $admin);

        return response()->json(['message' => 'Empresa creada correctamente.', 'data' => $this->present($organization)], 201);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): JsonResponse
    {
        $organization->update($request->validated());

        return response()->json(['message' => 'Empresa actualizada correctamente.', 'data' => $this->present($organization)]);
    }

    /** Búsqueda de usuarios por nombre o email (para elegir al administrador de una empresa nueva). */
    public function users(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('search', ''));
        $like = '%' . addcslashes($term, '\\%_') . '%';

        return response()->json([
            'message' => 'Usuarios.',
            'data' => User::query()
                ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)))
                ->orderBy('name')->limit(20)->get(['id', 'name', 'email'])->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Organization $organization): array
    {
        return ['id' => $organization->id, 'name' => $organization->name, 'tax_id' => $organization->tax_id, 'is_active' => $organization->is_active];
    }
}
