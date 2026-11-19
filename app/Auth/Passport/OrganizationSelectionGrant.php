<?php

namespace App\Auth\Passport;

use App\Application\Organizations\OrganizationAccess;
use DateInterval;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\RequestAccessTokenEvent;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\RequestRefreshTokenEvent;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Grant propio: emite access + refresh token para una persona que ya probó quién es (usuario y contraseña) y
 * eligió una empresa. La prueba es el permiso temporal de un solo uso (SelectionTokens), no la contraseña.
 * Cada login, de una o de varias empresas, pasa por acá: así hay un único camino que emite tokens con empresa.
 */
final class OrganizationSelectionGrant extends AbstractGrant
{
    public const IDENTIFIER = 'organization_selection';

    public function __construct(
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly SelectionTokens $selections,
        private readonly OrganizationAccess $access,
        private readonly TokenContext $context,
    ) {
        $this->setRefreshTokenRepository($refreshTokenRepository);

        $this->refreshTokenTTL = new DateInterval('P1M');
    }

    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL
    ): ResponseTypeInterface {
        $client = $this->validateClient($request);
        $scopes = $this->validateScopes($this->getRequestParameter('scope', $request, $this->defaultScope));

        $selectionToken = $this->getRequestParameter('selection_token', $request)
            ?? throw OAuthServerException::invalidRequest('selection_token');
        $organizationId = (int) ($this->getRequestParameter('organization_id', $request)
            ?? throw OAuthServerException::invalidRequest('organization_id'));

        $userId = $this->selections->peek($selectionToken)
            ?? throw OAuthServerException::invalidGrant('La selección de empresa venció: volvé a iniciar sesión.');

        // Se revalida acá, no solo al listar: la persona pudo perder la empresa en el medio
        if (! $this->access->isMember($userId, $organizationId)) {
            throw OAuthServerException::invalidGrant('No pertenecés a esa empresa o está dada de baja.');
        }

        // Un solo uso: recién ahora (con empresa válida) se consume
        $this->selections->consume($selectionToken);

        $finalizedScopes = $this->scopeRepository->finalizeScopes($scopes, $this->getIdentifier(), $client, (string) $userId);

        $this->context->organizationId = $organizationId;

        $accessToken = $this->issueAccessToken($accessTokenTTL, $client, (string) $userId, $finalizedScopes);
        $this->getEmitter()->emit(new RequestAccessTokenEvent(RequestEvent::ACCESS_TOKEN_ISSUED, $request, $accessToken));
        $responseType->setAccessToken($accessToken);

        $refreshToken = $this->issueRefreshToken($accessToken);

        if ($refreshToken !== null) {
            $this->getEmitter()->emit(new RequestRefreshTokenEvent(RequestEvent::REFRESH_TOKEN_ISSUED, $request, $refreshToken));
            $responseType->setRefreshToken($refreshToken);
        }

        return $responseType;
    }

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }
}
