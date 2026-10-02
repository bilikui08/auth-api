<?php

namespace App\Application\Auth;

use App\Domain\Auth\Contracts\AuthRepository;
use App\Domain\Auth\Contracts\TokenRepository;
use App\Domain\Auth\DTO\AuthData;
use App\Domain\Auth\DTO\ForgotPasswordData;
use App\Domain\Auth\DTO\LogoutData;
use App\Domain\Auth\DTO\RefreshTokenData;
use App\Domain\Auth\DTO\RegisterData;
use App\Domain\Auth\DTO\TokenData;
use App\Domain\Auth\Exceptions\InvalidCredentials;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class AuthService
{
    public function __construct(
        private AuthRepository $users,
        private TokenRepository $tokens,
    ) {}

    /**
     * @return array{user: User, tokens: TokenData}
     */
    public function authenticate(AuthData $data): array
    {
        $tokens = $this->tokens->issue($data->identifier, $data->password);
        $user = $this->users->findByIdentifier($data->identifier);

        if ($user === null) {
            throw new InvalidCredentials;
        }

        return ['user' => $user, 'tokens' => $tokens];
    }

    /**
     * @return array{user: User, tokens: TokenData}
     */
    public function register(RegisterData $data): array
    {
        return DB::transaction(function () use ($data): array {
            $user = $this->users->create($data);
            $tokens = $this->tokens->issue($user->email, $data->password);

            return ['user' => $user, 'tokens' => $tokens];
        });
    }

    public function forgotPassword(ForgotPasswordData $data): void
    {
        $this->users->sendPasswordResetLink($data->email);
    }

    public function refresh(RefreshTokenData $data): TokenData
    {
        return $this->tokens->refresh($data->refreshToken);
    }

    public function logout(LogoutData $data): void
    {
        $this->tokens->revoke($data->accessTokenId);
    }
}
