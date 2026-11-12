<?php

namespace App\Providers;

use App\Domain\Auth\Contracts\AuthRepository;
use App\Domain\Auth\Contracts\TokenRepository;
use App\Infrastructure\Auth\EloquentAuthRepository;
use App\Infrastructure\Auth\PassportTokenRepository;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AuthRepository::class, EloquentAuthRepository::class);
        $this->app->bind(TokenRepository::class, PassportTokenRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::enablePasswordGrant();

        // Access tokens cortos: los microservicios los validan solo con la clave
        // publica (sin consultar la BD), asi que la revocacion depende de que caduquen pronto.
        Passport::tokensExpireIn(new \DateInterval(sprintf('PT%dM', (int) config('auth.access_token_ttl_minutes'))));
        Passport::refreshTokensExpireIn(new \DateInterval(sprintf('P%dD', (int) config('auth.refresh_token_ttl_days'))));

        ResetPassword::createUrlUsing(
            fn (User $user, string $token): string => sprintf(
                '%s/reset-password?token=%s&email=%s',
                rtrim((string) config('app.frontend_url'), '/'),
                urlencode($token),
                urlencode($user->email),
            ),
        );
    }
}
