<?php

namespace App\Application\Auth;

use App\Domain\Auth\Exceptions\PasswordResetNotValid;
use App\Application\Organizations\OrganizationAccess;
use App\Application\Security\TwoFactorService;
use App\Auth\Passport\SelectionTokens;
use App\Auth\Passport\TwoFactorChallenges;
use App\Domain\Auth\Contracts\AuthRepository;
use App\Domain\Auth\Contracts\TokenRepository;
use App\Domain\Auth\DTO\AuthData;
use App\Domain\Auth\DTO\ForgotPasswordData;
use App\Domain\Auth\DTO\LogoutData;
use App\Domain\Auth\DTO\RefreshTokenData;
use App\Domain\Auth\Exceptions\InvalidCredentials;
use App\Domain\Organizations\Exceptions\OrganizationAccessDenied;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final readonly class AuthService
{
    public function __construct(
        private AuthRepository $users,
        private TokenRepository $tokens,
        private OrganizationAccess $access,
        private SelectionTokens $selections,
        private TwoFactorService $twoFactor,
        private TwoFactorChallenges $challenges,
    ) {}

    /**
     * Primer paso del login: valida usuario y contraseña. Si la persona tiene la verificación en dos pasos activa (lo normal) NO
     * emite nada todavía: devuelve un permiso temporal para el paso siguiente (verificar el código, o configurar la aplicación si
     * es la primera vez). Sin 2FA sigue como antes: con una sola empresa entra directo; con varias devuelve las empresas.
     *
     * @return array<string, mixed>
     */
    public function authenticate(AuthData $data): array
    {
        $user = $this->users->findByIdentifier($data->identifier);

        if ($user === null || ! Hash::check($data->password, $user->password)) {
            throw new InvalidCredentials;
        }

        $organizations = $this->access->organizationsOf($user->id);

        if ($organizations->isEmpty()) {
            throw OrganizationAccessDenied::noOrganizations();
        }

        if ($this->twoFactor->required($user)) {
            return [
                'requires_two_factor' => true,
                'two_factor' => $this->twoFactor->isConfirmed($user) ? 'verify' : 'setup',
                'challenge_token' => $this->challenges->create($user->id),
                'challenge_expires_in' => TwoFactorChallenges::TTL_SECONDS,
            ];
        }

        return $this->proceed($user);
    }

    /** Datos para configurar la aplicación de autenticación (primer ingreso): el secreto y la dirección del QR. @return array{secret: string, uri: string} */
    public function twoFactorSetup(string $challengeToken): array
    {
        $user = $this->challengedUser($challengeToken);

        if ($this->twoFactor->isConfirmed($user)) {
            throw new InvalidCredentials('La verificación en dos pasos ya está configurada.');
        }

        return $this->twoFactor->beginSetup($user);
    }

    /**
     * Confirma la configuración con el primer código y sigue el ingreso. Devuelve además los códigos de recuperación (única vez que se ven).
     *
     * @return array<string, mixed>
     */
    public function twoFactorConfirm(string $challengeToken, string $code): array
    {
        $user = $this->challengedUser($challengeToken);
        $codes = $this->attempt($challengeToken, fn () => $this->twoFactor->confirmSetup($user, $code));
        $this->challenges->consume($challengeToken);

        return ['recovery_codes' => $codes] + $this->proceed($user);
    }

    /**
     * Verifica el código (de la aplicación o de recuperación) y sigue el ingreso.
     *
     * @return array<string, mixed>
     */
    public function twoFactorVerify(string $challengeToken, string $code): array
    {
        $user = $this->challengedUser($challengeToken);
        $this->attempt($challengeToken, fn () => $this->twoFactor->verify($user, $code));
        $this->challenges->consume($challengeToken);

        return $this->proceed($user);
    }

    /** El permiso temporal del 2FA: si venció o ya se usó, hay que volver a poner la contraseña. */
    private function challengedUser(string $challengeToken): User
    {
        $userId = $this->challenges->userId($challengeToken)
            ?? throw new InvalidCredentials('La verificación venció: volvé a iniciar sesión.');

        return User::query()->findOrFail($userId);
    }

    /** Cuenta los códigos erróneos del permiso temporal: a los pocos errores se anula y hay que empezar de nuevo. */
    private function attempt(string $challengeToken, \Closure $action): mixed
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            if ($this->challenges->fail($challengeToken) === 0) {
                throw new InvalidCredentials('Demasiados códigos incorrectos: volvé a iniciar sesión.');
            }

            throw $e;
        }
    }

    /**
     * Lo que sigue una vez comprobado todo lo que hay que comprobar: con una sola empresa entra directo; con varias NO emite
     * tokens: devuelve las empresas y un permiso temporal para elegir una (selectOrganization). Sin empresas, error.
     *
     * @return array<string, mixed>
     */
    private function proceed(User $user): array
    {
        $organizations = $this->access->organizationsOf($user->id);

        if ($organizations->isEmpty()) {
            throw OrganizationAccessDenied::noOrganizations();
        }

        $selectionToken = $this->selections->create($user->id);

        if ($organizations->count() === 1) {
            return $this->signIn($user, $organizations->first(), $selectionToken);
        }

        return [
            'user' => $user,
            'requires_organization' => true,
            'selection_token' => $selectionToken,
            'selection_expires_in' => SelectionTokens::TTL_SECONDS,
            'organizations' => $this->summaries($organizations),
        ];
    }

    /**
     * Segundo paso del login (solo con varias empresas): con el permiso temporal, elige la empresa y recibe los tokens.
     *
     * @return array<string, mixed>
     */
    public function selectOrganization(string $selectionToken, int $organizationId): array
    {
        $userId = $this->selections->peek($selectionToken)
            ?? throw new InvalidCredentials('La selección de empresa venció: volvé a iniciar sesión.');

        $organization = $this->access->organizationsOf($userId)->firstWhere('id', $organizationId)
            ?? throw OrganizationAccessDenied::notAMember();

        return $this->signIn(User::query()->findOrFail($userId), $organization, $selectionToken);
    }

    /**
     * Cambia de empresa sin volver a loguearse: con el refresh token de la sesión se emiten tokens de la otra empresa.
     *
     * @return array{tokens: \App\Domain\Auth\DTO\TokenData, organization: array{id: int, name: string}}
     */
    public function switchOrganization(string $refreshToken, int $organizationId): array
    {
        $tokens = $this->tokens->refresh($refreshToken, $organizationId);

        return ['tokens' => $tokens, 'organization' => $this->summaryOf($organizationId)];
    }

    /**
     * Abre la sesión de una persona en una empresa sin pedir la contraseña (ya se probó otra cosa: el enlace de una invitación).
     *
     * @return array<string, mixed>
     */
    public function startSession(User $user, Organization $organization): array
    {
        return $this->signIn($user, $organization, $this->selections->create($user->id));
    }

    public function forgotPassword(ForgotPasswordData $data): void
    {
        $this->users->sendPasswordResetLink($data->email);
    }

    /** @throws PasswordResetNotValid si el enlace no sirve */
    public function checkPasswordReset(string $email, string $token): void
    {
        if (! $this->users->passwordResetIsValid($email, $token)) {
            throw new PasswordResetNotValid;
        }
    }

    public function resetPassword(\App\Domain\Auth\DTO\ResetPasswordData $data): void
    {
        $this->users->resetPassword($data);
    }

    /**
     * Renueva los tokens: conserva la empresa de la sesión. Si la persona ya no pertenece a ella, falla.
     */
    public function refresh(RefreshTokenData $data): \App\Domain\Auth\DTO\TokenData
    {
        return $this->tokens->refresh($data->refreshToken);
    }

    public function logout(LogoutData $data): void
    {
        $this->tokens->revoke($data->accessTokenId);
    }

    /**
     * Empresas con las que puede trabajar la persona (para el selector de cambio de empresa).
     *
     * @return list<array{id: int, name: string}>
     */
    public function organizationsOf(int $userId): array
    {
        return $this->summaries($this->access->organizationsOf($userId));
    }

    /**
     * @return array<string, mixed>
     */
    private function signIn(User $user, Organization $organization, string $selectionToken): array
    {
        return [
            'user' => $user,
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'tokens' => $this->tokens->issueForOrganization($selectionToken, $organization->id),
        ];
    }

    /**
     * @param  iterable<Organization>  $organizations
     * @return list<array{id: int, name: string}>
     */
    private function summaries(iterable $organizations): array
    {
        $list = [];

        foreach ($organizations as $organization) {
            $list[] = ['id' => $organization->id, 'name' => $organization->name];
        }

        return $list;
    }

    /** @return array{id: int, name: string} */
    private function summaryOf(int $organizationId): array
    {
        $organization = Organization::query()->findOrFail($organizationId);

        return ['id' => $organization->id, 'name' => $organization->name];
    }
}
