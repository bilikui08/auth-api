<?php

namespace Tests\Feature;

use App\Domain\Auth\Contracts\AuthRepository;
use App\Domain\Auth\Contracts\TokenRepository;
use App\Domain\Auth\DTO\TokenData;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;
use Mockery;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    /** Usuario con contraseña "password" (la del factory) y su empresa propia, como todo usuario real. */
    private function userWithOrganization(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $organization = \App\Models\Organization::create(['name' => $user->name]);
        $organization->users()->attach($user->id, ['role_id' => Role::where('name', 'company_admin')->value('id')]);

        return $user;
    }

    public function test_user_can_authenticate_with_email(): void
    {
        $user = $this->userWithOrganization(['email' => 'john@example.com']);
        $this->mockTokens()->shouldReceive('issueForOrganization')
            ->once()
            ->with(Mockery::type('string'), $user->id)
            ->andReturn($this->tokenData());

        $this->postJson('/api/auth/login', [
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
        $user = $this->userWithOrganization(['name' => 'john']);
        $this->mockTokens()->shouldReceive('issueForOrganization')
            ->once()
            ->with(Mockery::type('string'), $user->id)
            ->andReturn($this->tokenData());

        $this->postJson('/api/auth/login', [
            'name' => 'john',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_forgot_password_has_a_generic_uniform_response(): void
    {
        $repository = Mockery::mock(AuthRepository::class);
        $repository->shouldReceive('sendPasswordResetLink')->once()->with('john@example.com');
        $this->app->instance(AuthRepository::class, $repository);

        $this->postJson('/api/auth/forgot-password', [
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

        $this->postJson('/api/auth/refresh', [
            'refresh_token' => 'old-refresh-token',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tokens.access_token', 'access-token');
    }

    public function test_refresh_with_an_invalid_token_answers_401_not_500(): void
    {
        $this->mockTokens()->shouldReceive('refresh')
            ->once()
            ->andThrow(new \App\Domain\Auth\Exceptions\InvalidCredentials('El refresh token es inválido, expiró o ya fue utilizado.'));

        $this->postJson('/api/auth/refresh', ['refresh_token' => 'invalido'])
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'El refresh token es inválido, expiró o ya fue utilizado.');
    }

    public function test_validation_errors_use_the_uniform_response(): void
    {
        $this->postJson('/api/auth/login', [])
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

        $this->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Sesión cerrada correctamente.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors', null);
    }

    public function test_logout_requires_a_valid_bearer_token(): void
    {
        $this->postJson('/api/auth/logout')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'No autenticado.')
            ->assertJsonPath('data', null);
    }

    public function test_authenticated_user_can_fetch_own_profile(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.roles', [])
            ->assertJsonMissingPath('data.password');
    }

    public function test_me_exposes_the_users_roles_as_a_list_of_names(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::whereIn('name', ['admin', 'user'])->pluck('id'));
        Passport::actingAs($user);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.roles', ['admin', 'user']);
    }

    public function test_me_requires_a_valid_bearer_token(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_access_tokens_are_short_lived(): void
    {
        $access = Passport::tokensExpireIn();
        $refresh = Passport::refreshTokensExpireIn();

        $this->assertSame(15, $access->i);
        $this->assertSame(0, $access->d + $access->m + $access->y);
        $this->assertSame(30, $refresh->d);
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
