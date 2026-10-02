<?php

namespace App\Http\Controllers;

use App\Application\Auth\AuthService;
use App\Http\Requests\Auth\AuthRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LogoutRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\JsonResponse;

final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $service) {}

    public function auth(AuthRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Autenticación exitosa.',
            'data' => $this->service->authenticate($request->toDto()),
        ]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Usuario registrado correctamente.',
            'data' => $this->service->register($request->toDto()),
        ], 201);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->service->forgotPassword($request->toDto());

        return response()->json([
            'message' => 'Si el correo está registrado, recibirá un enlace para restablecer la contraseña.',
            'data' => null,
        ]);
    }

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Token renovado correctamente.',
            'data' => ['tokens' => $this->service->refresh($request->toDto())],
        ]);
    }

    public function logout(LogoutRequest $request): JsonResponse
    {
        $this->service->logout($request->toDto());

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
            'data' => null,
        ]);
    }
}
