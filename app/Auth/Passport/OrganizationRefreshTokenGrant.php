<?php

namespace App\Auth\Passport;

use App\Application\Organizations\OrganizationAccess;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Refresh que conserva la empresa del token anterior (o cambia a la pedida) y la revalida: si la persona ya no
 * pertenece a la empresa, el refresh falla en lugar de seguir dando acceso a datos que ya no le corresponden.
 */
final class OrganizationRefreshTokenGrant extends RefreshTokenGrant
{
    public function __construct(
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly TokenOrganizations $tokenOrganizations,
        private readonly OrganizationAccess $access,
        private readonly TokenContext $context,
    ) {
        parent::__construct($refreshTokenRepository);
    }

    /** @return array<string, mixed> */
    protected function validateOldRefreshToken(ServerRequestInterface $request, string $clientId): array
    {
        $old = parent::validateOldRefreshToken($request, $clientId);

        $userId = (int) $old['user_id'];
        $organizationId = $this->context->requestedOrganizationId
            ?? $this->tokenOrganizations->organizationOf((string) $old['access_token_id'])
            ?? $this->onlyOrganizationOf($userId); // sesiones anteriores a las empresas: no tienen registro

        if ($organizationId === null || ! $this->access->isMember($userId, $organizationId)) {
            throw OAuthServerException::invalidRefreshToken('Ya no pertenecés a la empresa de esta sesión: iniciá sesión de nuevo.');
        }

        $this->context->organizationId = $organizationId;

        return $old;
    }

    /** La empresa de la persona si tiene exactamente una (único caso en que no hay ambigüedad). */
    private function onlyOrganizationOf(int $userId): ?int
    {
        $organizations = $this->access->organizationsOf($userId);

        return $organizations->count() === 1 ? (int) $organizations->first()->id : null;
    }
}
