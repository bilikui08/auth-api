<?php

namespace App\Infrastructure\Auth;

use App\Domain\Auth\Contracts\TokenRepository;
use App\Domain\Auth\DTO\TokenData;
use App\Domain\Auth\Exceptions\InvalidCredentials;
use App\Domain\Auth\Exceptions\OAuthClientNotConfigured;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use JsonException;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use RuntimeException;

final readonly class PassportTokenRepository implements TokenRepository
{
    public function __construct(
        private AuthorizationServer $server,
        private AccessTokenRepository $accessTokens,
    ) {}

    public function issue(string $identifier, string $password): TokenData
    {
        return $this->requestToken([
            'grant_type' => 'password',
            'username' => $identifier,
            'password' => $password,
            'scope' => '',
        ]);
    }

    public function refresh(string $refreshToken): TokenData
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => '',
        ]);
    }

    public function revoke(string $accessTokenId): void
    {
        Passport::refreshToken()->newQuery()
            ->where('access_token_id', $accessTokenId)
            ->update(['revoked' => true]);

        $this->accessTokens->revokeAccessToken($accessTokenId);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function requestToken(array $payload): TokenData
    {
        $clientId = (string) config('services.passport.password_client_id');
        $clientSecret = (string) config('services.passport.password_client_secret');

        if ($clientId === '' || $clientSecret === '') {
            throw new OAuthClientNotConfigured;
        }

        $request = (new ServerRequest('POST', '/oauth/token'))
            ->withParsedBody([
                ...$payload,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        try {
            $response = $this->server->respondToAccessTokenRequest($request, new Response);
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (OAuthServerException $exception) {
            if (in_array($exception->getErrorType(), ['invalid_grant', 'access_denied'], true)) {
                throw new InvalidCredentials(
                    $payload['grant_type'] === 'refresh_token'
                        ? 'El refresh token es inválido, expiró o ya fue utilizado.'
                        : 'Las credenciales ingresadas no son válidas.',
                );
            }

            throw new RuntimeException('Passport no pudo emitir el token.', previous: $exception);
        } catch (JsonException $exception) {
            throw new RuntimeException('Passport devolvió una respuesta inválida.', previous: $exception);
        }

        return new TokenData(
            tokenType: (string) $body['token_type'],
            expiresIn: (int) $body['expires_in'],
            accessToken: (string) $body['access_token'],
            refreshToken: (string) $body['refresh_token'],
        );
    }
}
