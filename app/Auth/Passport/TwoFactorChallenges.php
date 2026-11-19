<?php

namespace App\Auth\Passport;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Permiso temporal entre "la contraseña es correcta" y "el segundo factor también". No es un access token: solo sirve para los pasos
 * de verificación (o de configuración) del 2FA, vence en minutos, se usa una única vez y se anula tras unos pocos códigos erróneos.
 * Se guarda hasheado: quien lea el caché no puede reutilizarlo.
 */
final class TwoFactorChallenges
{
    public const TTL_SECONDS = 600;
    public const MAX_ATTEMPTS = 5;

    public function create(int $userId): string
    {
        $token = Str::random(64);

        Cache::put($this->key($token), ['user' => $userId, 'attempts' => 0], self::TTL_SECONDS);

        return $token;
    }

    /** Usuario al que pertenece el permiso, sin consumirlo. */
    public function userId(string $token): ?int
    {
        $data = Cache::get($this->key($token));

        return is_array($data) ? (int) $data['user'] : null;
    }

    /** Registra un código erróneo. Devuelve los intentos que quedan; con 0 el permiso queda anulado. */
    public function fail(string $token): int
    {
        $data = Cache::get($this->key($token));

        if (! is_array($data)) {
            return 0;
        }

        $data['attempts']++;

        if ($data['attempts'] >= self::MAX_ATTEMPTS) {
            $this->consume($token);

            return 0;
        }

        Cache::put($this->key($token), $data, self::TTL_SECONDS);

        return self::MAX_ATTEMPTS - $data['attempts'];
    }

    public function consume(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'login-2fa:' . hash('sha256', $token);
    }
}
