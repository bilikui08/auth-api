<?php

use App\Http\Middleware\EnsureOrganizationPermission;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\RequireCaptcha;
use App\Http\Middleware\UniformApiResponse;
use App\Domain\Auth\Exceptions\InvalidCredentials;
use App\Domain\Auth\Exceptions\OAuthClientNotConfigured;
use App\Domain\Organizations\Exceptions\OrganizationAccessDenied;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            UniformApiResponse::class,
        ]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'org.permission' => EnsureOrganizationPermission::class,
            'captcha' => RequireCaptcha::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Credenciales o refresh token inválidos: es un error del cliente (401), no una falla del servidor.
        // Las excepciones de controlador las convierte Laravel en respuesta antes de llegar al middleware
        // UniformApiResponse, por eso se resuelven acá.
        $exceptions->dontReport(InvalidCredentials::class);
        $exceptions->render(fn (InvalidCredentials $e, Request $request) => response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => null,
            'errors' => null,
        ], 401));

        // Sin empresas, o una empresa que no es de la persona: es un 403 con el motivo, no una falla del servidor.
        $exceptions->dontReport(OrganizationAccessDenied::class);
        $exceptions->render(fn (OrganizationAccessDenied $e, Request $request) => response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => null,
            'errors' => null,
        ], 403));

        $exceptions->render(fn (OAuthClientNotConfigured $e, Request $request) => response()->json([
            'success' => false,
            'message' => 'El servicio de autenticación no está disponible.',
            'data' => null,
            'errors' => null,
        ], 503));
    })->create();
