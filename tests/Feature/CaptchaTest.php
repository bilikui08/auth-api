<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\UsesRealPassport;
use Tests\TestCase;

/** reCAPTCHA v2 en el login y en "olvidé mi contraseña": se verifica en el servidor, falla cerrado y no sirve el de otro sitio. */
class CaptchaTest extends TestCase
{
    use RefreshDatabase;
    use UsesRealPassport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassport();
        Mail::fake();
        config(['services.recaptcha.secret' => 'secreto-de-prueba', 'services.recaptcha.hostnames' => ['facturin.gustavoluis.me']]);

        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => 'ClaveVieja1']);
        Organization::create(['name' => 'Empresa A SA'])->users()->attach($user->id, ['role_id' => Role::where('name', 'company_admin')->value('id')]);
    }

    private function google(array $answer, int $status = 200): void
    {
        Http::fake(['www.google.com/recaptcha/api/siteverify' => Http::response($answer, $status)]);
    }

    private function login(array $extra = [])
    {
        return $this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'ClaveVieja1'] + $extra);
    }

    public function test_without_a_secret_the_captcha_is_off(): void
    {
        config(['services.recaptcha.secret' => null]);

        $this->login()->assertOk();
        Http::assertNothingSent();
    }

    public function test_login_without_the_captcha_is_rejected_and_never_checks_the_password(): void
    {
        $this->login()->assertStatus(422)->assertJsonPath('errors.captcha.0', 'Completá el captcha para continuar.');
    }

    public function test_a_valid_captcha_lets_the_login_through_and_sends_the_secret_and_ip_to_google(): void
    {
        $this->google(['success' => true, 'hostname' => 'facturin.gustavoluis.me']);

        $this->login(['captcha' => 'respuesta-del-widget'])->assertOk();

        Http::assertSent(fn ($request) => $request['secret'] === 'secreto-de-prueba' && $request['response'] === 'respuesta-del-widget' && $request['remoteip'] !== null);
    }

    public function test_an_invalid_or_expired_captcha_is_rejected(): void
    {
        $this->google(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);

        $this->login(['captcha' => 'vencida'])->assertStatus(422)->assertJsonValidationErrors('captcha', 'errors');
    }

    public function test_a_captcha_from_another_site_with_the_same_key_is_rejected(): void
    {
        $this->google(['success' => true, 'hostname' => 'gustavoluis.me']);

        $this->login(['captcha' => 'del-formulario-de-contacto'])->assertStatus(422);
    }

    public function test_if_google_is_down_the_login_is_rejected_not_skipped(): void
    {
        $this->google([], 503);
        $this->login(['captcha' => 'x'])->assertStatus(422);

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('sin conexión'));
        $this->login(['captcha' => 'x'])->assertStatus(422);
    }

    public function test_forgot_password_also_needs_the_captcha_and_no_mail_goes_out_without_it(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'ana@example.com'])->assertStatus(422);
        Mail::assertNothingSent();

        $this->google(['success' => true, 'hostname' => 'facturin.gustavoluis.me']);
        $this->postJson('/api/auth/forgot-password', ['email' => 'ana@example.com', 'captcha' => 'ok'])->assertOk();
        Mail::assertSent(PasswordResetMail::class);
    }

    public function test_login_is_rate_limited_per_ip(): void
    {
        config(['services.recaptcha.secret' => null]);

        foreach (range(1, 10) as $i) {
            $this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'mala'])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'mala'])->assertStatus(429);
    }

    public function test_each_route_has_its_own_rate_limit_counter(): void
    {
        config(['services.recaptcha.secret' => null]);

        // cargar la pantalla de login (branding, 60 por minuto) no gasta los intentos de contraseña
        foreach (range(1, 12) as $i) {
            $this->getJson('/api/auth/branding')->assertOk();
        }

        $this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'mala'])->assertStatus(401);
    }

    public function test_the_public_oauth_routes_do_not_exist(): void
    {
        $this->postJson('/oauth/token', ['grant_type' => 'password'])->assertStatus(404);
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('passport.token'));
    }
}
