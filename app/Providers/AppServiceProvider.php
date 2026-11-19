<?php

namespace App\Providers;

use App\Domain\Auth\Contracts\AuthRepository;
use App\Domain\Auth\Contracts\TokenRepository;
use App\Infrastructure\Auth\EloquentAuthRepository;
use App\Infrastructure\Auth\PassportTokenRepository;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use App\Auth\Passport\ClaimsAccessToken;
use App\Application\Organizations\OrganizationAccess;
use App\Auth\Passport\OrganizationRefreshTokenGrant;
use App\Auth\Passport\OrganizationSelectionGrant;
use App\Auth\Passport\SelectionTokens;
use App\Auth\Passport\TokenContext;
use App\Auth\Passport\TokenOrganizations;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\RequestAccessTokenEvent;
use League\OAuth2\Server\RequestEvent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AuthRepository::class, EloquentAuthRepository::class);
        $this->app->bind(TokenRepository::class, PassportTokenRepository::class);
        $this->app->singleton(TokenContext::class);

        // Todos los tokens salen con empresa: login (grant propio), refresh (conserva o cambia la empresa)
        $this->app->extend(AuthorizationServer::class, function (AuthorizationServer $server, $app): AuthorizationServer {
            $refreshTokens = $app->make(RefreshTokenRepository::class);
            $context = $app->make(TokenContext::class);
            $tokenOrganizations = $app->make(TokenOrganizations::class);
            $access = $app->make(OrganizationAccess::class);

            $selection = new OrganizationSelectionGrant($refreshTokens, $app->make(SelectionTokens::class), $access, $context);
            $selection->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
            $server->enableGrantType($selection, Passport::tokensExpireIn());

            // Reemplaza al refresh estándar (mismo identificador)
            $refresh = new OrganizationRefreshTokenGrant($refreshTokens, $tokenOrganizations, $access, $context);
            $refresh->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
            $server->enableGrantType($refresh, Passport::tokensExpireIn());

            // Cada access token emitido queda asociado a su empresa: el refresh parte de ese registro
            $server->getEmitter()->subscribeTo(
                RequestEvent::ACCESS_TOKEN_ISSUED,
                function (RequestAccessTokenEvent $event) use ($context, $tokenOrganizations): void {
                    if ($context->organizationId !== null) {
                        $tokenOrganizations->remember($event->getAccessToken()->getIdentifier(), $context->organizationId);
                    }
                },
            );

            return $server;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Los access tokens llevan el nombre del usuario (ver ClaimsAccessToken): los servicios lo usan para auditar
        Passport::useAccessTokenEntity(ClaimsAccessToken::class);

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
