<?php

namespace Tests\Feature;

use App\Domain\Auth\Contracts\AuthRepository;
use App\Domain\Auth\Contracts\TokenRepository;
use App\Domain\Auth\DTO\TokenData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;
use Mockery;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_authenticate_with_email(): void
    {
        $user = User::factory()->create(['email' => 'john@example.com']);
        $this->mockTokens()->shouldReceive('issue')
            ->once()
            ->with('john@example.com', 'password')
            ->andReturn($this->tokenData());

        $this->postJson('/api/auth', [
            'email' => 'john@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.tokens.access_token', 'access-token')
            ->assertJsonPath('errors', null);
    }

    public function test_user_can_authenticate_with_name(): void
    {
        $user = User::factory()->create(['name' => 'john']);
        $this->mockTokens()->shouldReceive('issue')
            ->once()
            ->with('john', 'password')
            ->andReturn($this->tokenData());

        $this->postJson('/api/auth', [
            'name' => 'john',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_user_can_register_and_receives_tokens(): void
    {
        $this->mockTokens()->shouldReceive('issue')
            ->once()
            ->with('jane@example.com', 'password123')
            ->andReturn($this->tokenData());

        $this->postJson('/api/register', [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'jane@example.com')
            ->assertJsonPath('data.tokens.refresh_token', 'refresh-token');

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
    }

    public function test_forgot_password_has_a_generic_uniform_response(): void
    {
        $repository = Mockery::mock(AuthRepository::class);
        $repository->shouldReceive('sendPasswordResetLink')->once()->with('john@example.com');
        $this->app->instance(AuthRepository::class, $repository);

        $this->postJson('/api/forgot-password', [
            'email' => 'john@example.com',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors', null);
    }

    public function test_refresh_exchanges_a_refresh_token(): void
    {
        $this->mockTokens()->shouldReceive('refresh')
            ->once()
            ->with('old-refresh-token')
            ->andReturn($this->tokenData());

        $this->postJson('/api/refresh', [
            'refresh_token' => 'old-refresh-token',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tokens.access_token', 'access-token');
    }

    public function test_validation_errors_use_the_uniform_response(): void
    {
        $this->postJson('/api/auth', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonStructure(['message', 'errors' => ['name', 'email', 'password']]);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);
        $user->withAccessToken(new AccessToken([
            'oauth_access_token_id' => 'current-token-id',
            'oauth_user_id' => (string) $user->getKey(),
            'oauth_scopes' => [],
        ]));

        $this->mockTokens()->shouldReceive('revoke')
            ->once()
            ->with('current-token-id');

        $this->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Sesión cerrada correctamente.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors', null);
    }

    public function test_logout_requires_a_valid_bearer_token(): void
    {
        $this->postJson('/api/logout')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'No autenticado.')
            ->assertJsonPath('data', null);
    }

    private function mockTokens(): Mockery\MockInterface
    {
        $repository = Mockery::mock(TokenRepository::class);
        $this->app->instance(TokenRepository::class, $repository);

        return $repository;
    }

    private function tokenData(): TokenData
    {
        return new TokenData('Bearer', 3600, 'access-token', 'refresh-token');
    }
}
