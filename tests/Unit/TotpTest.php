<?php

namespace Tests\Unit;

use App\Application\Security\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Vectores de prueba del RFC 6238 (secreto "12345678901234567890", HMAC-SHA1): los 6 dígitos son los últimos de los 8 del RFC. */
class TotpTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32 de 12345678901234567890

    #[DataProvider('vectors')]
    public function test_it_matches_the_rfc_6238_vectors(int $time, string $expected): void
    {
        $this->assertSame($expected, Totp::code(self::SECRET, Totp::currentStep($time)));
    }

    public static function vectors(): array
    {
        return [[59, '287082'], [1111111109, '081804'], [1111111111, '050471'], [1234567890, '005924'], [2000000000, '279037']];
    }

    public function test_base32_round_trips_and_rejects_garbage(): void
    {
        $bytes = random_bytes(20);
        $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        $this->assertSame(32, strlen(Totp::generateSecret()));
        $this->expectException(\InvalidArgumentException::class);
        Totp::base32Decode('NO-ES-BASE32!');
    }

    public function test_it_accepts_one_step_of_clock_drift_but_not_more(): void
    {
        $now = 1_700_000_000;
        $step = Totp::currentStep($now);

        $this->assertSame($step, Totp::verify(self::SECRET, Totp::code(self::SECRET, $step), null, $now));
        $this->assertSame($step - 1, Totp::verify(self::SECRET, Totp::code(self::SECRET, $step - 1), null, $now));
        $this->assertSame($step + 1, Totp::verify(self::SECRET, Totp::code(self::SECRET, $step + 1), null, $now));
        $this->assertNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, $step - 2), null, $now));
        $this->assertNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, $step + 2), null, $now));
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $now = 1_700_000_000;
        $step = Totp::currentStep($now);
        $code = Totp::code(self::SECRET, $step);

        $this->assertSame($step, Totp::verify(self::SECRET, $code, $step - 1, $now));
        $this->assertNull(Totp::verify(self::SECRET, $code, $step, $now), 'el intervalo ya usado no sirve');
        $this->assertNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, $step - 1), $step, $now), 'ni uno anterior');
    }

    public function test_it_ignores_spaces_and_rejects_anything_that_is_not_six_digits(): void
    {
        $now = 1_700_000_000;
        $code = Totp::code(self::SECRET, Totp::currentStep($now));

        $this->assertNotNull(Totp::verify(self::SECRET, substr($code, 0, 3) . ' ' . substr($code, 3), null, $now));
        foreach (['', '12345', '1234567', 'abcdef', $code . 'x'] as $bad) {
            $this->assertNull(Totp::verify(self::SECRET, $bad, null, $now));
        }
    }

    public function test_the_uri_is_what_authenticator_apps_read(): void
    {
        $uri = Totp::uri('ABCDEFGH', 'ana@example.com', 'Mi ERP');

        $this->assertSame('otpauth://totp/Mi%20ERP%3Aana%40example.com?secret=ABCDEFGH&issuer=Mi%20ERP&algorithm=SHA1&digits=6&period=30', $uri);
    }
}
