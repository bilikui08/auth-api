<?php

namespace App\Application\Security;

/**
 * Contraseñas de un solo uso basadas en el tiempo (RFC 6238, HMAC-SHA1, 6 dígitos, 30 s): lo que entienden Google Authenticator,
 * Microsoft Authenticator, Authy, 1Password, etc. Implementación propia (sin dependencias) verificada con los vectores del RFC.
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    /** Intervalos de tolerancia hacia cada lado (relojes que no coinciden del todo). */
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secreto nuevo: 160 bits al azar en base32 (lo que se escanea o se escribe en la aplicación). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function currentStep(?int $time = null): int
    {
        return intdiv($time ?? \Illuminate\Support\Carbon::now()->getTimestamp(), self::PERIOD);
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Intervalo al que corresponde el código, o null si no es válido. Solo se aceptan intervalos POSTERIORES a `$afterStep`
     * (el último usado): así un código espiado o repetido no sirve una segunda vez.
     */
    public static function verify(string $secret, string $input, ?int $afterStep = null, ?int $time = null): ?int
    {
        $input = preg_replace('/[\s-]+/', '', $input) ?? '';

        if (! preg_match('/^\d{' . self::DIGITS . '}$/', $input)) {
            return null;
        }

        $matched = null;
        $current = self::currentStep($time);

        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            // Se recorren todos (sin cortar al encontrar) para no filtrar por el tiempo de respuesta cuál intervalo coincidió
            if (hash_equals(self::code($secret, $step), $input) && ($afterStep === null || $step > $afterStep)) {
                $matched = $step;
            }
        }

        return $matched;
    }

    /** Dirección que lee la aplicación al escanear el QR. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer . ':' . $account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($text, '='))) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) {
                throw new \InvalidArgumentException('Secreto inválido.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
