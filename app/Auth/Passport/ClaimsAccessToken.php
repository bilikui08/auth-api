<?php

namespace App\Auth\Passport;

use App\Application\Organizations\OrganizationAccess;
use App\Models\User;
use DateTimeImmutable;
use Lcobucci\JWT\Token;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * Access token de Passport que, además de los claims estándar (aud, jti, iat, nbf, exp, sub, scopes), lleva
 * datos del usuario que los servicios (billing, etc.) necesitan para auditar sin consultar a auth-api.
 *
 * Agrega `name` y, si se emite para una empresa (ver TokenContext), `org` (id de la empresa) y `permissions` (los
 * permisos de la persona en esa empresa). Billing y los demás servicios se guían por `org` para saber de quién son
 * los datos y por `permissions` para saber qué puede hacer. Los claims se calculan al emitir el token (login y refresh), así que un cambio tarda
 * hasta el siguiente refresh (el access token dura minutos) en reflejarse.
 *
 * ATENCIÓN: el JWT va firmado pero NO cifrado: cualquiera que tenga el token puede leer sus claims. No agregar
 * datos sensibles (email, documentos, etc.).
 *
 * Es una copia de Laravel\Passport\Bridge\AccessToken porque league/oauth2-server arma el JWT en un método
 * privado (convertToJWT) que no se puede extender: acá se redefine agregando los claims propios.
 * Revisar esta clase al actualizar Passport o league/oauth2-server (hay un test que decodifica un token real).
 */
final class ClaimsAccessToken implements AccessTokenEntityInterface
{
    use AccessTokenTrait, EntityTrait, TokenEntityTrait;

    /**
     * @param  non-empty-string|null  $userIdentifier
     * @param  \League\OAuth2\Server\Entities\ScopeEntityInterface[]  $scopes
     */
    public function __construct(?string $userIdentifier, array $scopes, ClientEntityInterface $client)
    {
        if (! is_null($userIdentifier)) {
            $this->setUserIdentifier($userIdentifier);
        }

        foreach ($scopes as $scope) {
            $this->addScope($scope);
        }

        $this->setClient($client);
    }

    /** Igual que AccessTokenTrait::convertToJWT(), agregando los claims propios. */
    private function convertToJWT(): Token
    {
        $this->initJwtConfiguration();

        $builder = $this->jwtConfiguration->builder()
            ->permittedFor($this->getClient()->getIdentifier())
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(new DateTimeImmutable())
            ->canOnlyBeUsedAfter(new DateTimeImmutable())
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getSubjectIdentifier())
            ->withClaim('scopes', $this->getScopes());

        foreach ($this->userClaims() as $name => $value) {
            $builder = $builder->withClaim($name, $value);
        }

        return $builder->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey());
    }

    /**
     * Claims del usuario dueño del token. Vacío si el token no es de un usuario (client credentials)
     * o si el usuario ya no existe.
     *
     * @return array<string, mixed>
     */
    private function userClaims(): array
    {
        $userId = $this->getUserIdentifier();

        if ($userId === null) {
            return [];
        }

        $name = User::query()->whereKey($userId)->value('name');
        $claims = $name === null || $name === '' ? [] : ['name' => (string) $name];

        $organizationId = app(TokenContext::class)->organizationId;

        if ($organizationId !== null) {
            $claims['org'] = $organizationId;
            $claims['permissions'] = app(OrganizationAccess::class)->permissionsOf((int) $userId, $organizationId);
        }

        return $claims;
    }
}
