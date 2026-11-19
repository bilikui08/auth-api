<?php

namespace App\Http\Controllers;

use App\Application\Auth\AuthService;
use App\Application\Organizations\InvitationService;
use App\Http\Requests\Organization\AcceptInvitationRequest;
use Illuminate\Http\JsonResponse;

/** Pantalla pública de aceptación: el token del enlace es la única credencial (por eso va limitada por frecuencia). */
final class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations, private readonly AuthService $auth) {}

    public function show(string $token): JsonResponse
    {
        return response()->json(['message' => 'Invitación.', 'data' => $this->invitations->describe($token)]);
    }

    public function accept(AcceptInvitationRequest $request, string $token): JsonResponse
    {
        $result = $this->invitations->accept($token, (string) $request->validated('name'), (string) $request->validated('password'));

        if (! $result['created']) {
            // El email ya tenía cuenta: queda sumada a la empresa, pero entrar requiere su contraseña de siempre
            return response()->json([
                'message' => 'Ese email ya tenía una cuenta: ahora también pertenece a la empresa. Ingresá con tu contraseña.',
                'data' => ['status' => 'existing_user'],
            ]);
        }

        return response()->json([
            'message' => 'Cuenta creada correctamente.',
            'data' => ['status' => 'created'] + $this->auth->startSession($result['user'], $result['organization']),
        ], 201);
    }
}
