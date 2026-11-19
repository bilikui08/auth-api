<?php

namespace App\Http\Controllers;

use App\Application\Auth\AuthService;
use App\Http\Requests\Auth\AuthRequest;
use App\Http\Requests\Auth\CheckResetTokenRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\LogoutRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Requests\Auth\SelectOrganizationRequest;
use App\Http\Requests\Auth\SwitchOrganizationRequest;
use App\Application\Organizations\OrganizationAccess;
use App\Auth\Passport\TokenOrganizations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $service,
        private readonly OrganizationAccess $access,
        private readonly TokenOrganizations $tokenOrganizations,
    ) {}

    public function login(AuthRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Autenticación exitosa.',
            'data' => $this->service->authenticate($request->toDto()),
        ]);
    }

    /** Segundo paso del login con varias empresas: elige una y recibe los tokens. */
    public function selectOrganization(SelectOrganizationRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Autenticación exitosa.',
            'data' => $this->service->selectOrganization(
                (string) $request->validated('selection_token'),
                (int) $request->validated('organization_id'),
            ),
        ]);
    }

    /** Cambia de empresa con el refresh token de la sesión. */
    public function switchOrganization(SwitchOrganizationRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Empresa cambiada correctamente.',
            'data' => $this->service->switchOrganization(
                (string) $request->validated('refresh_token'),
                (int) $request->validated('organization_id'),
            ),
        ]);
    }

    /** Empresas con las que puede trabajar la persona autenticada (y cuál es la actual). */
    public function organizations(Request $request): JsonResponse
    {
        $current = $this->currentOrganizationId($request);

        return response()->json([
            'message' => 'Empresas del usuario.',
            'data' => [
                'current' => $current,
                'organizations' => $this->service->organizationsOf($request->user()->id),
            ],
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->service->forgotPassword($request->toDto());

        return response()->json([
            'message' => 'Si el correo está registrado, recibirá un enlace para restablecer la contraseña.',
            'data' => null,
        ]);
    }

    /** Para que la pantalla avise antes de pedir la contraseña nueva si el enlace ya no sirve. */
    public function checkPasswordReset(CheckResetTokenRequest $request): JsonResponse
    {
        $this->service->checkPasswordReset((string) $request->validated('email'), (string) $request->validated('token'));

        return response()->json(['message' => 'El enlace es válido.', 'data' => null]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->service->resetPassword($request->toDto());

        return response()->json(['message' => 'Contraseña actualizada. Ya podés ingresar con la nueva.', 'data' => null]);
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

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Usuario autenticado.',
            // Los roles van como lista de nombres: es lo que consume el API Gateway.
            'data' => [
                ...$request->user()->toArray(),
                'roles' => $request->user()->roleNames(),
                ...$this->organizationData($request),
            ],
        ]);
    }

    private function currentOrganizationId(Request $request): ?int
    {
        $tokenId = $request->user()->token()?->id;

        return $tokenId === null ? null : $this->tokenOrganizations->organizationOf((string) $tokenId);
    }

    /** @return array{organization_id: int|null, permissions: list<string>} */
    private function organizationData(Request $request): array
    {
        $organizationId = $this->currentOrganizationId($request);

        return [
            'organization_id' => $organizationId,
            'permissions' => $organizationId === null ? [] : $this->access->permissionsOf($request->user()->id, $organizationId),
        ];
    }
}
