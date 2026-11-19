<?php

namespace App\Http\Controllers;

use App\Application\Auth\AuthService;
use App\Application\Security\TwoFactorService;
use App\Http\Requests\TwoFactor\ChallengeRequest;
use App\Http\Requests\TwoFactor\CodeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Verificación en dos pasos. Dos grupos de rutas:
 * - del ingreso (públicas): trabajan con el permiso temporal que se obtiene al validar la contraseña;
 * - de la cuenta (con sesión): estado, activar, apagar y regenerar los códigos de recuperación.
 */
final class TwoFactorController extends Controller
{
    public function __construct(private readonly AuthService $auth, private readonly TwoFactorService $twoFactor) {}

    // ---------------------------------------------------------------- ingreso

    /** Primer ingreso: el secreto y la dirección del QR para configurar la aplicación. */
    public function loginSetup(ChallengeRequest $request): JsonResponse
    {
        return response()->json(['message' => 'Configurá tu aplicación de autenticación.', 'data' => $this->auth->twoFactorSetup((string) $request->validated('challenge_token'))]);
    }

    /** Confirma la configuración con el primer código; devuelve los códigos de recuperación y sigue el ingreso. */
    public function loginConfirm(ChallengeRequest $request): JsonResponse
    {
        $request->validate(['code' => ['required']]);

        return response()->json([
            'message' => 'Verificación en dos pasos activada.',
            'data' => $this->auth->twoFactorConfirm((string) $request->validated('challenge_token'), (string) $request->validated('code')),
        ]);
    }

    /** Verifica el código (de la aplicación o de recuperación) y sigue el ingreso. */
    public function loginVerify(ChallengeRequest $request): JsonResponse
    {
        $request->validate(['code' => ['required']]);

        return response()->json([
            'message' => 'Autenticación exitosa.',
            'data' => $this->auth->twoFactorVerify((string) $request->validated('challenge_token'), (string) $request->validated('code')),
        ]);
    }

    // ---------------------------------------------------------------- cuenta

    public function status(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Verificación en dos pasos.', 'data' => $this->twoFactor->status($request->user())]);
    }

    /** Vuelve a activarla: prepara un secreto nuevo (todavía sin confirmar). */
    public function enable(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Configurá tu aplicación de autenticación.', 'data' => $this->twoFactor->beginSetup($request->user())]);
    }

    public function confirmEnable(CodeRequest $request): JsonResponse
    {
        $codes = $this->twoFactor->confirmSetup($request->user(), (string) $request->validated('code'));

        return response()->json(['message' => 'Verificación en dos pasos activada.', 'data' => ['recovery_codes' => $codes] + $this->twoFactor->status($request->user())]);
    }

    public function disable(CodeRequest $request): JsonResponse
    {
        $this->twoFactor->disable($request->user(), (string) $request->validated('password'), (string) $request->validated('code'));

        return response()->json(['message' => 'Verificación en dos pasos desactivada.', 'data' => $this->twoFactor->status($request->user())]);
    }

    public function recoveryCodes(CodeRequest $request): JsonResponse
    {
        $codes = $this->twoFactor->regenerateRecoveryCodes($request->user(), (string) $request->validated('code'));

        return response()->json(['message' => 'Códigos de recuperación nuevos. Los anteriores ya no sirven.', 'data' => ['recovery_codes' => $codes]]);
    }
}
