<?php

namespace Tests\Feature;

use App\Application\Security\Totp;
use App\Auth\Passport\SelectionTokens;
use App\Domain\Auth\Contracts\TokenRepository;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesRealPassport;
use Tests\TestCase;

/**
 * Verificación en dos pasos (TOTP). Activa por defecto: después de la contraseña (y el captcha) se pide el código, o se configura la
 * aplicación si es la primera vez. Sin el segundo factor nunca se emite un token.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;
    use UsesRealPassport;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassport();
    }

    private function company(User $user, string $name = 'Empresa A SA'): Organization
    {
        $organization = Organization::create(['name' => $name]);
        $organization->users()->attach($user->id, ['role_id' => Role::where('name', 'company_admin')->value('id')]);

        return $organization;
    }

    /** Persona con 2FA configurado y una empresa. */
    private function enrolled(string $email = 'ana@example.com'): User
    {
        $user = User::factory()->withTwoFactor(self::SECRET)->create(['email' => $email, 'password' => 'ClaveVieja1']);
        $this->company($user);

        return $user;
    }

    /** Persona recién creada: el 2FA nace activo (valor por defecto de la base) pero sin configurar. */
    private function fresh(string $email = 'nueva@example.com'): User
    {
        $user = User::factory()->withTwoFactorPending()->create(['email' => $email, 'password' => 'ClaveVieja1']);
        $this->company($user);

        return $user;
    }

    private function login(string $email = 'ana@example.com')
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'ClaveVieja1']);
    }

    private function challenge(string $email = 'ana@example.com'): string
    {
        return (string) $this->login($email)->assertOk()->json('data.challenge_token');
    }

    private function code(string $secret = self::SECRET): string
    {
        return Totp::code($secret, Totp::currentStep());
    }

    private function verify(string $challenge, string $code)
    {
        return $this->postJson('/api/auth/login/two-factor/verify', ['challenge_token' => $challenge, 'code' => $code]);
    }

    /** Pasa al siguiente intervalo de 30 s (un código ya usado no vale dos veces). */
    private function nextStep(): void
    {
        $this->travel(31)->seconds();
    }

    private function as(User $user)
    {
        $this->app['auth']->forgetGuards();
        $organization = $user->organizations()->orderBy('organizations.id')->first();

        return $this->withToken(app(TokenRepository::class)->issueForOrganization(app(SelectionTokens::class)->create($user->id), $organization->id)->accessToken);
    }

    // ------------------------------------------------------------------ por defecto y primer ingreso

    public function test_two_factor_is_on_by_default_for_every_new_account(): void
    {
        $user = User::create(['name' => 'Nueva', 'email' => 'nueva@example.com', 'password' => 'ClaveVieja1']);

        $this->assertTrue((bool) DB::table('users')->where('id', $user->id)->value('two_factor_enabled'));
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('two_factor_secret'));
    }

    public function test_after_the_password_no_token_is_issued_only_a_challenge(): void
    {
        $this->enrolled();

        $this->login()->assertOk()
            ->assertJsonPath('data.requires_two_factor', true)->assertJsonPath('data.two_factor', 'verify')
            ->assertJsonMissingPath('data.tokens')->assertJsonMissingPath('data.user')->assertJsonMissingPath('data.selection_token');
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }

    public function test_a_wrong_password_never_reaches_the_second_factor(): void
    {
        $this->enrolled();

        $this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'mala'])->assertStatus(401)->assertJsonMissingPath('data.challenge_token');
    }

    public function test_first_login_configures_the_app_then_confirms_with_the_first_code_and_signs_in(): void
    {
        $user = $this->fresh();
        $challenge = $this->login('nueva@example.com')->assertJsonPath('data.two_factor', 'setup')->json('data.challenge_token');

        $setup = $this->postJson('/api/auth/login/two-factor/setup', ['challenge_token' => $challenge])->assertOk()->json('data');
        $this->assertStringStartsWith('otpauth://totp/', $setup['uri']);
        $this->assertStringContainsString(rawurlencode('nueva@example.com'), $setup['uri']);
        $this->assertStringContainsString('secret=' . $setup['secret'], $setup['uri']);
        $this->assertNotSame($setup['secret'], DB::table('users')->where('id', $user->id)->value('two_factor_secret'), 'el secreto se guarda cifrado');

        // un código incorrecto no activa nada ni emite tokens
        $this->postJson('/api/auth/login/two-factor/confirm', ['challenge_token' => $challenge, 'code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('code', 'errors');
        $this->assertNull($user->fresh()->two_factor_confirmed_at);

        $done = $this->postJson('/api/auth/login/two-factor/confirm', ['challenge_token' => $challenge, 'code' => $this->code($setup['secret'])])->assertOk()
            ->assertJsonPath('data.organization.name', 'Empresa A SA')->assertJsonStructure(['data' => ['tokens' => ['access_token'], 'recovery_codes']]);
        $this->assertCount(10, $done->json('data.recovery_codes'));
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $done->json('data.recovery_codes.0'));
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        // el siguiente ingreso ya pide el código
        $this->login('nueva@example.com')->assertJsonPath('data.two_factor', 'verify');
    }

    public function test_the_setup_is_not_available_once_configured(): void
    {
        $this->enrolled();

        $this->postJson('/api/auth/login/two-factor/setup', ['challenge_token' => $this->challenge()])->assertStatus(401);
    }

    public function test_retrying_the_setup_shows_the_same_secret(): void
    {
        $this->fresh();
        $challenge = $this->login('nueva@example.com')->json('data.challenge_token');

        $first = $this->postJson('/api/auth/login/two-factor/setup', ['challenge_token' => $challenge])->json('data.secret');
        $second = $this->postJson('/api/auth/login/two-factor/setup', ['challenge_token' => $challenge])->json('data.secret');

        $this->assertSame($first, $second, 'recargar la pantalla no puede dejar la aplicación con un secreto distinto');
    }

    // ------------------------------------------------------------------ verificación

    public function test_the_right_code_signs_in(): void
    {
        $this->enrolled();

        $this->verify($this->challenge(), $this->code())->assertOk()->assertJsonStructure(['data' => ['tokens' => ['access_token', 'refresh_token']]])
            ->assertJsonPath('data.organization.name', 'Empresa A SA');
    }

    public function test_a_wrong_code_does_not_sign_in_and_gives_no_hint(): void
    {
        $this->enrolled();

        $this->verify($this->challenge(), '123456')->assertStatus(422)->assertJsonPath('errors.code.0', 'El código no es correcto o venció.')->assertJsonMissingPath('data.tokens');
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }

    public function test_a_code_cannot_be_reused_even_in_a_new_login(): void
    {
        $this->enrolled();
        $code = $this->code();

        $this->verify($this->challenge(), $code)->assertOk();
        $this->verify($this->challenge(), $code)->assertStatus(422); // el mismo código espiado

        $this->nextStep();
        $this->verify($this->challenge(), $this->code())->assertOk();
    }

    public function test_the_challenge_works_once(): void
    {
        $this->enrolled();
        $challenge = $this->challenge();

        $this->verify($challenge, $this->code())->assertOk();
        $this->nextStep();
        $this->verify($challenge, $this->code())->assertStatus(401);
    }

    public function test_the_challenge_expires(): void
    {
        $this->enrolled();
        $challenge = $this->challenge();
        $this->travel(11)->minutes();

        $this->verify($challenge, $this->code())->assertStatus(401)->assertJsonPath('message', 'La verificación venció: volvé a iniciar sesión.');
    }

    public function test_an_invented_challenge_is_refused(): void
    {
        $this->enrolled();

        $this->verify('inventado', $this->code())->assertStatus(401);
    }

    public function test_after_five_wrong_codes_the_challenge_is_cancelled_even_for_the_right_code(): void
    {
        $this->enrolled();
        $challenge = $this->challenge();

        foreach (range(1, 4) as $i) {
            $this->verify($challenge, '000000')->assertStatus(422);
        }
        $this->verify($challenge, '000000')->assertStatus(401)->assertJsonPath('message', 'Demasiados códigos incorrectos: volvé a iniciar sesión.');
        $this->verify($challenge, $this->code())->assertStatus(401);
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }

    public function test_many_wrong_codes_across_logins_lock_the_verification_for_that_person(): void
    {
        $this->enrolled();

        foreach (range(1, 2) as $round) { // 2 ingresos x 5 errores = 10
            $challenge = $this->challenge();
            foreach (range(1, 5) as $i) {
                $this->verify($challenge, '000000');
            }
        }

        $this->verify($this->challenge(), $this->code())->assertStatus(429)->assertJsonPath('message', 'Demasiados intentos con códigos incorrectos. Esperá unos minutos y probá de nuevo.');
        $this->travel(16)->minutes();
        $this->verify($this->challenge(), $this->code())->assertOk();
    }

    public function test_a_code_of_another_person_does_not_work(): void
    {
        $this->enrolled('ana@example.com');
        $beto = User::factory()->withTwoFactor('KRSXG5CTMVRXEZLU')->create(['email' => 'beto@example.com', 'password' => 'ClaveVieja1']);
        $this->company($beto, 'Empresa B SA');

        $this->verify($this->challenge('ana@example.com'), $this->code('KRSXG5CTMVRXEZLU'))->assertStatus(422);
    }

    public function test_with_several_companies_the_verification_leads_to_the_company_selection(): void
    {
        $ana = $this->enrolled();
        $this->company($ana, 'Empresa B SA');

        $this->verify($this->challenge(), $this->code())->assertOk()->assertJsonPath('data.requires_organization', true)
            ->assertJsonCount(2, 'data.organizations')->assertJsonMissingPath('data.tokens');
    }

    // ------------------------------------------------------------------ códigos de recuperación

    public function test_a_recovery_code_works_once(): void
    {
        $user = $this->fresh();
        $setup = $this->postJson('/api/auth/login/two-factor/setup', ['challenge_token' => $this->challenge('nueva@example.com')])->json('data');
        $codes = $this->postJson('/api/auth/login/two-factor/confirm', ['challenge_token' => $this->challenge('nueva@example.com'), 'code' => $this->code($setup['secret'])])->json('data.recovery_codes');

        $this->verify($this->challenge('nueva@example.com'), strtolower($codes[0]))->assertOk(); // minúsculas también
        $this->verify($this->challenge('nueva@example.com'), $codes[0])->assertStatus(422);
        $this->verify($this->challenge('nueva@example.com'), $codes[1])->assertOk();
        $this->assertCount(8, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_recovery_codes_are_stored_hashed_not_in_clear(): void
    {
        $this->fresh();
        $setup = $this->postJson('/api/auth/login/two-factor/setup', ['challenge_token' => $this->challenge('nueva@example.com')])->json('data');
        $codes = $this->postJson('/api/auth/login/two-factor/confirm', ['challenge_token' => $this->challenge('nueva@example.com'), 'code' => $this->code($setup['secret'])])->json('data.recovery_codes');

        $raw = (string) DB::table('users')->where('email', 'nueva@example.com')->value('two_factor_recovery_codes');
        $stored = json_encode(User::where('email', 'nueva@example.com')->first()->two_factor_recovery_codes);
        $this->assertStringNotContainsString(str_replace('-', '', $codes[0]), $stored);
        $this->assertStringNotContainsString($codes[0], $raw);
    }

    // ------------------------------------------------------------------ la cuenta (Configuración)

    public function test_status_shows_it_active_and_secrets_never_leave_the_server(): void
    {
        $this->enrolled();
        $response = $this->as(User::where('email', 'ana@example.com')->first())->getJson('/api/auth/two-factor')->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.confirmed', true);

        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertStringNotContainsString(self::SECRET, $this->as(User::where('email', 'ana@example.com')->first())->getJson('/api/auth/me')->getContent());
    }

    public function test_turning_it_off_needs_the_password_and_a_valid_code(): void
    {
        $ana = $this->enrolled();
        $session = fn () => $this->as($ana->fresh());

        $session()->postJson('/api/auth/two-factor/disable', ['password' => 'mala', 'code' => $this->code()])->assertStatus(422)->assertJsonValidationErrors('password', 'errors');
        $session()->postJson('/api/auth/two-factor/disable', ['password' => 'ClaveVieja1', 'code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('code', 'errors');
        $session()->postJson('/api/auth/two-factor/disable', ['code' => $this->code()])->assertStatus(422);
        $this->assertTrue((bool) $ana->fresh()->two_factor_enabled);

        $session()->postJson('/api/auth/two-factor/disable', ['password' => 'ClaveVieja1', 'code' => $this->code()])->assertOk()->assertJsonPath('data.enabled', false);

        $fresh = $ana->fresh();
        $this->assertFalse($fresh->two_factor_enabled);
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->login()->assertOk()->assertJsonStructure(['data' => ['tokens' => ['access_token']]]); // sin 2FA ingresa directo
    }

    public function test_it_can_be_turned_on_again_with_a_new_secret_and_new_recovery_codes(): void
    {
        $ana = $this->enrolled();
        $this->as($ana)->postJson('/api/auth/two-factor/disable', ['password' => 'ClaveVieja1', 'code' => $this->code()])->assertOk();

        $setup = $this->as($ana->fresh())->postJson('/api/auth/two-factor/enable')->assertOk()->json('data');
        $this->assertNotSame(self::SECRET, $setup['secret']);
        $this->assertFalse($ana->fresh()->two_factor_enabled, 'hasta confirmar un código no se activa');

        $this->as($ana->fresh())->postJson('/api/auth/two-factor/enable/confirm', ['code' => '000000'])->assertStatus(422);
        $this->as($ana->fresh())->postJson('/api/auth/two-factor/enable/confirm', ['code' => $this->code($setup['secret'])])->assertOk()
            ->assertJsonCount(10, 'data.recovery_codes')->assertJsonPath('data.enabled', true);
        $this->login()->assertJsonPath('data.two_factor', 'verify');
    }

    public function test_it_cannot_be_started_again_while_active(): void
    {
        $ana = $this->enrolled();

        $this->as($ana)->postJson('/api/auth/two-factor/enable')->assertStatus(422);
        $this->assertSame(self::SECRET, $ana->fresh()->two_factor_secret, 'el secreto activo no se pisa');
    }

    public function test_regenerating_recovery_codes_needs_a_valid_code_and_voids_the_old_ones(): void
    {
        $user = $this->fresh();
        $setup = $this->postJson('/api/auth/login/two-factor/setup', ['challenge_token' => $this->challenge('nueva@example.com')])->json('data');
        $old = $this->postJson('/api/auth/login/two-factor/confirm', ['challenge_token' => $this->challenge('nueva@example.com'), 'code' => $this->code($setup['secret'])])->json('data.recovery_codes');
        $this->nextStep();

        $this->as($user->fresh())->postJson('/api/auth/two-factor/recovery-codes', ['code' => '000000'])->assertStatus(422);
        $new = $this->as($user->fresh())->postJson('/api/auth/two-factor/recovery-codes', ['code' => $this->code($setup['secret'])])->assertOk()->json('data.recovery_codes');

        $this->assertCount(10, $new);
        $this->assertEmpty(array_intersect($old, $new));
        $this->verify($this->challenge('nueva@example.com'), $old[0])->assertStatus(422);
        $this->verify($this->challenge('nueva@example.com'), $new[0])->assertOk();
    }

    public function test_the_account_routes_need_a_session(): void
    {
        foreach (['getJson' => '/api/auth/two-factor', 'postJson' => '/api/auth/two-factor/disable'] as $method => $url) {
            $this->{$method}($url)->assertStatus(401);
        }
    }

    // ------------------------------------------------------------------ recuperación de una cuenta

    public function test_the_console_command_resets_a_lost_device(): void
    {
        $ana = $this->enrolled();

        $this->artisan('auth:2fa-reset', ['email' => 'ana@example.com'])->assertSuccessful();

        $fresh = $ana->fresh();
        $this->assertTrue($fresh->two_factor_enabled);
        $this->assertNull($fresh->two_factor_secret);
        $this->login()->assertJsonPath('data.two_factor', 'setup'); // vuelve a configurarlo

        $this->artisan('auth:2fa-reset', ['email' => 'ana@example.com', '--disable' => true])->assertSuccessful();
        $this->assertFalse($ana->fresh()->two_factor_enabled);
        $this->artisan('auth:2fa-reset', ['email' => 'nadie@example.com'])->assertFailed();
    }

    public function test_it_is_enforced_with_the_captcha_too(): void
    {
        config(['services.recaptcha.secret' => 'secreto']);
        \Illuminate\Support\Facades\Http::fake(['www.google.com/*' => \Illuminate\Support\Facades\Http::response(['success' => true])]);
        $this->enrolled();

        $this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'ClaveVieja1', 'captcha' => 'ok'])->assertOk()->assertJsonPath('data.requires_two_factor', true);
        $this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'ClaveVieja1'])->assertStatus(422);
    }
}
