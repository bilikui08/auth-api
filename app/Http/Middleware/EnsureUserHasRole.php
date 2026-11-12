<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Uso: ->middleware('role:admin') o 'role:admin,auditor' (basta con uno). */
final class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->hasAnyRole(...$roles)) {
            return response()->json(['message' => 'No tiene permisos para realizar esta acción.'], 403);
        }

        return $next($request);
    }
}
