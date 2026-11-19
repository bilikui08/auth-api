<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\UsesRealPassport;
use Tests\TestCase;

/**
 * Restablecer la contraseña: el enlace llega por email con un token secreto (hasheado en la base, vence, se usa una vez).
 * Nunca se revela si un correo está registrado.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;
    use UsesRealPassport;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassport();
        Mail::fake();
        config(['app.frontend_url' => 'https://app.ejemplo.com']);
        $this->ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'ClaveVieja1']);
    }

    /** Pide el enlace y devuelve el token que viaja en el email. */
    private function requestToken(string $email = 'ana@example.com'): string
    {
        $this->postJson('/api/auth/forgot-password', ['email' => $email])->assertOk();
        $token = null;
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$token, $email): bool {
            parse_str((string) parse_url($mail->resetUrl, PHP_URL_QUERY), $query);
            $token = $query['token'];
            $this->assertStringStartsWith('https://app.ejemplo.com/reset-password?', $mail->resetUrl);
            $this->assertSame($email, $query['email']);

            return $mail->hasTo($email);
        });

        return $token;
    }

    private function reset(string $token, string $password = 'ClaveNueva9', ?string $confirmation = null, string $email = 'ana@example.com')
    {
        return $this->postJson('/api/auth/reset-password', [
            'email' => $email, 'token' => $token, 'password' => $password, 'password_confirmation' => $confirmation ?? $password,
        ]);
    }

    public function test_a_registered_email_gets_the_link_and_the_token_is_stored_hashed(): void
    {
        $token = $this->requestToken();

        $this->assertNotSame($token, DB::table('password_reset_tokens')->where('email', 'ana@example.com')->value('token'));
        $this->assertFalse(DB::table('password_reset_tokens')->where('token', $token)->exists());
    }

    public function test_an_unknown_email_gets_the_same_answer_and_no_email(): void
    {
        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'ana@example.com'])->assertOk()->json();
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nadie@example.com'])->assertOk()->json();

        $this->assertSame($known, $unknown);
        Mail::assertSent(PasswordResetMail::class, 1);
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'nadie@example.com')->count());
    }

    public function test_a_failing_mail_server_does_not_break_or_reveal_anything(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp caído'));

        $this->postJson('/api/auth/forgot-password', ['email' => 'ana@example.com'])->assertOk()
            ->assertJsonPath('message', 'Si el correo está registrado, recibirá un enlace para restablecer la contraseña.');
    }

    public function test_the_link_can_be_checked_without_consuming_it(): void
    {
        $token = $this->requestToken();

        $this->postJson('/api/auth/reset-password/check', ['email' => 'ana@example.com', 'token' => $token])->assertOk();
        $this->postJson('/api/auth/reset-password/check', ['email' => 'ana@example.com', 'token' => $token])->assertOk();
        $this->reset($token)->assertOk();
    }

    public function test_a_wrong_token_or_another_email_is_not_valid_with_one_message(): void
    {
        $token = $this->requestToken();
        User::factory()->create(['email' => 'beto@example.com']);

        $this->postJson('/api/auth/reset-password/check', ['email' => 'ana@example.com', 'token' => 'otro'])->assertStatus(422)
            ->assertJsonPath('message', 'El enlace no es válido o venció. Pedí uno nuevo.');
        $this->postJson('/api/auth/reset-password/check', ['email' => 'beto@example.com', 'token' => $token])->assertStatus(422);
        $this->postJson('/api/auth/reset-password/check', ['email' => 'nadie@example.com', 'token' => $token])->assertStatus(422)
            ->assertJsonPath('message', 'El enlace no es válido o venció. Pedí uno nuevo.');
        $this->reset('otro')->assertStatus(422)->assertJsonPath('message', 'El enlace no es válido o venció. Pedí uno nuevo.');
        $this->assertTrue(Hash::check('ClaveVieja1', $this->ana->fresh()->password));
    }

    public function test_the_password_changes_and_the_link_works_only_once(): void
    {
        $token = $this->requestToken();

        $this->reset($token)->assertOk()->assertJsonPath('data', null);
        $this->assertTrue(Hash::check('ClaveNueva9', $this->ana->fresh()->password));

        $this->reset($token, 'OtraClave77')->assertStatus(422);
        $this->postJson('/api/auth/reset-password/check', ['email' => 'ana@example.com', 'token' => $token])->assertStatus(422);
        $this->assertTrue(Hash::check('ClaveNueva9', $this->ana->fresh()->password));
    }

    public function test_the_link_expires(): void
    {
        $token = $this->requestToken();
        $this->travel(61)->minutes();

        $this->reset($token)->assertStatus(422);
        $this->assertTrue(Hash::check('ClaveVieja1', $this->ana->fresh()->password));
    }

    public function test_the_new_password_is_validated(): void
    {
        $token = $this->requestToken();

        $this->reset($token, 'corta')->assertStatus(422)->assertJsonValidationErrors('password', 'errors');
        $this->reset($token, 'ClaveNueva9', 'ClaveDistinta9')->assertStatus(422);
        $this->reset($token, 'ClaveNueva9')->assertOk(); // el intento fallido no gastó el enlace
    }

    public function test_changing_the_password_closes_every_open_session(): void
    {
        DB::table('oauth_access_tokens')->insert(['id' => 'tok-1', 'user_id' => $this->ana->id, 'client_id' => 1, 'revoked' => false, 'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addHour()]);
        DB::table('oauth_refresh_tokens')->insert(['id' => 'ref-1', 'access_token_id' => 'tok-1', 'revoked' => false, 'expires_at' => now()->addDay()]);
        $other = User::factory()->create();
        DB::table('oauth_access_tokens')->insert(['id' => 'tok-2', 'user_id' => $other->id, 'client_id' => 1, 'revoked' => false, 'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addHour()]);

        $this->reset($this->requestToken())->assertOk();

        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('id', 'tok-1')->value('revoked'));
        $this->assertTrue((bool) DB::table('oauth_refresh_tokens')->where('id', 'ref-1')->value('revoked'));
        $this->assertFalse((bool) DB::table('oauth_access_tokens')->where('id', 'tok-2')->value('revoked'), 'las sesiones de otras personas no se tocan');
    }

    public function test_the_new_password_lets_the_person_log_in_and_the_old_one_does_not(): void
    {
        $this->reset($this->requestToken())->assertOk();

        $this->postJson('/api/auth/login', ['name' => 'Ana', 'password' => 'ClaveVieja1'])->assertStatus(401);
        $this->assertTrue(Hash::check('ClaveNueva9', $this->ana->fresh()->password));
    }

    public function test_asking_again_right_away_is_throttled_but_looks_the_same(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'ana@example.com'])->assertOk();
        $this->postJson('/api/auth/forgot-password', ['email' => 'ana@example.com'])->assertOk();

        Mail::assertSent(PasswordResetMail::class, 1);
    }

    public function test_the_mail_comes_from_the_editable_template_and_the_link_is_the_systems(): void
    {
        $this->requestToken();

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail): bool {
            $this->assertStringContainsString('Restablecé tu contraseña', $mail->rendered()->subject);
            $this->assertStringContainsString('60 minutos', $mail->rendered()->bodyMarkdown);

            return true;
        });
    }
}
