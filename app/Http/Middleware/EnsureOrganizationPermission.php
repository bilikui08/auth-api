<?php

namespace App\Http\Middleware;

use App\Application\Organizations\OrganizationAccess;
use App\Auth\Passport\TokenOrganizations;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un permiso en la empresa con la que se emitió el token. Uso: ->middleware('org.permission:org.members.manage').
 * El permiso se consulta en la base (no en el claim del token), así que un cambio de rol vale al instante.
 * Deja la empresa en `$request->attributes->get('organization')`.
 */
final class EnsureOrganizationPermission
{
    public function __construct(private readonly TokenOrganizations $tokens, private readonly OrganizationAccess $access) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $tokenId = $user?->token()?->id;
        $organizationId = $tokenId === null ? null : $this->tokens->organizationOf((string) $tokenId);
        $organization = $organizationId === null ? null : Organization::query()->where('is_active', true)->find($organizationId);

        if ($user === null || $organization === null || ! in_array($permission, $this->access->permissionsOf($user->id, $organization->id), true)) {
            return response()->json(['message' => 'No tiene permisos para realizar esta acción.'], 403);
        }

        $request->attributes->set('organization', $organization);

        return $next($request);
    }
}
