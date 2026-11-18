<?php

namespace Tests\Feature;

use App\Auth\Passport\ClaimsAccessToken;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use Mockery;
use Tests\TestCase;

/**
 * El access token lleva el nombre del usuario (claim `name`) para que billing pueda auditar sin consultar
 * a auth-api. Se decodifica un token REAL emitido por la clase, con una clave RSA de prueba.
 */
class AccessTokenClaimsTest extends TestCase
{
    use RefreshDatabase;

    private string $publicPem;
    private string $privatePem;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privatePem);
        $this->privatePem = $privatePem;
        $this->publicPem = openssl_pkey_get_details($key)['key'];
    }

    private function issue(?string $userId): UnencryptedToken
    {
        $client = Mockery::mock(ClientEntityInterface::class);
        $client->shouldReceive('getIdentifier')->andReturn('client-1');

        $entity = new ClaimsAccessToken($userId, [], $client);
        $entity->setIdentifier('token-id-123');
        $entity->setExpiryDateTime(new DateTimeImmutable('+15 minutes'));
        $entity->setPrivateKey(new CryptKey($this->privatePem, null, false));

        $token = (new Parser(new JoseEncoder()))->parse($entity->toString());
        $this->assertInstanceOf(UnencryptedToken::class, $token);

        return $token;
    }

    public function test_the_token_carries_the_user_name_and_keeps_every_standard_claim(): void
    {
        $user = User::factory()->create(['name' => 'Gustavo Luis']);

        $token = $this->issue((string) $user->id);
        $claims = $token->claims();

        $this->assertSame('Gustavo Luis', $claims->get('name'));
        $this->assertSame((string) $user->id, $claims->get('sub'));
        $this->assertSame('token-id-123', $claims->get('jti'));
        $this->assertSame(['client-1'], $claims->get('aud'));
        $this->assertSame([], $claims->get('scopes'));
        $this->assertGreaterThan(time(), $claims->get('exp')->getTimestamp());
        $this->assertNotNull($claims->get('iat'));
        $this->assertNotNull($claims->get('nbf'));
    }

    public function test_the_token_is_still_signed_and_verifiable_with_the_public_key(): void
    {
        $user = User::factory()->create();

        $token = $this->issue((string) $user->id);

        $valid = (new Validator())->validate($token, new SignedWith(new Sha256(), InMemory::plainText($this->publicPem)));
        $this->assertTrue($valid, 'billing y los demás servicios validan solo con la clave pública');
    }

    public function test_a_token_that_is_not_from_a_user_has_no_name(): void
    {
        $token = $this->issue(null);

        $this->assertFalse($token->claims()->has('name'));
        $this->assertSame('client-1', $token->claims()->get('sub'), 'client credentials: el sujeto es el cliente');
    }

    public function test_a_user_that_no_longer_exists_does_not_break_the_issuance(): void
    {
        $token = $this->issue('999999');

        $this->assertFalse($token->claims()->has('name'));
        $this->assertSame('999999', $token->claims()->get('sub'));
    }

    public function test_passport_is_configured_to_use_the_claims_entity(): void
    {
        $this->assertSame(ClaimsAccessToken::class, \Laravel\Passport\Passport::$accessTokenEntity);
    }

    public function test_the_name_reflects_the_current_value_each_time_a_token_is_issued(): void
    {
        $user = User::factory()->create(['name' => 'Nombre Viejo']);
        $this->assertSame('Nombre Viejo', $this->issue((string) $user->id)->claims()->get('name'));

        $user->update(['name' => 'Nombre Nuevo']);

        $this->assertSame('Nombre Nuevo', $this->issue((string) $user->id)->claims()->get('name'), 'se refresca en el siguiente refresh');
    }
}
